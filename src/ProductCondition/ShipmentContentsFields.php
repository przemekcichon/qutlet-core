<?php
/**
 * Slice ProductCondition — rejestracja pól ACF „Zawartość przesyłki" (P-20.8).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Rejestruje osobną grupę pól ACF „Zawartość przesyłki" na produkcie
 * WooCommerce — wydzieloną z {@see ProductConditionFields} (D-20.11,
 * `docs/plan.md` P-20.8): jedyne pole `zawartosc_zestawu_pozycje` (repeater,
 * sub-pola `zdjecie`/`etykieta`/`w_zestawie`), TEN SAM `key`/`name`/sub-pola co
 * dotąd — bez migracji danych (grep potwierdza zero zapisów cross-plugin do
 * tego pola, `docs/kontrakt-danych.md` §2).
 */
final class ShipmentContentsFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_shipment_contents';

	/**
	 * Wpina rejestrację na `acf/init` — moment, w którym ACF jest gotowe na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );
	}

	/**
	 * Rejestruje grupę pól ACF na produkcie (`post_type == product`).
	 *
	 * @return void
	 */
	public static function register(): void {
		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP_KEY,
				'title'                 => __( 'Zawartość przesyłki', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'           => 'field_qutlet_zawartosc_zestawu_pozycje',
						'label'         => __( 'Co w przesyłce', 'qutlet-core' ),
						'name'          => 'zawartosc_zestawu_pozycje',
						'type'          => 'repeater',
						'instructions'  => __( 'Pozycje zestawu — jeden wiersz = jedna pozycja. Zdjęcie zasila karuzelę „Co w przesyłce" (brak zdjęcia → pozycja trafia tylko do checklisty). Pusty repeater → motyw nie renderuje zakładki „Co w przesyłce".', 'qutlet-core' ),
						'required'      => 0,
						'layout'        => 'table',
						'button_label'  => __( 'Dodaj pozycję', 'qutlet-core' ),
						'sub_fields'    => array(
							array(
								'key'           => 'field_qutlet_zawartosc_zestawu_zdjecie',
								'label'         => __( 'Zdjęcie', 'qutlet-core' ),
								'name'          => 'zdjecie',
								'type'          => 'image',
								'instructions'  => __( 'Opcjonalne — bez zdjęcia pozycja pojawia się tylko w checkliście, nie w karuzeli.', 'qutlet-core' ),
								'required'      => 0,
								'return_format' => 'id',
								'preview_size'  => 'thumbnail',
								'library'       => 'all',
							),
							array(
								'key'          => 'field_qutlet_zawartosc_zestawu_etykieta',
								'label'        => __( 'Etykieta', 'qutlet-core' ),
								'name'         => 'etykieta',
								'type'         => 'text',
								'instructions' => '',
								'required'     => 1,
							),
							array(
								'key'           => 'field_qutlet_zawartosc_zestawu_w_zestawie',
								'label'         => __( 'W zestawie', 'qutlet-core' ),
								'name'          => 'w_zestawie',
								'type'          => 'true_false',
								'instructions'  => __( 'Zaznaczone = pozycja jest w zestawie (ikona check); odznaczone = brakuje (ikona cross).', 'qutlet-core' ),
								'required'      => 0,
								'default_value' => 1,
								'ui'            => 1,
							),
						),
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
				'description'           => __( 'Zawartość przesyłki (pozycje zestawu) — rejestruje qutlet-core (P-20.8).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}

	/**
	 * ID metaboxa renderowanego przez ACF dla tej grupy (`acf-{key}`, wzorzec
	 * potwierdzony w `Acf_Form_Post::add_meta_boxes()`,
	 * `includes/forms/form-post.php` w ACF PRO). Publiczne dla konsumentów
	 * spoza slice'a (P-17.2 — kreator identyfikuje box po DOM id, bez
	 * zgadywania literału, wzorem {@see ProductConditionFields::metabox_id()}).
	 *
	 * @return string
	 */
	public static function metabox_id(): string {
		return 'acf-' . self::GROUP_KEY;
	}
}
