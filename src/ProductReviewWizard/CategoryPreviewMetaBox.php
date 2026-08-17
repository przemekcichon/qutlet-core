<?php
/**
 * Slice ProductReviewWizard — podgląd kategorii Allegro, krok 6 kreatora (P-17.2, D-17.3).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductReviewWizard;

use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use WP_Post;

/**
 * Metabox READ-ONLY pokazujący wynik automatycznego mapowania kategorii
 * (D-17.3): term `product_cat` przypisany produktowi + ścieżka źródłowa
 * Allegro ({@see AllegroLinkMeta::META_CATEGORY_PATH}, liść→korzeń, kontrakt
 * §10.1). Mapowanie samo jest w pełni automatyczne przy sync
 * (`qutlet-allegro\OfferSync\ProductWriter::upsert()`) — ten box tylko
 * POKAZUJE wynik, bez żadnej ścieżki edycji/zapisu (odrzucona alternatywa w
 * D-17.3: korekta ręczna w kreatorze, poza zakresem tej fazy).
 *
 * Krok 6 kreatora ({@see ProductReviewWizard}) fizycznie przenosi ten box do
 * karty kreatora po DOM id ({@see self::META_BOX_ID}) — poza kreatorem stoi
 * jako zwykły, mały box informacyjny w bocznej kolumnie ekranu edycji, wzorem
 * {@see \Qutlet\Core\ProductInfo\RawLayerMetaBox} (podgląd read-only, bez
 * formularza/nonce'a/zapisu).
 */
final class CategoryPreviewMetaBox {

	/**
	 * Ekran (typ posta), na którym pokazujemy podgląd — produkt WooCommerce.
	 */
	private const SCREEN = 'product';

	/**
	 * Identyfikator metaboxa (unikalny w obrębie ekranu) — publiczny, bo
	 * kreator ({@see ProductReviewWizard}) identyfikuje ten box po DOM id.
	 */
	public const META_BOX_ID = 'qutlet_wizard_category_preview';

	/**
	 * Wpina rejestrację metaboxa na `add_meta_boxes`. Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
	}

	/**
	 * Rejestruje metabox tylko dla ekranu edycji produktu, w bocznej kolumnie
	 * (mały panel informacyjny, priorytet niski — nie konkuruje o uwagę z
	 * metaboxami akcji).
	 *
	 * @param string $post_type Typ posta bieżącego ekranu edycji.
	 * @return void
	 */
	public static function register( string $post_type ): void {
		if ( self::SCREEN !== $post_type ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Qutlet — kategoria (podgląd)', 'qutlet-core' ),
			array( self::class, 'render' ),
			self::SCREEN,
			'side',
			'low'
		);
	}

	/**
	 * Renderuje podgląd: przypisany term `product_cat` + ścieżka kategorii
	 * Allegro (liść→korzeń w meta, wyświetlana korzeń→liść jako naturalniejszy
	 * kierunek czytania breadcrumbów).
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		printf( '<h4 style="margin-top:0">%s</h4>', esc_html__( 'Kategoria produktu (Woo)', 'qutlet-core' ) );

		$terms = get_the_terms( $post->ID, 'product_cat' );

		if ( ! is_array( $terms ) || array() === $terms ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Brak przypisanej kategorii.', 'qutlet-core' ) );
		} else {
			printf( '<p>%s</p>', esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) );
		}

		printf( '<h4>%s</h4>', esc_html__( 'Ścieżka kategorii Allegro (źródło mapowania)', 'qutlet-core' ) );

		$path = get_post_meta( $post->ID, AllegroLinkMeta::META_CATEGORY_PATH, true );

		if ( ! is_array( $path ) || array() === $path ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Brak ścieżki kategorii — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany.', 'qutlet-core' )
			);

			return;
		}

		$labels = array();

		foreach ( array_reverse( $path ) as $node ) {
			if ( is_array( $node ) && isset( $node['name'] ) && '' !== trim( (string) $node['name'] ) ) {
				$labels[] = (string) $node['name'];
			}
		}

		printf(
			'<p style="word-break:break-word">%s</p>',
			esc_html( implode( ' → ', $labels ) )
		);
	}
}
