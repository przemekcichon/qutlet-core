<?php
/**
 * Slice Pricing — efektywna stawka rabatu ceny sklepu (P-6.1a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\Pricing;

/**
 * Jedno źródło odczytu EFEKTYWNEJ stawki rabatu (D-4.1.2 / D-6.1.1): nadpisanie
 * per produkt (`_qutlet_stawka_rabatu`, zakładka General danych produktu) ??
 * globalna opcja (`qutlet_stawka_rabatu`, strona ustawień pod menu WooCommerce).
 * Literały z `docs/kontrakt-danych.md` §11 — VERBATIM, case-sensitive.
 *
 * Konsumentem jest import/sync (`qutlet-allegro`, P-6.1b), który liczy
 * `_price = cena_allegro × (1 − stawka/100)`. Sama FORMUŁA świadomie NIE mieszka
 * w core — zakres P-6.1a to powierzchnia danych (opcja + pole + odczyt), a
 * przeliczanie cen jest zachowaniem importu (plan, D-4.1.2).
 *
 * Obie wartości to STRINGI numeryczne w procentach (konwencja meta/opcji WP —
 * tak samo Woo trzyma `_price`); puste = „nie ustawiono". Nadpisanie i opcja są
 * wprowadzane RĘCZNIE (wartość globalna = średnie miesięczne koszty działalności
 * na Allegro) — sync ich nigdy nie zapisuje.
 */
final class DiscountRate {

	/**
	 * Nazwa globalnej opcji stawki rabatu — kontrakt §11 (VERBATIM).
	 */
	public const OPTION_NAME = 'qutlet_stawka_rabatu';

	/**
	 * `meta_key` nadpisania stawki per produkt — kontrakt §11 (VERBATIM).
	 */
	public const META_OVERRIDE = '_qutlet_stawka_rabatu';

	/**
	 * Efektywna stawka rabatu produktu w procentach (0–100).
	 *
	 * Nadpisanie per produkt ma pierwszeństwo; puste/nienumeryczne nadpisanie →
	 * globalna opcja; pusta/nienumeryczna opcja → 0.0 (import przepisze cenę
	 * Allegro 1:1). Wartość przycinana do [0, 100] — stawka spoza tego przedziału
	 * nie ma sensu biznesowego (ujemna podnosiłaby cenę, >100 dawałaby ujemną).
	 *
	 * @param int $product_id ID produktu (post ID).
	 * @return float Stawka w procentach.
	 */
	public static function effective_percent( int $product_id ): float {
		$override = get_post_meta( $product_id, self::META_OVERRIDE, true );

		if ( is_string( $override ) && is_numeric( $override ) ) {
			return self::clamp( (float) $override );
		}

		$global = get_option( self::OPTION_NAME, '' );

		if ( is_string( $global ) && is_numeric( $global ) ) {
			return self::clamp( (float) $global );
		}

		return 0.0;
	}

	/**
	 * Sanityzuje surową wartość stawki (opcja albo pole produktu) do stringa
	 * numerycznego w [0, 100] lub pustego stringa („nie ustawiono").
	 *
	 * `wc_format_decimal()` normalizuje wejście z panelu (przecinek dziesiętny,
	 * spacje) do kropki — WooCommerce jest twardą zależnością core (D-G5), więc
	 * funkcja jest zawsze dostępna.
	 *
	 * @param mixed $value Surowa wartość z formularza.
	 * @return string Znormalizowany procent albo pusty string.
	 */
	public static function sanitize_percent( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$decimal = wc_format_decimal( (string) $value );

		if ( '' === $decimal || ! is_numeric( $decimal ) ) {
			return '';
		}

		return (string) self::clamp( (float) $decimal );
	}

	/**
	 * Przycina stawkę do sensownego przedziału [0, 100].
	 *
	 * @param float $percent Stawka w procentach.
	 * @return float
	 */
	private static function clamp( float $percent ): float {
		return min( 100.0, max( 0.0, $percent ) );
	}
}
