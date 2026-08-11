<?php
/**
 * Slice ProductCondition — cena rynkowa nowego w natywnym Product Data (P-13.5).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

use WC_Product;

/**
 * Pole „Cena rynkowa nowego" (odniesienie „nowy w sklepach / średnia rynkowa",
 * baza rabatu — kontrakt §2, §6) w zakładce **General** panelu danych produktu
 * WooCommerce, tuż pod ceną promocyjną (D-13.5.1 — dosłowne „między
 * `_regular_price` a `_sale_price`" niewykonalne bez patcha rdzenia, bo oba pola
 * to hardcoded HTML w `html-product-data-general.php`, nie callbacki na żadnym
 * hooku; `woocommerce_product_options_pricing` to najbliższy dostępny hook,
 * wciąż wewnątrz tego samego boksu `options_group pricing`).
 *
 * UWAGA o widoczności (inaczej niż `ProductDiscountRateField`): boks
 * `options_group pricing` ma klasy `show_if_simple show_if_external` (JS Woo
 * chowa go dla `grouped`/`variable`) — pole jest więc widoczne tylko dla
 * produktów prostych/zewnętrznych, NIE dla wszystkich typów jak
 * `_qutlet_stawka_rabatu` (ten hook jest poza jakimkolwiek `show_if_*`).
 * W praktyce bez znaczenia: `qutlet-allegro\OfferSync\ProductWriter` tworzy
 * WYŁĄCZNIE `WC_Product_Simple` (potwierdzone ground-truth P-13.5 — cały
 * katalog Local, 525/525 produktów, typ `simple`).
 *
 * Przenosiny z ACF (P-13.5, REWIZJA P-1.2/P-9.2): meta_key ZOSTAJE publiczny
 * `cena_rynkowa_nowego` (D-13.5.2, bez podkreślnika, bez migracji danych) —
 * `ProductConditionFields` przestała rejestrować to pole jako ACF, ale wartości
 * pod tym samym kluczem czytane są teraz wprost (`get_post_meta`/
 * `update_post_meta`), jak `_qutlet_stawka_rabatu` ({@see
 * \Qutlet\Core\Pricing\ProductDiscountRateField}), zamiast prywatnego klucza z
 * podkreślnikiem — bo `_cena_rynkowa_nowego` już istnieje jako WEWNĘTRZNY
 * reference meta ACF (klucz pola, nie cena) na każdym produkcie, gdzie to pole
 * było kiedyś zapisane przez ACF; przejęcie tego klucza wymagałoby migracji
 * danych, której świadomie unikamy. Odczyt w motywie (`ProductPage::acf_field()`)
 * i SQL sortowania rabatu (`ProductFilters\ProductFilterQuery::
 * MARKET_PRICE_META_KEY`) działają bez zmian — ACF, gdy pole nie jest już
 * zarejestrowane, sam degraduje `get_field()` do surowego `get_post_meta()`
 * (dummy field, `acf_maybe_get_field()` w rdzeniu ACF).
 *
 * Bezpieczeństwo zapisu: jak {@see \Qutlet\Core\Pricing\ProductDiscountRateField}
 * — hook `woocommerce_admin_process_product_object` odpala się już PO
 * weryfikacji nonce'a i uprawnień przez Woo (`WC_Admin_Meta_Boxes::
 * save_meta_boxes()`), więc pole NIE ma własnego nonce'a.
 */
final class MarketPriceField {

	/**
	 * Meta_key (kontrakt §2, VERBATIM — zgodny z
	 * {@see \Qutlet\Core\ProductFilters\ProductFilterQuery::MARKET_PRICE_META_KEY}).
	 */
	public const META_KEY = 'cena_rynkowa_nowego';

	/**
	 * Wpina render pola i zapis. Wołane z bootstrapu core (na `plugins_loaded`,
	 * po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', array( self::class, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
	}

	/**
	 * Renderuje pole w boksie cenowym zakładki General, tuż pod ceną promocyjną.
	 *
	 * @return void
	 */
	public static function render_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_KEY,
				'label'       => __( 'Cena rynkowa nowego', 'qutlet-core' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Odniesienie „nowy w sklepach / średnia rynkowa" — baza rabatu. Puste → motyw ukrywa linię „nowy" i rabat.', 'qutlet-core' ),
			)
		);
	}

	/**
	 * Zapisuje cenę rynkową przy zapisie produktu w adminie.
	 *
	 * Puste/nienumeryczne wejście USUWA meta (stan „nie ustawiono" = brak
	 * wpisu — motyw i tak sprawdza tylko obecność wartości, kontrakt §2).
	 *
	 * @param WC_Product $product Produkt przetwarzany przez metabox Woo.
	 * @return void
	 */
	public static function save( WC_Product $product ): void {
		// Nonce zweryfikowany przez Woo przed tym hookiem (patrz docblock klasy).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return; // Formularz bez pola (np. zapis programowy) — nie ruszamy meta.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw   = wc_clean( wp_unslash( (string) $_POST[ self::META_KEY ] ) );
		$value = '' === $raw ? '' : wc_format_decimal( $raw );

		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			$product->delete_meta_data( self::META_KEY );

			return;
		}

		$product->update_meta_data( self::META_KEY, $value );
	}
}
