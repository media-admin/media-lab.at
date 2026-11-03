/* Veredelung (media-lab-ml-decoration): Lightbox für die Bilder der Druckpositionen.
 * Ohne Abhängigkeiten, nutzt das native <dialog>-Element (ESC, Fokusfalle und Hintergrund inklusive). */
( function () {
	'use strict';

	var l10n = window.mldL10n || { close: 'Schließen', fallback: 'Druckposition' };
	var dialog = null;
	var img = null;
	var caption = null;

	function build() {
		dialog = document.createElement( 'dialog' );
		dialog.className = 'mld-lightbox';
		dialog.setAttribute( 'aria-label', l10n.fallback );

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'mld-lightbox__close';
		close.setAttribute( 'aria-label', l10n.close );
		close.innerHTML = '&times;';

		var figure = document.createElement( 'figure' );
		figure.className = 'mld-lightbox__fig';
		img = document.createElement( 'img' );
		img.className = 'mld-lightbox__img';
		img.alt = '';
		caption = document.createElement( 'figcaption' );
		caption.className = 'mld-lightbox__cap';
		figure.appendChild( img );
		figure.appendChild( caption );

		dialog.appendChild( close );
		dialog.appendChild( figure );
		document.body.appendChild( dialog );

		// Klick auf Hintergrund, Bild oder Schließen-Knopf schließt
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog || e.target === img || e.target.closest( '.mld-lightbox__close' ) ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function () {
			img.removeAttribute( 'src' );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.mld__img-btn' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var src = btn.getAttribute( 'data-mld-full' );
		var title = btn.getAttribute( 'data-mld-title' ) || l10n.fallback;
		if ( ! src ) {
			return;
		}

		// Sehr alte Browser ohne <dialog>: Bild direkt öffnen
		if ( typeof HTMLDialogElement === 'undefined' ) {
			window.open( src, '_blank', 'noopener' );
			return;
		}

		if ( ! dialog ) {
			build();
		}
		img.src = src;
		img.alt = title;
		caption.textContent = title;
		dialog.setAttribute( 'aria-label', title );
		dialog.showModal();
	} );
}() );
