/**
 * Wunschliste: Hinweise unter den Preisen von Einträgen mit Veredelung.
 *
 * Der Stückpreis bleibt der Textilpreis, die Zeilensumme enthält die Veredelung (ML_Deco_Selection::display_items).
 * Damit das nicht verwirrt, steht unter dem Stückpreis "zzgl. Veredelung" und unter der Zeilensumme "inkl. Veredelung".
 * In der geteilten Ansicht (shared.php) stehen Stückpreis und Summe in einer Zeile ("X pro Stück · Y gesamt"), dort steht
 * ein gemeinsamer Hinweis darunter.
 * Die Vorlage der Wunschliste (media-lab-woocommerce) gibt die Preise selbst aus und rendert nach Ajax-Änderungen neu,
 * deshalb arbeitet dieses Skript am fertigen Markup: Eintrag mit der Detailzeile "Gesamt inkl. Veredelung ..." bekommt
 * die Hinweise, der Rest nicht. Läuft beim Laden und nach jedem Neuaufbau der Liste.
 */
( function () {
	'use strict';

	var cfg = window.mldWishlistNotes || null;
	if ( ! cfg || ! cfg.marker ) {
		return;
	}

	var NOTE = 'mld-price-note';

	function note( text ) {
		var el = document.createElement( 'small' );
		el.className = NOTE;
		el.style.cssText = 'display:block;font-size:.8em;font-weight:400;opacity:.75';
		el.textContent = text;
		return el;
	}

	function hasDecoration( item ) {
		var labels = item.querySelectorAll( '.mlw-wishlist-item__config li > span' );
		for ( var i = 0; i < labels.length; i++ ) {
			if ( labels[ i ].textContent.indexOf( cfg.marker ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	function sync( cell, text, wanted ) {
		var have = cell.querySelector( '.' + NOTE );
		if ( wanted && cell.textContent.trim() !== '' && ! have ) {
			cell.appendChild( note( text ) );
		} else if ( ! wanted && have ) {
			have.remove();
		}
	}

	function apply() {
		var items = document.querySelectorAll( '.mlw-wishlist-item' );
		for ( var i = 0; i < items.length; i++ ) {
			var wanted = hasDecoration( items[ i ] );
			var price = items[ i ].querySelector( '.mlw-wishlist-item__price' );
			var total = items[ i ].querySelector( '.mlw-wishlist-item__line-total' );
			if ( items[ i ].classList.contains( 'mlw-wishlist-item--shared' ) ) {
				// Geteilte Ansicht: eine Preiszeile, ein gemeinsamer Hinweis.
				if ( price && cfg.shared ) {
					sync( price, cfg.shared, wanted );
				}
				continue;
			}
			if ( price ) {
				sync( price, cfg.unit, wanted );
			}
			if ( total ) {
				sync( total, cfg.total, wanted );
			}
		}
	}

	var timer = null;
	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( apply, 30 );
	}

	function start() {
		apply();
		// Die Liste wird nach Mengenänderung und Entfernen neu aufgebaut. apply() ändert nur, was fehlt oder zu viel ist,
		// löst also keine Endlosschleife aus.
		if ( typeof MutationObserver !== 'undefined' ) {
			new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
