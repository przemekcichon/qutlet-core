<?php
/**
 * Slice AllegroChannel — rejestracja pól ACF kanału Allegro (P-1.3).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AllegroChannel;

/**
 * Rejestruje grupę pól ACF „kanał Allegro" na produkcie WooCommerce.
 *
 * Drugi kanał zakupu (tab „Kup przez Allegro" na stronie produktu) — feature
 * PRZEJŚCIOWY (kontrakt §4). Slice `AllegroChannel/` nosi tę samą nazwę w theme
 * (render tabów, FAZA 8) i w allegro (sync, FAZA 6); P-1.3 dotyka wyłącznie core
 * (rejestracja pól).
 *
 * Pola (literały z `docs/kontrakt-danych.md` §4 — VERBATIM, case-sensitive):
 * - `allegro_wlaczone` — true/false, nieopcjonalne (domyślnie false).
 * - `allegro_url`      — url, opcjonalne.
 * - `cena_allegro`     — number (PLN), opcjonalne.
 *
 * Wartości liczone (kontrakt §6) — nie tworzymy dla nich pól: nota „Cena wyższa
 * o ~X%" jest liczona przez motyw z `cena_allegro` vs cena sprzedaży. Korzyści
 * kanału Allegro to statyczna treść szablonu (kontrakt §4), NIE dane produktu.
 *
 * Mechanizm: `acf_add_local_field_group()` w PHP (wzorzec P-1.2). Kod = źródło
 * prawdy; pola są wersjonowane i nie zależą od zapisywalnego folderu acf-json.
 *
 * `name` pola = `meta_key` w bazie — MUSI być zgodne z kontraktem, bo motyw
 * czyta dokładnie ten literał (`get_field()` / `get_post_meta()`).
 */
final class AllegroChannelFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_allegro_channel';

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
				'title'                 => __( 'Qutlet — kanał Allegro', 'qutlet-core' ),
				'fields'                => array(
					array(
						// `allegro_wlaczone` jest zawsze obecne (true_false zwraca 0/1),
						// więc kontraktowe „nieopcjonalne" spełnia domyślna wartość, NIE
						// `required` (required na checkboxie wymusiłoby zaznaczenie = true).
						'key'           => 'field_qutlet_allegro_wlaczone',
						'label'         => __( 'Kanał Allegro włączony', 'qutlet-core' ),
						'name'          => 'allegro_wlaczone',
						'type'          => 'true_false',
						'instructions'  => __( 'Włącza drugi kanał zakupu. Wyłączone → motyw nie renderuje elementów kanału Allegro ([data-allegro-only]).', 'qutlet-core' ),
						'required'      => 0,
						'default_value' => 0,
						'ui'            => 1,
					),
					array(
						'key'          => 'field_qutlet_allegro_url',
						'label'        => __( 'URL oferty Allegro', 'qutlet-core' ),
						'name'         => 'allegro_url',
						'type'         => 'url',
						'instructions' => __( 'Link do oferty na Allegro. Puste → motyw przełącza układ na 2-kolumnowy (.info-2col), bez karty „Zwrot — Allegro".', 'qutlet-core' ),
						'required'     => 0,
						'placeholder'  => '',
					),
					array(
						'key'          => 'field_qutlet_cena_allegro',
						'label'        => __( 'Cena Allegro (PLN)', 'qutlet-core' ),
						'name'         => 'cena_allegro',
						'type'         => 'number',
						'instructions' => __( 'Cena kanału Allegro pokazywana na stronie produktu. Nota „Cena wyższa o ~X%" jest liczona przez motyw (kontrakt §6), nie przechowywana.', 'qutlet-core' ),
						'required'     => 0,
						'min'          => 0,
						'step'         => 'any',
						'append'       => 'zł',
						'placeholder'  => '',
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
				'description'           => __( 'Pola drugiego kanału zakupu (Allegro) — rejestruje qutlet-core (P-1.3). Feature przejściowy.', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
