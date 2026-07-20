<?php
/**
 * Slice ProductCondition — rejestracja pól ACF produktu (P-1.2).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Rejestruje grupę pól ACF „stan produktu" na produkcie WooCommerce.
 *
 * Pola (literały z `docs/kontrakt-danych.md` §2 — VERBATIM, case-sensitive):
 * - `klasa_stanu`          — select A/B/C/D, wymagane.
 * - `cena_rynkowa_nowego`  — number (PLN), opcjonalne.
 * - `zawartosc_zestawu`    — WYSIWYG, opcjonalne.
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
						'key'          => 'field_qutlet_cena_rynkowa_nowego',
						'label'        => __( 'Cena rynkowa nowego (PLN)', 'qutlet-core' ),
						'name'         => 'cena_rynkowa_nowego',
						'type'         => 'number',
						'instructions' => __( 'Odniesienie „nowy w sklepach / średnia rynkowa". Puste → motyw ukrywa linię „nowy" i rabat.', 'qutlet-core' ),
						'required'     => 0,
						'min'          => 0,
						'step'         => 'any',
						'append'       => 'zł',
						'placeholder'  => '',
					),
					array(
						'key'          => 'field_qutlet_zawartosc_zestawu',
						'label'        => __( 'Co w przesyłce', 'qutlet-core' ),
						'name'         => 'zawartosc_zestawu',
						'type'         => 'wysiwyg',
						'instructions' => __( 'Ręcznie spisana zawartość zestawu (lista „co otrzymasz"). Puste → motyw nie renderuje zakładki „Co w przesyłce".', 'qutlet-core' ),
						'required'     => 0,
						'tabs'         => 'all',
						'toolbar'      => 'basic',
						'media_upload' => 0,
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
				'description'           => __( 'Pola stanu, ceny odniesienia i zawartości zestawu — rejestruje qutlet-core (P-1.2).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
