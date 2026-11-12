/**
 * Gruppen mit Überschrift in den Auswahllisten der Produktfilter (Projekt media-lab.at)
 *
 * Die Filterleiste (Plugin media-lab-woocommerce) rendert jedes Attribut als flache Liste. Für Attribute, zu denen die Seite
 * Gruppen mitliefert (JSON in <script id="mlwf-term-groups">, Format {"pa_groesse": {"<slug>": ["Gruppe", Gruppennummer, Platz]}},
 * ausgegeben von media-lab-ml-sku, inc/groessen.php), hängt dieses Skript die vorhandenen Listeneinträge in Gruppen um:
 *
 *   - Jede Gruppe (Kleidergrößen, Kinder ...) ist eine einklappbare Gruppe (<details>) mit Zähler für gewählte Werte in der
 *     Überschrift und beim ersten Öffnen EINGEKLAPPT. Offen ist nur eine Gruppe mit gewähltem Wert, oder die einzige Gruppe
 *     im aktuellen Kontext (sonst stünde eine Überschrift ohne Inhalt da).
 *   - Die Einträge (<li>) werden nur UMGEHÄNGT, nicht neu erzeugt: Ereignisse, Zähler und das Filtern in mlwf-filters.js
 *     funktionieren unverändert. Das Skript wird VOR mlwf-filters.js ausgeführt (import am Anfang von mlwf-filters.js).
 *   - Ohne JSON oder ohne passende Liste passiert nichts. Fehler lassen die Liste, wie sie ist.
 *
 * Markup je Gruppe (Styles: _woocommerce.scss, Abschnitt "Gruppen in der Checkbox-Liste"):
 *   li.wc-filter-checklist__group > details.wc-filter-subgroup > summary.wc-filter-subgroup__title + ul.wc-filter-checklist--sub
 */
( function () {
	'use strict';

	const DATA_ID  = 'mlwf-term-groups';
	const FALLBACK = 'Weitere';

	function readData() {
		const el = document.getElementById( DATA_ID );
		if ( ! el ) return null;
		try {
			const data = JSON.parse( el.textContent );
			return data && typeof data === 'object' ? data : null;
		} catch ( e ) {
			return null;
		}
	}

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( text !== undefined ) node.textContent = text;
		return node;
	}

	function updateBadges( root ) {
		root.querySelectorAll( '.wc-filter-subgroup' ).forEach( group => {
			const badge = group.querySelector( '.wc-filter-subgroup__badge' );
			if ( ! badge ) return;
			const n = group.querySelectorAll( '.wc-filter-option__checkbox:checked' ).length;
			badge.textContent = n;
			badge.hidden      = n === 0;
		} );
	}

	function groupList( groupEl, info ) {
		const list = groupEl.querySelector( '.wc-filter-checklist' );
		if ( ! list || groupEl.dataset.mlwfGrouped ) return;

		// Einträge samt Wert-Slug: der Slug steht im value der Checkbox
		const items = Array.from( list.children )
			.filter( li => li.tagName === 'LI' )
			.map( li => {
				const cb   = li.querySelector( '.wc-filter-option__checkbox' );
				const slug = cb ? cb.value : '';
				const meta = info[ slug ];
				return {
					li,
					group:      meta ? String( meta[ 0 ] ) : FALLBACK,
					groupOrder: meta ? Number( meta[ 1 ] ) : 999999,
					order:      meta ? Number( meta[ 2 ] ) : 999999,
					checked:    !! ( cb && cb.checked ),
				};
			} );
		if ( ! items.length ) return;

		const groups = new Map();
		items.forEach( item => {
			if ( ! groups.has( item.group ) ) groups.set( item.group, { name: item.group, order: item.groupOrder, items: [] } );
			groups.get( item.group ).items.push( item );
		} );
		const sorted = Array.from( groups.values() ).sort( ( a, b ) => a.order - b.order );
		sorted.forEach( g => g.items.sort( ( a, b ) => a.order - b.order ) );

		const onlyOne = sorted.length === 1;
		const frag    = document.createDocumentFragment();
		sorted.forEach( g => {
			const hasChecked = g.items.some( item => item.checked );
			const sub  = el( 'ul', 'wc-filter-checklist wc-filter-checklist--sub' );
			sub.setAttribute( 'role', 'group' );
			sub.setAttribute( 'aria-label', g.name );
			g.items.forEach( item => sub.appendChild( item.li ) );   // umhängen, nicht neu erzeugen

			const box = el( 'details', 'wc-filter-subgroup' );
			if ( onlyOne || hasChecked ) box.open = true;
			const summary = el( 'summary', 'wc-filter-subgroup__title' );
			summary.appendChild( el( 'span', 'wc-filter-subgroup__name', g.name ) );
			const badge = el( 'span', 'wc-filter-subgroup__badge', '0' );
			badge.hidden = true;
			summary.appendChild( badge );
			box.appendChild( summary );
			box.appendChild( sub );

			const wrap = el( 'li', 'wc-filter-checklist__group' );
			wrap.appendChild( box );
			frag.appendChild( wrap );
		} );

		list.replaceChildren( frag );
		groupEl.dataset.mlwfGrouped = '1';
		updateBadges( groupEl );
		groupEl.addEventListener( 'change', () => updateBadges( groupEl ) );

		// mlwf-filters.js überträgt Werte aus der URL (syncFromUrl) erst NACH diesem Skript auf die Kästchen:
		// danach Gruppen mit gewähltem Wert öffnen und Zähler nachziehen
		setTimeout( () => openCheckedGroups( groupEl ), 0 );
	}

	function openCheckedGroups( root ) {
		root.querySelectorAll( 'details.wc-filter-subgroup' ).forEach( d => {
			if ( d.querySelector( '.wc-filter-option__checkbox:checked' ) ) d.open = true;
		} );
		updateBadges( root );
	}

	function run() {
		try {
			const data = readData();
			if ( ! data ) return;
			Object.keys( data ).forEach( attr => {
				const info = data[ attr ];
				if ( ! info || typeof info !== 'object' ) return;
				document.querySelectorAll( '.wc-filter-group[data-attribute="' + attr + '"]' ).forEach( groupEl => groupList( groupEl, info ) );
			} );

			// "Zurücksetzen" setzt die Kästchen ohne change-Ereignis zurück: Zähler danach nachziehen
			document.addEventListener( 'click', e => {
				if ( e.target.closest && e.target.closest( '.js-filter-reset' ) ) {
					setTimeout( () => document.querySelectorAll( '.wc-filter-group[data-mlwf-grouped]' ).forEach( updateBadges ), 0 );
				}
			} );
		} catch ( e ) {
			console.warn( '[MLWF] Gruppen in der Filterleiste konnten nicht gebildet werden.', e );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
