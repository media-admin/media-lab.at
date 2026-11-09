/* Veredelung (media-lab-ml-decoration): Auswahl + Preisrechner.
 *
 * Die Daten kommen als JSON aus dem Server (Preise sind dort bereits mit dem Faktor verrechnet, und nur
 * vorhanden, wenn "Preise anzeigen" aktiv ist). Die Formeln entsprechen ML_Deco_Calc (PHP):
 *
 *   Stückpreis = Preis + weitere Farben (next * (Farben - 1))
 *                Makito (Staffel hat "cm"):  Preis + cm * Fläche
 *                MidOcean "area":            Preis * Fläche
 *                mindestens minUnit, dazu Handling je Stück
 *   Summe      = Einrichtung + Laufkosten; Laufkosten = Menge * Stückpreis, wobei jede Farbe mindestens den
 *                Mindestauftragswert kostet (erste Farbe zum Preis, weitere zum Preis "weitere Farbe")
 */
( function ( root ) {
	'use strict';

	function hasKey( obj, key ) {
		return Object.prototype.hasOwnProperty.call( obj, key );
	}

	// ── Rechenkern (rein, auch ohne DOM testbar) ────────────────────────────────

	function unitPrice( tech, scale, colours, area ) {
		var price = Number( scale.price ) || 0;
		var unit = price;

		if ( hasKey( scale, 'cm' ) ) {
			unit = price + ( Number( scale.cm ) || 0 ) * area;
		} else if ( tech.pricing === 'area' ) {
			unit = price * area;
		}

		if ( colours > 1 && scale.next ) {
			unit += Number( scale.next ) * ( colours - 1 );
		}

		var min = Number( tech.minUnit ) || 0;
		if ( min > 0 ) {
			unit = Math.max( unit, min );
		}
		return unit;
	}

	/** Bei Flächenstufen nur die Stufen, die auf der Position erreichbar sind. */
	function reachableRanges( tech, maxArea ) {
		var ranges = tech.ranges || [];
		if ( ( tech.pricing !== 'area_range' && tech.pricing !== 'colour_area_range' ) || ! maxArea ) {
			return ranges;
		}
		var out = ranges.filter( function ( r ) {
			return r.from === null || r.from === undefined || r.from < maxArea;
		} );
		return out.length ? out : ranges.slice( 0, 1 );
	}

	/** Passende Mengenstaffel, oder null (über der letzten Makito-Staffel: Preis auf Anfrage). */
	function findScale( range, qty ) {
		var scales = range.scales || [];
		var found = null;
		for ( var i = 0; i < scales.length; i++ ) {
			if ( qty >= scales[ i ].qty ) {
				found = scales[ i ];
			}
		}
		if ( ! found ) {
			found = scales[ 0 ] || null;
		}
		var last = scales[ scales.length - 1 ];
		if ( found && found === last && last.upto !== null && last.upto !== undefined && qty > last.upto ) {
			return null;
		}
		return found;
	}

	function round2( v ) {
		return Math.round( v * 100 ) / 100;
	}

	/**
	 * @param {Object} o {tech, range, qty, colours, area, repeat, handling}
	 */
	function computeLine( o ) {
		var tech = o.tech;
		var res = { onRequest: false, unit: 0, setup: 0, running: 0, total: 0, minJob: false, minQty: 0 };

		var scale = findScale( o.range, o.qty );
		if ( ! scale ) {
			res.onRequest = true;
			return res;
		}

		var handling = o.handling || 0;
		res.unit = unitPrice( tech, scale, o.colours, o.area ) + handling;
		res.running = o.qty * res.unit;

		// Mindestauftragswert (Makito-Tarif): gilt je Farbe. Jede Farbe kostet mindestens minJob,
		// die erste Farbe mit dem Preis, jede weitere mit dem Preis "weitere Farbe".
		var minJob = Number( tech.minJob ) || 0;
		if ( minJob > 0 ) {
			var running;
			if ( o.colours > 1 && scale.next ) {
				var first = o.qty * ( unitPrice( tech, scale, 1, o.area ) + handling );
				var more = o.qty * Number( scale.next );
				running = Math.max( first, minJob ) + ( o.colours - 1 ) * Math.max( more, minJob );
			} else {
				running = Math.max( res.running, minJob );
			}
			if ( running > res.running + 1e-9 ) {
				res.minJob = true;
				res.running = running;
			}
		}

		// Klischee/Einrichtung: bei Techniken mit "je Farbe" (Makito-Tarif "CLICHÉ X COLOR") für jede Farbe
		res.setup = o.repeat ? ( Number( tech.setupRepeat ) || 0 ) : ( Number( tech.setup ) || 0 );
		if ( tech.setupPerColour && o.colours > 1 ) {
			res.setup *= o.colours;
		}
		res.total = round2( res.running ) + round2( res.setup );

		if ( tech.minQty && o.qty < tech.minQty ) {
			res.minQty = tech.minQty;
		}
		return res;
	}

	root.MLDecoCalc = {
		imageForColor: function ( pos, code ) {
			if ( ! code || ! pos.colors || pos.colors.indexOf( code ) === -1 ) {
				return pos.image;
			}
			var m = /^(.*)-([A-Za-z0-9]{2})(_POS\d+\.(?:jpg|png))$/.exec( pos.image );
			return m ? m[ 1 ] + '-' + code + m[ 3 ] : pos.image;
		},
		unitPrice: unitPrice,
		reachableRanges: reachableRanges,
		findScale: findScale,
		computeLine: computeLine,
	};

	// ── Oberfläche ──────────────────────────────────────────────────────────────

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function fill( template, values ) {
		var i = 0;
		return String( template ).replace( /%(\d\$)?[sd]/g, function () {
			return values[ i++ ];
		} );
	}

	function init( section ) {
		var dataNode = section.querySelector( 'script.mld-calc__data' );
		var ui = section.querySelector( '.mld-calc__ui' );
		if ( ! dataNode || ! ui ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( dataNode.textContent );
		} catch ( e ) {
			return;
		}

		var t = data.i18n || {};
		var showPrices = !! data.showPrices;
		var fmt = data.format || {};
		var pattern = fmt.pattern || '%2$s\u00a0%1$s';
		var symbol = fmt.symbol || '\u20ac';
		var decSep = fmt.decimal !== undefined ? fmt.decimal : ',';
		var thouSep = fmt.thousand !== undefined ? fmt.thousand : '.';

		/** Zahl mit den Trennzeichen des Shops (WooCommerce-Einstellungen). */
		function formatNumber( v, dec ) {
			var neg = v < 0;
			var parts = Math.abs( v ).toFixed( dec ).split( '.' );
			var intPart = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, thouSep );
			return ( neg ? '-' : '' ) + intPart + ( dec > 0 ? decSep + parts[ 1 ] : '' );
		}
		function formatPrice( v, dec ) {
			return pattern.replace( '%1$s', symbol ).replace( '%2$s', formatNumber( v, dec ) );
		}
		/** Stückpreise: 2 Nachkommastellen, bei Bedarf 3 (0,105 € nicht auf 0,11 € runden) */
		function money( v ) {
			v = Math.round( v * 1000 ) / 1000;
			var cents = v * 100;
			return formatPrice( v, Math.abs( cents - Math.round( cents ) ) < 1e-4 ? 2 : 3 );
		}
		function money2( v ) {
			return formatPrice( round2( v ), 2 );
		}
		/** Mengen und Flächen: ganze Zahl, sonst eine Nachkommastelle */
		function num( v, dec ) {
			if ( dec === undefined ) {
				dec = Math.abs( v - Math.round( v ) ) < 1e-9 ? 0 : 1;
			}
			return formatNumber( dec === 0 ? Math.round( v ) : Math.round( v * 10 ) / 10, dec );
		}

		function rangeLabel( r ) {
			var from = r.from, to = r.to;
			if ( to !== null && to !== undefined && ( ! from || from <= 0 ) ) {
				return fill( t.areaTo, [ num( to ) ] );
			}
			if ( to !== null && to !== undefined ) {
				return fill( t.areaBetween, [ num( from ), num( to ) ] );
			}
			return fill( t.areaFrom, [ num( from || 0 ) ] );
		}

		var uid = 'mldc' + Math.floor( Math.random() * 1e6 );
		var state = { positions: [], qty: 1, repeat: false };
		var controls = [];                                  // je Position: set(line) für das Vorbelegen
		var repeatInput = null;
		var cfg = root.mldCalc || null;                     // {ajaxUrl, nonce} (wp_localize_script)
		var saveTimer = null;
		var lastSaved = '';
		var suppressSave = false;
		// Wunschlisten-Button der Produktseite (Zustand je Veredelung, siehe wlSetup)
		var wl = { btn: null, scope: null, qty: null, itemId: null, key: '', seq: 0, busy: false, qtyTimer: null };

		// Menge aus dem Produktfeld der Seite übernehmen (Seite führt, Feld hier ist änderbar)
		var qtyInput = null;
		var selector = section.getAttribute( 'data-qty-selector' ) || '';
		var pageQty = null;
		try {
			// zuerst im eigenen Produkt suchen (nicht in "Ähnliche Produkte"), dann auf der ganzen Seite
			var scope = section.closest( '.product' );
			pageQty = selector ? ( ( scope && scope.querySelector( selector ) ) || document.querySelector( selector ) ) : null;
		} catch ( e ) {
			pageQty = null;
		}
		var initialQty = pageQty ? parseInt( pageQty.value, 10 ) : 1;
		state.qty = initialQty > 0 ? initialQty : 1;

		var posImages = [];   // je Position mit Bild: {pos, btn, img}, für den Farbwechsel

		/** Bildadresse einer Position für einen Farbcode; ohne eigenes Bild für diese Farbe das Standardbild. */
		function imageFor( pos, code ) {
			if ( ! code || ! pos.colors || pos.colors.indexOf( code ) === -1 ) {
				return pos.image;
			}
			var m = /^(.*)-([A-Za-z0-9]{2})(_POS\d+\.(?:jpg|png))$/.exec( pos.image );
			return m ? m[ 1 ] + '-' + code + m[ 3 ] : pos.image;
		}

		/** Farbe der gewählten Variante: Positionsbilder tauschen (null = keine Variante gewählt). */
		function setColor( code ) {
			posImages.forEach( function ( p ) {
				var url = imageFor( p.pos, code );
				if ( p.img.getAttribute( 'src' ) !== url ) {
					p.img.setAttribute( 'src', url );
					p.btn.setAttribute( 'data-mld-full', url );
				}
			} );
		}

		function imageButton( pos ) {
			var btn = el( 'button', 'mld__img-btn' );
			btn.type = 'button';
			btn.setAttribute( 'data-mld-full', pos.image );
			btn.setAttribute( 'data-mld-title', pos.label );
			btn.setAttribute( 'aria-label', fill( t.zoomImage || '%s', [ pos.label ] ) );
			var img = el( 'img', 'mld__img' );
			img.loading = 'lazy';
			img.alt = '';
			img.src = pos.image;
			btn.appendChild( img );
			posImages.push( { pos: pos, btn: btn, img: img } );
			return btn;
		}

		var hint = el( 'p', 'mld-calc__hint', t.hint );
		var form = el( 'div', 'mld-calc__form' );

		data.positions.forEach( function ( pos, pi ) {
			var ps = { techId: '', colours: 1, rangeIdx: null, area: pos.maxArea || 0 };
			state.positions.push( ps );

			var fs = el( 'fieldset', 'mld-calc__pos' );
			var legend = el( 'legend', 'mld-calc__legend', pos.label );
			if ( pos.size ) {
				legend.appendChild( el( 'span', 'mld-calc__size', ' · ' + pos.size ) );
			}
			fs.appendChild( legend );
			var row = el( 'div', 'mld-calc__row' );

			function field( labelText, control, hidden, headExtra ) {
				var id = uid + '-' + pi + '-' + labelText.replace( /\W+/g, '' );
				var wrap = el( 'div', 'mld-calc__field' );
				var label = el( 'label', null, labelText );
				label.setAttribute( 'for', id );
				control.id = id;
				if ( headExtra ) {
					// Beschriftung links, Zusatzelement (z. B. "Zurücksetzen") rechts in derselben Zeile
					var head = el( 'div', 'mld-calc__fieldhead' );
					head.appendChild( label );
					head.appendChild( headExtra );
					wrap.appendChild( head );
				} else {
					wrap.appendChild( label );
				}
				wrap.appendChild( control );
				if ( hidden ) {
					wrap.hidden = true;
				}
				row.appendChild( wrap );
				return wrap;
			}

			var selTech = el( 'select' );
			var optNone = el( 'option', null, t.none );
			optNone.value = '';
			selTech.appendChild( optNone );
			pos.techniques.forEach( function ( pt ) {
				var tech = data.techniques[ pt.id ];
				var o = el( 'option', null, tech.name );
				o.value = pt.id;
				selTech.appendChild( o );
			} );

			// Kleiner Knopf: nur diese Position zurücksetzen (nur sichtbar, solange eine Technik gewählt ist);
			// steht rechts in der Beschriftungszeile der Technik und bleibt damit auch am Smartphone dort.
			var btnReset = el( 'button', 'mld-calc__reset', t.resetPos || 'Zurücksetzen' );
			btnReset.type = 'button';
			btnReset.hidden = true;
			btnReset.setAttribute( 'aria-label', fill( t.resetPosFor || '%s', [ pos.label ] ) );
			btnReset.addEventListener( 'click', function () {
				selTech.value = '';
				selTech.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				selTech.focus();
			} );
			field( t.technique, selTech, false, btnReset );

			var selColours = el( 'select' );
			var wrapColours = field( t.colours, selColours, true );
			var selRange = el( 'select' );
			var wrapRange = field( t.motifSize, selRange, true );
			var inArea = el( 'input' );
			inArea.type = 'number';
			inArea.step = '0.5';
			inArea.min = '1';
			var wrapArea = field( t.motifArea, inArea, true );

			function refresh() {
				var tech = ps.techId ? data.techniques[ ps.techId ] : null;
				btnReset.hidden = ! ps.techId;
				wrapColours.hidden = wrapRange.hidden = wrapArea.hidden = true;
				if ( ! tech ) {
					return;
				}
				var pt = pos.techniques.filter( function ( x ) { return x.id === ps.techId; } )[ 0 ];

				// Farben (nicht bei Vollfarbe oder wenn die Farbanzahl egal ist)
				var maxC = pt ? pt.maxColours : 0;
				if ( ! tech.fullColour && maxC > 1 ) {
					selColours.innerHTML = '';
					for ( var c = 1; c <= maxC; c++ ) {
						var oc = el( 'option', null, c === 1 ? t.colourOne : fill( t.colourMany, [ c ] ) );
						oc.value = String( c );
						selColours.appendChild( oc );
					}
					ps.colours = Math.min( ps.colours, maxC );
					selColours.value = String( ps.colours );
					wrapColours.hidden = false;
				} else {
					ps.colours = 1;
				}

				if ( ! showPrices ) {
					return;
				}

				// Flächenstufe (MidOcean) bzw. Motivfläche (Preis je cm²)
				var ranges = reachableRanges( tech, pos.maxArea );
				if ( ( tech.pricing === 'area_range' || tech.pricing === 'colour_area_range' ) && ranges.length > 1 ) {
					selRange.innerHTML = '';
					ranges.forEach( function ( r, ri ) {
						var orr = el( 'option', null, rangeLabel( r ) );
						orr.value = String( ri );
						selRange.appendChild( orr );
					} );
					if ( ps.rangeIdx === null || ps.rangeIdx >= ranges.length ) {
						ps.rangeIdx = ranges.length - 1; // vorsichtig: größte erreichbare Stufe
					}
					selRange.value = String( ps.rangeIdx );
					wrapRange.hidden = false;
				} else {
					ps.rangeIdx = 0;
				}
				var usesArea = ranges.some( function ( r ) {
					return ( r.scales || [] ).some( function ( s ) { return hasKey( s, 'cm' ) && s.cm; } );
				} ) || tech.pricing === 'area';
				if ( usesArea && pos.maxArea ) {
					inArea.max = String( pos.maxArea );
					inArea.value = String( ps.area );
					wrapArea.hidden = false;
				}
			}

			selTech.addEventListener( 'change', function () {
				ps.techId = selTech.value;
				ps.colours = 1;
				ps.rangeIdx = null;
				ps.area = pos.maxArea || 0;
				refresh();
				render();
			} );
			selColours.addEventListener( 'change', function () {
				ps.colours = parseInt( selColours.value, 10 ) || 1;
				render();
			} );
			selRange.addEventListener( 'change', function () {
				ps.rangeIdx = parseInt( selRange.value, 10 ) || 0;
				render();
			} );
			inArea.addEventListener( 'input', function () {
				var v = parseFloat( inArea.value );
				ps.area = v > 0 ? Math.min( v, pos.maxArea || v ) : pos.maxArea || 0;
				render();
			} );

			controls[ pi ] = {
				clear: function () {
					ps.techId = '';
					selTech.value = '';
					ps.colours = 1;
					ps.rangeIdx = null;
					ps.area = pos.maxArea || 0;
					refresh();
				},
				set: function ( line ) {
					ps.techId = line.tech;
					selTech.value = line.tech;
					ps.colours = line.colours > 0 ? line.colours : 1;
					ps.rangeIdx = line.range >= 0 ? line.range : null;
					ps.area = line.area > 0 ? line.area : ( pos.maxArea || 0 );
					refresh();
				},
			};

			// Bild der Druckposition links neben den Feldern (Klick: Vergrößerung wie in der Übersicht)
			var body = el( 'div', 'mld-calc__pos-body' );
			if ( pos.image ) {
				body.appendChild( imageButton( pos ) );
			}
			body.appendChild( row );
			fs.appendChild( body );
			form.appendChild( fs );
		} );

		// Menge (und Wiederholungsauftrag bei Preisen)
		var global = el( 'div', 'mld-calc__row mld-calc__global' );
		var qWrap = el( 'div', 'mld-calc__field' );
		var qLabel = el( 'label', null, t.quantity );
		qtyInput = el( 'input' );
		qtyInput.type = 'number';
		qtyInput.min = '1';
		qtyInput.step = '1';
		qtyInput.value = String( state.qty );
		qtyInput.id = uid + '-qty';
		qLabel.setAttribute( 'for', qtyInput.id );
		qWrap.appendChild( qLabel );
		qWrap.appendChild( qtyInput );
		global.appendChild( qWrap );

		if ( showPrices ) {
			var rWrap = el( 'div', 'mld-calc__field mld-calc__check' );
			var rInput = el( 'input' );
			repeatInput = rInput;
			rInput.type = 'checkbox';
			rInput.id = uid + '-repeat';
			var rLabel = el( 'label', null, t.repeat );
			rLabel.setAttribute( 'for', rInput.id );
			rWrap.appendChild( rInput );
			rWrap.appendChild( rLabel );
			global.appendChild( rWrap );
			rInput.addEventListener( 'change', function () {
				state.repeat = rInput.checked;
				render();
			} );
		}

		var resetAll = el( 'button', 'mld-calc__reset mld-calc__reset--all', t.resetAll || 'Auswahl zurücksetzen' );
		resetAll.type = 'button';
		resetAll.hidden = true;
		resetAll.addEventListener( 'click', function () {
			suppressSave = true;
			controls.forEach( function ( c ) {
				if ( c ) {
					c.clear();
				}
			} );
			state.repeat = false;
			if ( repeatInput ) {
				repeatInput.checked = false;
			}
			suppressSave = false;
			render();
		} );
		global.appendChild( resetAll );

		qtyInput.addEventListener( 'input', function () {
			var q = parseInt( qtyInput.value, 10 );
			state.qty = q > 0 ? q : 1;
			render();
		} );
		if ( pageQty ) {
			var follow = function () {
				var q = parseInt( pageQty.value, 10 );
				if ( q > 0 ) {
					state.qty = q;
					qtyInput.value = String( q );
					render();
				}
			};
			pageQty.addEventListener( 'input', follow );
			pageQty.addEventListener( 'change', follow );

			// Alpine.js (Konfigurator) setzt den Wert bei +/- und beim Start ohne input-Ereignis:
			// deshalb auch auf Klicks im Feldblock, auf den Alpine-Start und auf das Laden reagieren.
			var host = pageQty.closest( '.configurator-number, .mlw-wishlist-qty, .quantity' ) || pageQty.parentNode;
			if ( host ) {
				host.addEventListener( 'click', function () {
					setTimeout( follow, 50 );
				} );
			}
			document.addEventListener( 'alpine:initialized', function () {
				setTimeout( follow, 0 );
			} );
			window.addEventListener( 'load', function () {
				setTimeout( follow, 100 );
			} );
		}

		var result = el( 'div', 'mld-calc__result' );
		result.setAttribute( 'aria-live', 'polite' );

		ui.appendChild( hint );
		ui.appendChild( form );
		ui.appendChild( global );
		ui.appendChild( result );

		function selectedLines() {
			var lines = [];
			data.positions.forEach( function ( pos, pi ) {
				var ps = state.positions[ pi ];
				if ( ! ps.techId ) {
					return;
				}
				var tech = data.techniques[ ps.techId ];
				var colourText = ps.colours > 1 ? fill( t.colourMany, [ ps.colours ] ) : ( tech.fullColour ? '' : t.colourOne );
				lines.push( { pos: pos, ps: ps, tech: tech, colourText: colourText } );
			} );
			return lines;
		}

		/** Auswahl in der Form, die der Server prüft (keine Preise!). */
		function serialize() {
			var lines = [];
			data.positions.forEach( function ( pos, pi ) {
				var ps = state.positions[ pi ];
				if ( ps.techId ) {
					lines.push( { pos: pos.id, tech: ps.techId, colours: ps.colours, range: ps.rangeIdx || 0, area: ps.area || pos.maxArea || 0 } );
				}
			} );
			return { lines: lines, repeat: !! state.repeat };
		}

		function postAjax( action, extra ) {
			var body = new FormData();
			body.append( 'action', action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'product_id', String( data.productId || 0 ) );
			Object.keys( extra || {} ).forEach( function ( k ) {
				body.append( k, extra[ k ] );
			} );
			return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
				return r.json();
			} );
		}

		/** Auswahl serverseitig merken (für die Anfrage), leicht verzögert und nur bei Änderung. */
		function scheduleSave() {
			if ( ! cfg || ! data.productId || suppressSave || typeof fetch !== 'function' ) {
				return;
			}
			clearTimeout( saveTimer );
			saveTimer = setTimeout( function () {
				var json = JSON.stringify( serialize() );
				if ( json === lastSaved ) {
					return;
				}
				lastSaved = json;
				postAjax( 'mld_selection_save', { selection: json } ).catch( function () {
					lastSaved = '';
				} );
			}, 400 );
		}

		/** Beim Laden die zuletzt gemerkte Auswahl wieder anzeigen (Server hat sie geprüft). */
		function restoreSelection() {
			if ( ! cfg || ! data.productId || typeof fetch !== 'function' ) {
				return;
			}
			postAjax( 'mld_selection_get' ).then( function ( res ) {
				var sel = res && res.success && res.data ? res.data.selection : null;
				if ( ! sel || ! sel.lines || ! sel.lines.length ) {
					return;
				}
				suppressSave = true;
				sel.lines.forEach( function ( line ) {
					for ( var i = 0; i < data.positions.length; i++ ) {
						var pos = data.positions[ i ];
						var known = pos.id === line.pos && pos.techniques.some( function ( t ) { return t.id === line.tech; } );
						if ( known && controls[ i ] ) {
							controls[ i ].set( line );
						}
					}
				} );
				if ( repeatInput && sel.repeat ) {
					repeatInput.checked = true;
					state.repeat = true;
				}
				suppressSave = false;
				lastSaved = JSON.stringify( serialize() );
				render();
			} ).catch( function () {
				suppressSave = false;
			} );
		}

		/** Auswahl sofort speichern (ohne Verzögerung) und abwarten, z. B. unmittelbar vor "Zur Wunschliste hinzufügen". */
		function flushSave() {
			if ( ! cfg || ! data.productId || typeof fetch !== 'function' ) {
				return Promise.resolve();
			}
			clearTimeout( saveTimer );
			var json = JSON.stringify( serialize() );
			if ( json === lastSaved ) {
				return Promise.resolve();
			}
			lastSaved = json;
			return postAjax( 'mld_selection_save', { selection: json } ).catch( function () {
				lastSaved = '';
			} );
		}

		// ── Wunschlisten-Button auf der Produktseite ────────────────────────────
		// Der Button des geteilten Plugins schaltet je Produkt/Variante um. Mit Veredelung gehört zur Identität eines
		// Eintrags auch die Auswahl: dasselbe Produkt mit anderer Veredelung ist ein eigener Eintrag. Deshalb übernimmt
		// dieser Block auf Produktseiten mit Rechner den Button (Klick, Zustand, Menge) und nutzt dabei die vorhandenen
		// Endpunkte des geteilten Plugins (mlw_wishlist_add, _remove, _update_qty).

		function wlPost( action, extra ) {
			var body = new URLSearchParams( Object.assign( { action: action, nonce: root.mlwWishlist.nonce }, extra || {} ) );
			return fetch( root.mlwWishlist.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
			} ).then( function ( r ) {
				return r.json();
			} );
		}

		function wlVariationId() {
			var form = wl.scope.querySelector( 'form.variations_form' );
			var input = form ? form.querySelector( 'input.variation_id, input[name="variation_id"]' ) : null;
			return input ? ( parseInt( input.value, 10 ) || 0 ) : 0;
		}

		function wlQuantity() {
			var q = wl.qty ? parseInt( wl.qty.value, 10 ) : 1;
			return q > 0 ? q : 1;
		}

		function wlBadges( count ) {
			if ( typeof count !== 'number' ) {
				return;
			}
			root.mlwWishlist.count = count;
			document.querySelectorAll( '.mlw-wishlist-count' ).forEach( function ( el ) {
				el.textContent = count;
				el.style.display = count > 0 ? '' : 'none';
			} );
		}

		function wlSetActive( active ) {
			var btn = wl.btn;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			var text = active ? btn.dataset.labelRemove : btn.dataset.labelAdd;
			var label = btn.querySelector( '.mlw-add-to-wishlist__label' );
			if ( text && label ) {
				label.textContent = text;
			} else if ( text ) {
				btn.setAttribute( 'aria-label', text );
			}
		}

		function wlApply( state ) {
			wl.itemId = state && state.item_id ? state.item_id : null;
			wlSetActive( !! wl.itemId );
			// Steht genau diese Auswahl schon auf der Liste: Mengenfeld zeigt deren Menge (die Summe rechnet mit)
			if ( wl.itemId && state.quantity && wl.qty && parseInt( wl.qty.value, 10 ) !== state.quantity ) {
				wl.qty.value = state.quantity;
				wl.qty.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}

		/** Zustand für die aktuelle Kombination aus Variante und Veredelung vom Server holen. */
		function wlRefresh( force ) {
			if ( ! wl.btn ) {
				return;
			}
			var selection = JSON.stringify( serialize() );
			var variationId = wlVariationId();
			var key = variationId + '|' + selection;
			if ( ! force && key === wl.key ) {
				return;
			}
			wl.key = key;
			var seq = ++wl.seq;
			postAjax( 'mld_wishlist_state', { variation_id: variationId, selection: selection } ).then( function ( res ) {
				if ( seq === wl.seq && res && res.success ) {
					wlApply( res.data );
				}
			} ).catch( function () {} );
		}

		function wlFail( res ) {
			alert( ( res && res.data && res.data.message ) || ( root.mlwWishlist.i18n && root.mlwWishlist.i18n.genericError ) || 'Es ist ein Fehler aufgetreten.' );
		}

		function wlClick( e ) {
			if ( ! e.target.closest || e.target.closest( '.mlw-add-to-wishlist--single' ) !== wl.btn ) {
				return;
			}
			var variationId = wlVariationId();
			if ( wl.btn.dataset.variable === '1' && ! variationId ) {
				return; // ohne gewählte Variante zeigt das geteilte Plugin seinen Hinweis
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			if ( wl.busy ) {
				return;
			}
			wl.busy = true;
			wl.btn.disabled = true;
			var finish = function () {
				wl.busy = false;
				wl.btn.disabled = false;
			};

			if ( wl.itemId ) {
				// Genau diese Auswahl steht auf der Liste: genau diesen Eintrag entfernen
				wlPost( 'mlw_wishlist_remove', { item_id: wl.itemId } ).then( function ( res ) {
					if ( res.success ) {
						wlBadges( res.data.count );
						wl.itemId = null;
						wlSetActive( false );
					} else {
						wlFail( res );
					}
					finish();
				} ).catch( function () {
					wlFail( null );
					finish();
				} );
				return;
			}

			// Auswahl zuerst speichern: Der Eintrag übernimmt sie serverseitig (nicht aus dem Request)
			flushSave().then( function () {
				return wlPost( 'mlw_wishlist_add', { product_id: data.productId, variation_id: variationId, quantity: wlQuantity() } );
			} ).then( function ( res ) {
				if ( res.success ) {
					wlBadges( res.data.count );
					wlRefresh( true );
				} else {
					wlFail( res );
				}
				finish();
			} ).catch( function () {
				wlFail( null );
				finish();
			} );
		}

		/** Menge geändert: Eintrag der aktuellen Auswahl aktualisieren (das geteilte Skript träfe die falsche item_id). */
		function wlQtyChange( e ) {
			if ( e.target !== wl.qty ) {
				return;
			}
			e.stopPropagation();
			var q = wlQuantity();
			wl.qty.value = q;
			wl.qty.dispatchEvent( new Event( 'input', { bubbles: true } ) );   // Summenzeile unter dem Preis aktuell halten
			if ( ! wl.itemId ) {
				return;
			}
			clearTimeout( wl.qtyTimer );
			var itemId = wl.itemId;
			wl.qtyTimer = setTimeout( function () {
				wlPost( 'mlw_wishlist_update_qty', { item_id: itemId, quantity: q } ).catch( function () {} );
			}, 400 );
		}

		function wlSetup() {
			if ( ! cfg || ! data.productId || typeof fetch !== 'function' || typeof URLSearchParams === 'undefined' || ! root.mlwWishlist ) {
				return;
			}
			wl.scope = section.closest( '.product' ) || document;
			wl.btn = wl.scope.querySelector( '.mlw-add-to-wishlist--single' );
			if ( ! wl.btn ) {
				return;
			}
			var wrap = wl.btn.closest( '.mlw-wishlist-action' );
			wl.qty = wrap ? wrap.querySelector( '.mlw-wishlist-qty__input' ) : null;

			// Capture-Phase: vor den Handlern des geteilten Skripts
			document.addEventListener( 'click', wlClick, true );
			if ( wl.qty ) {
				document.addEventListener( 'change', wlQtyChange, true );
			}
			var form = wl.scope.querySelector( 'form.variations_form' );
			if ( form && typeof window.jQuery !== 'undefined' ) {
				window.jQuery( form ).on( 'found_variation reset_data hide_variation', function () {
					setTimeout( function () {
						wlRefresh( false );
					}, 50 );
				} );
			}
		}

		function render() {
			scheduleSave();
			wlRefresh( false );
			result.innerHTML = '';
			var lines = selectedLines();
			resetAll.hidden = ! lines.length;
			if ( ! lines.length ) {
				result.appendChild( el( 'p', 'mld-calc__empty', t.pick ) );
				return;
			}

			if ( ! showPrices ) {
				result.appendChild( el( 'h4', 'mld-calc__h', t.selection ) );
				var ul = el( 'ul', 'mld-calc__list' );
				lines.forEach( function ( l ) {
					ul.appendChild( el( 'li', null, l.pos.label + ': ' + l.tech.name + ( l.colourText ? ', ' + l.colourText : '' ) ) );
				} );
				result.appendChild( ul );
				if ( t.sendNote ) {
					result.appendChild( el( 'p', 'mld__note', t.sendNote ) );
				}
				return;
			}

			var scroll = el( 'div', 'mld__scroll' );
			var table = el( 'table', 'mld__table mld-calc__table' );
			var head = el( 'thead' );
			var hr = el( 'tr' );
			[ t.colPos, t.colUnit, t.colSetup, t.colTotal ].forEach( function ( h ) {
				var th = el( 'th', null, h );
				th.scope = 'col';
				hr.appendChild( th );
			} );
			head.appendChild( hr );
			table.appendChild( head );

			var body = el( 'tbody' );
			var sum = 0;
			var notes = [];
			var anyRequest = false;

			lines.forEach( function ( l ) {
				var ranges = reachableRanges( l.tech, l.pos.maxArea );
				var range = ranges[ Math.min( l.ps.rangeIdx || 0, ranges.length - 1 ) ];
				var calc = computeLine( {
					tech: l.tech,
					range: range,
					qty: state.qty,
					colours: l.ps.colours,
					area: l.ps.area || l.pos.maxArea || 0,
					repeat: state.repeat,
					handling: data.handling || 0,
				} );

				var tr = el( 'tr' );
				var first = el( 'td' );
				first.appendChild( el( 'strong', null, l.pos.label ) );
				first.appendChild( document.createElement( 'br' ) );
				first.appendChild( document.createTextNode( l.tech.name + ( l.colourText ? ', ' + l.colourText : '' ) ) );
				tr.appendChild( first );

				if ( calc.onRequest ) {
					anyRequest = true;
					var td = el( 'td', 'mld-calc__request', t.onRequest );
					td.colSpan = 3;
					tr.appendChild( td );
				} else {
					sum += calc.total;
					// data-label: Am Smartphone wird die Tabelle zur Liste, die Beschriftung steht dann vor dem Wert
					var tdUnit = el( 'td', null, money( calc.unit ) );
					tdUnit.setAttribute( 'data-label', t.colUnit );
					var tdSetup = el( 'td', null, calc.setup > 0 ? money2( calc.setup ) : '–' );
					tdSetup.setAttribute( 'data-label', t.colSetup );
					var tdTotal = el( 'td', 'mld-calc__cell-total', money2( calc.total ) );
					tdTotal.setAttribute( 'data-label', t.colTotal );
					tr.appendChild( tdUnit );
					tr.appendChild( tdSetup );
					tr.appendChild( tdTotal );
					if ( calc.minJob && notes.indexOf( t.minJob ) === -1 ) {
						notes.push( t.minJob );
					}
					if ( calc.minQty ) {
						notes.push( fill( t.minQty, [ num( calc.minQty, 0 ) ] ) );
					}
					var usesArea = hasKey( range.scales[ 0 ] || {}, 'cm' ) && range.scales.some( function ( s ) { return s.cm; } ) || l.tech.pricing === 'area';
					if ( usesArea ) {
						notes.push( l.pos.label + ': ' + fill( t.maxArea, [ num( l.ps.area || l.pos.maxArea || 0 ) ] ) );
					}
				}
				body.appendChild( tr );
			} );
			table.appendChild( body );

			if ( ! anyRequest || sum > 0 ) {
				var foot = el( 'tfoot' );
				var ft = el( 'tr' );
				var lab = el( 'th', null, t.total );
				lab.colSpan = 3;
				lab.scope = 'row';
				ft.appendChild( lab );
				ft.appendChild( el( 'td', 'mld-calc__sum', money2( sum ) ) );
				foot.appendChild( ft );
				table.appendChild( foot );
			}

			scroll.appendChild( table );
			result.appendChild( scroll );

			if ( sum > 0 && state.qty > 0 ) {
				result.appendChild( el( 'p', 'mld-calc__avg', fill( t.average, [ money( sum / state.qty ) ] ) ) );
			}
			notes.forEach( function ( n ) {
				result.appendChild( el( 'p', 'mld__note', n ) );
			} );
			if ( t.sendNote ) {
				result.appendChild( el( 'p', 'mld__note', t.sendNote ) );
			}
		}

		// Farbwechsel im Variationsformular (WooCommerce): die Positionsbilder folgen der gewählten Farbe, auch wenn
		// erst die Farbe gewählt ist (WooCommerce meldet eine Variante erst mit allen Feldern, also auch der Größe).
		( function () {
			var scope = section.closest( '.product' ) || document;
			var form = scope.querySelector( 'form.variations_form' );
			if ( ! form || typeof window.jQuery === 'undefined' || ! posImages.length ) {
				return;
			}
			var colorMap = data.colorMap || {};
			var colorField = data.colorAttr || 'attribute_pa_color';

			/** Farbcode zur aktuell gewählten Farbe im Formular (null: keine Farbe gewählt oder unbekannt). */
			function codeFromSelect() {
				var select = form.querySelector( '[name="' + colorField + '"]' );
				var value = select ? select.value : '';
				return value && colorMap[ value ] ? String( colorMap[ value ] ) : null;
			}

			window.jQuery( form )
				.on( 'found_variation', function ( e, variation ) {
					setColor( variation && variation.mld_color ? String( variation.mld_color ) : codeFromSelect() );
				} )
				.on( 'change', '[name="' + colorField + '"]', function () {
					setColor( codeFromSelect() );
				} )
				.on( 'reset_data hide_variation', function () {
					// nach WooCommerce: Das Farbfeld kann gewählt bleiben (Variante unvollständig), dann bleibt das Farbbild
					setTimeout( function () {
						setColor( codeFromSelect() );
					}, 0 );
				} );

			setColor( codeFromSelect() );   // Vorauswahl der Seite (Standardwerte der Variante)
		}() );

		wlSetup();
		render();
		restoreSelection();
	}

	function boot() {
		var sections = document.querySelectorAll( '.mld-calc' );
		for ( var i = 0; i < sections.length; i++ ) {
			init( sections[ i ] );
		}
	}

	if ( typeof document !== 'undefined' ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', boot );
		} else {
			boot();
		}
	}
}( typeof window !== 'undefined' ? window : this ) );
