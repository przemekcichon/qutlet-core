<?php
/**
 * Slice Pricing — nadpisanie stawki rabatu na produkcie (P-6.1a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\Pricing;

use WC_Product;

/**
 * Pole nadpisania stawki rabatu per produkt (D-6.1.1): zakładka **General**
 * panelu danych produktu WooCommerce, zapis do meta `_qutlet_stawka_rabatu`
 * (kontrakt §11). Puste pole → obowiązuje globalna opcja.
 *
 * To NIE jest pole ACF ani rejestrowane `register_post_meta`: żyje w natywnym
 * panelu danych produktu Woo (jak `_regular_price`), edytowane wyłącznie ręcznie
 * w adminie i NIGDY nie zapisywane przez sync — to nasza decyzja cenowa, nie
 * fakt z Allegro (kontrakt §11).
 *
 * Bezpieczeństwo zapisu: hook `woocommerce_admin_process_product_object` odpala
 * się w `WC_Meta_Box_Product_Data::save()` już PO weryfikacji nonce'a
 * `woocommerce_meta_nonce` (akcja `woocommerce_save_data`) i uprawnień w
 * `WC_Admin_Meta_Boxes::save_meta_boxes()` — zweryfikowane w Woo 10.9.4
 * (`class-wc-admin-meta-boxes.php:228`). Wartość i tak sanityzujemy wspólnym
 * {@see DiscountRate::sanitize_percent()}.
 */
final class ProductDiscountRateField {

	/**
	 * Wpina render pola i zapis. Wołane z bootstrapu core (na `plugins_loaded`,
	 * po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
	}

	/**
	 * Renderuje pole w zakładce General panelu danych produktu.
	 *
	 * @return void
	 */
	public static function render_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => DiscountRate::META_OVERRIDE,
				'label'       => __( 'Stawka rabatu Qutlet (%)', 'qutlet-core' ),
				'desc_tip'    => true,
				'description' => __( 'Nadpisuje globalną stawkę rabatu (WooCommerce → Qutlet — stawka rabatu) dla tego produktu. Puste → używana globalna. Cena sklepu _price jest przeliczana przy imporcie z Allegro.', 'qutlet-core' ),
				'data_type'   => 'decimal',
			)
		);
	}

	/**
	 * Zapisuje nadpisanie stawki przy zapisie produktu w adminie.
	 *
	 * Puste/nienumeryczne wejście USUWA meta (stan „nie ustawiono" = brak wpisu,
	 * a nie pusty string) — dzięki temu `DiscountRate::effective_percent()`
	 * jednoznacznie wraca do globalnej opcji.
	 *
	 * @param WC_Product $product Produkt przetwarzany przez metabox Woo.
	 * @return void
	 */
	public static function save( WC_Product $product ): void {
		// Nonce zweryfikowany przez Woo przed tym hookiem (patrz docblock klasy).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ DiscountRate::META_OVERRIDE ] ) ) {
			return; // Formularz bez pola (np. zapis programowy) — nie ruszamy meta.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw   = sanitize_text_field( wp_unslash( (string) $_POST[ DiscountRate::META_OVERRIDE ] ) );
		$value = DiscountRate::sanitize_percent( $raw );

		if ( '' === $value ) {
			$product->delete_meta_data( DiscountRate::META_OVERRIDE );

			return;
		}

		$product->update_meta_data( DiscountRate::META_OVERRIDE, $value );
	}
}
