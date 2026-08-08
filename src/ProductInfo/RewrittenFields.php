<?php
/**
 * Slice ProductInfo — rejestracja warstwy PRZEROBIONEJ produktu (P-5.1b; pole podnazwa —
 * P-13.2a-core; pole opis wycofane — P-13.3a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductInfo;

/**
 * Rejestruje pola WARSTWY PRZEROBIONEJ na produkcie WooCommerce (`post_type == product`).
 *
 * Warstwa przerobiona = to, co ostatecznie widać na stronie produktu Qutlet (D-5.G4).
 * Powstaje z warstwy surowej przez AI (FAZA 7) + ręczną redakcję i — w odróżnieniu od
 * surowej — NIGDY nie jest nadpisywana przez sync z Allegro. Motyw czyta WYŁĄCZNIE tę
 * warstwę (D-8.G1).
 *
 * Zakres rejestracji (kontrakt §9.2):
 * - `podnazwa` — pole ACF text (jedna linia): druga część nazwy, gdy AI rozbije zbyt
 *   długą oryginalną nazwę Allegro (`RawLayerMeta::META_NAME_RAW`) na tytuł
 *   (→ `post_title`) + podnazwę (FAZA 13, P-13.2c). Odczyt motywu: `get_field('podnazwa')`.
 *
 * Opis (przerobiony) NIE jest już tu rejestrowany (P-13.3a) — cel zapisu/odczytu opisu
 * to natywne `post_content` (`the_content()` / `$post->post_content`), nie ACF. Pole ACF
 * `opis` (`field_qutlet_opis`) istniało do P-13.3a; istniejące wartości migruje jednorazowa
 * komenda WP-CLI {@see BackfillOpisToContentCommand} (D-13.G3). Motyw (`qutlet-theme`,
 * `ProductPage`) przechodzi z `get_field('opis')` na natywny opis osobnym, zależnym
 * punktem (P-13.3c).
 *
 * Specyfikacja przerobiona = **natywne atrybuty produktu WooCommerce**
 * (`_product_attributes`) — glue/sync je zapisuje, motyw renderuje natywnie; core NIE
 * rejestruje dla niej pola (D-5.1.1). Atrybuty WC są z natury front-facing, więc trzymają
 * tylko warstwę przerobioną; surowa specyfikacja jest osobnym prywatnym meta (D-5.1.2,
 * `RawLayerMeta::META_SPECIFICATION_RAW`).
 *
 * Mechanizm: `acf_add_local_field_group()` w PHP (wzorzec P-1.2 / P-1.3). Kod = źródło
 * prawdy; pole wersjonowane, niezależne od zapisywalnego folderu acf-json. `name` pola =
 * `meta_key` w bazie — MUSI być zgodne z kontraktem (motyw czyta ten literał).
 */
final class RewrittenFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_product_info';

	/**
	 * Wpina rejestrację na `acf/init` — moment gotowości ACF na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );
	}

	/**
	 * Rejestruje grupę pól ACF warstwy przerobionej na produkcie.
	 *
	 * @return void
	 */
	public static function register(): void {
		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP_KEY,
				'title'                 => __( 'Qutlet — nazwa produktu (warstwa przerobiona)', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => 'field_qutlet_podnazwa',
						'label'        => __( 'Podnazwa', 'qutlet-core' ),
						'name'         => 'podnazwa',
						'type'         => 'text',
						'instructions' => __( 'Druga część nazwy, gdy AI rozbije zbyt długą oryginalną nazwę Allegro na tytuł (post_title) + podnazwę. Redagowalna ręcznie; sync z Allegro jej NIE nadpisuje. Puste → motyw pokazuje sam tytuł.', 'qutlet-core' ),
						'required'     => 0,
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'product',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => __( 'Warstwa przerobiona (user-facing) nazwy produktu — rejestruje qutlet-core (P-13.2a-core). Opis (przerobiony) to natywne post_content (P-13.3a); specyfikacja przerobiona = natywne atrybuty WooCommerce.', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
