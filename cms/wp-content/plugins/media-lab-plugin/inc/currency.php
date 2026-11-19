<?php
/**
 * Währungsdarstellung: "EUR 100.000,00" statt "100.000,00 €".
 *
 * Projektentscheidung (Markus, 19.11.2026): überall im Auftritt, im Backend, in Mails und Anfragen steht die
 * Abkürzung EUR mit Leerzeichen vor dem Betrag. Alle Preise, die über WooCommerce laufen (wc_price(), Preis-
 * Einstellungen der Plugins, JavaScript-Formatierung der Wunschliste und des Veredelungs-Rechners), lesen Symbol,
 * Position und Trennzeichen aus WooCommerce. Deshalb reicht dieser eine Ort. Er überschreibt auch den Filter
 * `woocommerce_currency_symbol` des Themes (dort steht "€", Priorität 10).
 *
 * Nicht erfasst: Texte, in denen "€" von Hand getippt ist (Seiteninhalt, ACF-Felder, Newsletter, Produkttexte).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Nur für Euro, andere Währungen bleiben unverändert. */
function medialab_project_is_eur(): bool {
	return function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() === 'EUR';
}

/** Symbol: "EUR" statt "€". */
add_filter( 'woocommerce_currency_symbol', static function ( $symbol, $currency ) {
	return 'EUR' === $currency ? 'EUR' : $symbol;
}, 99, 2 );

/** Position: Symbol vor dem Betrag, mit geschütztem Leerzeichen (kein Zeilenumbruch zwischen EUR und Zahl). */
add_filter( 'woocommerce_price_format', static function ( $format ) {
	return medialab_project_is_eur() ? '%1$s&nbsp;%2$s' : $format;
}, 99 );

/** Trennzeichen: 100.000,00 (Tausenderpunkt, Dezimalkomma), unabhängig von der Einstellung in der Datenbank. */
add_filter( 'wc_get_price_decimal_separator', static function ( $sep ) {
	return medialab_project_is_eur() ? ',' : $sep;
}, 99 );

add_filter( 'wc_get_price_thousand_separator', static function ( $sep ) {
	return medialab_project_is_eur() ? '.' : $sep;
}, 99 );
