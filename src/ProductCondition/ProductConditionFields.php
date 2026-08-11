<?php
/**
 * Slice ProductCondition — rejestracja pól ACF produktu (P-1.2, P-9.2).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Rejestruje grupę pól ACF „stan produktu" na produkcie WooCommerce.
 *
 * Pola (literały z `docs/kontrakt-danych.md` §2 — VERBATIM, case-sensitive):
 * - `klasa_stanu`                 — select A/B/C/D, wymagane.
 * - `zawartosc_zestawu_pozycje`   — repeater (sub-pola `zdjecie`/`etykieta`/
 *   `w_zestawie`), opcjonalne. Zastępuje pole WYSIWYG `zawartosc_zestawu`
 *   z P-1.2 (D-9.2.1) — ground-truth P-8.2c ujawnił, że WYSIWYG
 *   (`media_upload=0`, `toolbar=basic`) nie udźwignie ani zdjęć karuzeli, ani
 *   struktury pozycja+flaga wymaganej przez `.ship-grid` (`produkt.html:142-173`).
 *   Stare pole nigdy nie miało danych (produkt niewystawiony) — bez migracji.
 *
 * `cena_rynkowa_nowego` NIE jest już tu rejestrowane (P-13.5, REWIZJA
 * P-1.2/P-9.2) — przeniesione do natywnej zakładki „Ogólne" Product Data,
 * ten sam meta_key, ten sam mechanizm co `_qutlet_stawka_rabatu` ({@see
 * \Qutlet\Core\ProductCondition\MarketPriceField}).
 *
 * Mechanizm: `acf_add_local_field_group()` w PHP (decyzja P-1.2). Kod = źródło
 * prawdy; pola są wersjonowane i nie zależą od zapisywalnego folderu acf-json.
 * Wzorzec dla kolejnych slice'ów rejestrujących pola (AllegroChannel, AiRewrite).
 *
 * `name` pola = `meta_key` w bazie — MUSI być zgodne z kontraktem, bo motyw
 * czyta dokładnie ten literał (`get_field()` / `get_post_meta()`).
 */
final class ProductConditionFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_product_condition';

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
				'title'                 => __( 'Qutlet — stan i zawartość produktu', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => 'field_qutlet_klasa_stanu',
						'label'        => __( 'Klasa stanu', 'qutlet-core' ),
						'name'         => 'klasa_stanu',
						'type'         => 'select',
						'instructions' => __( 'Ocena stanu egzemplarza. Motyw zamienia literę na etykietę.', 'qutlet-core' ),
						'required'     => 1,
						// Wartości (literały A/B/C/D) → etykiety wg kontraktu §2 (data.js QT.COND).
						'choices'      => array(
							'A' => __( 'Jak nowy', 'qutlet-core' ),
							'B' => __( 'Dobry', 'qutlet-core' ),
							'C' => __( 'Mocne ślady', 'qutlet-core' ),
							'D' => __( 'Na części', 'qutlet-core' ),
						),
						'default_value' => '',
						'allow_null'   => 0,
						'multiple'     => 0,
						'ui'           => 0,
						'ajax'         => 0,
						// Motyw dostaje literał (A/B/C/D) i sam mapuje na etykietę (kontrakt §6).
						'return_format' => 'value',
					),
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
				'description'           => __( 'Pola stanu i zawartości zestawu — rejestruje qutlet-core (P-1.2).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
