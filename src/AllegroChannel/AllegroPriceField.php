<?php
/**
 * Slice AllegroChannel — cena Allegro w natywnym Product Data (FAZA 20/P-20.7b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AllegroChannel;

use WC_Product;

/**
 * Pole „Cena Allegro" (kontrakt §4) w zakładce **General** panelu danych
 * produktu WooCommerce, hook `woocommerce_product_options_pricing` —
 * DOKŁADNIE ten sam mechanizm co {@see
 * \Qutlet\Core\ProductCondition\MarketPriceField} (D-13.5.1/D-20.8), z
 * priorytetem NIŻSZYM (9 < domyślne 10 tamtego pola), żeby wyrenderować się
 * PRZED nim w obrębie tego samego hooka.
 *
 * Przenosiny z ACF (FAZA 20/P-20.7b, D-20.8): meta_key ZOSTAJE publiczny
 * `cena_allegro` (bez podkreślnika, bez migracji danych, wzorem
 * `cena_rynkowa_nowego` po P-13.5, D-13.5.2) — {@see AllegroChannelFields}
 * przestała rejestrować to pole jako ACF; sync
 * (`qutlet-allegro\OfferSync\ProductWriter`) już od P-20.7a (D-20.9) pisze
 * zwykłym `update_post_meta()`/`update_meta_data()`, po nazwie, NIE po
 * kluczu ACF — bezpieczne niezależnie od tego, czy ACF jeszcze widzi to pole.
 *
 * Bezpieczeństwo zapisu: jak {@see \Qutlet\Core\ProductCondition\MarketPriceField}
 * — hook `woocommerce_admin_process_product_object` odpala się już PO
 * weryfikacji nonce'a i uprawnień przez Woo (`WC_Admin_Meta_Boxes::
 * save_meta_boxes()`), więc pole NIE ma własnego nonce'a.
 */
final class AllegroPriceField {

	/**
	 * Meta_key (kontrakt §4, VERBATIM — bez zmian od pola ACF `cena_allegro`).
	 */
	public const META_KEY = 'cena_allegro';

	/**
	 * Priorytet NIŻSZY niż {@see \Qutlet\Core\ProductCondition\MarketPriceField}
	 * (domyślne 10, bez własnej stałej) — render PRZED nim na tym samym hooku
	 * (D-20.8).
	 */
	private const RENDER_PRIORITY = 9;

	/**
	 * Wpina render pola i zapis. Wołane z bootstrapu core (na `plugins_loaded`,
	 * po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', array( self::class, 'render_field' ), self::RENDER_PRIORITY );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
	}

	/**
	 * Renderuje pole w boksie cenowym zakładki General, tuż NAD „Cena rynkowa nowego".
	 *
	 * @return void
	 */
	public static function render_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_KEY,
				'label'       => __( 'Cena Allegro', 'qutlet-core' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Cena kanału Allegro pokazywana na stronie produktu. Nota „Cena wyższa o ~X%" jest liczona przez motyw (kontrakt §6), nie przechowywana.', 'qutlet-core' ),
			)
		);
	}

	/**
	 * Zapisuje cenę Allegro przy zapisie produktu w adminie.
	 *
	 * Puste/nienumeryczne wejście USUWA meta (stan „nie ustawiono" = brak
	 * wpisu — motyw i tak sprawdza tylko obecność wartości, kontrakt §4).
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
