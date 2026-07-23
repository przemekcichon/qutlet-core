<?php
/**
 * Slice ProductInfo — rejestracja warstwy PRZEROBIONEJ produktu (P-5.1b).
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
 * - `opis` — pole ACF WYSIWYG (rich text): user-facing opis produktu. Odczyt motywu:
 *   `get_field('opis')` / `get_post_meta($id, 'opis', true)`. To NIE natywny opis Woo
 *   (`post_content`) — front czyta to pole (warstwa przerobiona).
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
				'title'                 => __( 'Qutlet — opis produktu (warstwa przerobiona)', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => 'field_qutlet_opis',
						'label'        => __( 'Opis (na stronie produktu)', 'qutlet-core' ),
						'name'         => 'opis',
						'type'         => 'wysiwyg',
						'instructions' => __( 'Finalny opis pokazywany klientowi. Wypełniany przez AI (przeróbka opisu z Allegro) i redagowany ręcznie; sync z Allegro go NIE nadpisuje. Puste → motyw stosuje fallback.', 'qutlet-core' ),
						'required'     => 0,
						'tabs'         => 'all',
						'toolbar'      => 'full',
						'media_upload' => 1,
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
				'description'           => __( 'Warstwa przerobiona (user-facing) opisu produktu — rejestruje qutlet-core (P-5.1b). Specyfikacja przerobiona = natywne atrybuty WooCommerce.', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
