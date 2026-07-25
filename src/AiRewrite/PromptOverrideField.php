<?php
/**
 * Slice AiRewrite — rejestracja pola override promptu AI (P-7.2a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AiRewrite;

/**
 * Rejestruje grupę pól ACF „prompt AI" (override per produkt) na produkcie
 * WooCommerce.
 *
 * Granica D-7.G6: rejestrację pól ACF/CPT robi wyłącznie `qutlet-core` — logika
 * przeróbki AI (odczyt promptu, wywołanie core AI Client) mieszka w `qutlet-ai`,
 * slice `AiRewrite/` o tej samej nazwie (feature rozproszony). To pole jest
 * opcjonalnym nadpisaniem globalnego promptu (ustawienie w `qutlet-ai`, P-7.2b,
 * D-7.G4) — puste pole → `qutlet-ai` używa promptu globalnego.
 *
 * Pole (literał z `docs/kontrakt-danych.md` §13 — VERBATIM, case-sensitive):
 * - `prompt_ai` — textarea (plain text), opcjonalne.
 *
 * Mechanizm: `acf_add_local_field_group()` (wzorzec `ProductConditionFields` /
 * `AllegroChannelFields` / `RewrittenFields`, D-7.2a.1) — pole edytowane ręcznie
 * w adminie, NIE fakt z Allegro nadpisywany syncem, więc NIE `register_post_meta`
 * prywatne (jak warstwa surowa §9.1/§10.1). Typ `textarea`, nie WYSIWYG jak `opis`
 * (§9.2) — to instrukcja tekstowa dla modelu, nie treść user-facing.
 *
 * `name` pola = `meta_key` w bazie — MUSI być zgodne z kontraktem, bo `qutlet-ai`
 * czyta dokładnie ten literał (`get_field()` / `get_post_meta()`).
 */
final class PromptOverrideField {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_ai_rewrite';

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
				'title'                 => __( 'Qutlet — prompt AI (nadpisanie per produkt)', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => 'field_qutlet_prompt_ai',
						'label'        => __( 'Prompt AI (nadpisanie)', 'qutlet-core' ),
						'name'         => 'prompt_ai',
						'type'         => 'textarea',
						'instructions' => __( 'Nadpisuje globalny prompt (ustawienie qutlet-ai) dla tego produktu przy generowaniu przerobionego opisu. Puste → używany prompt globalny.', 'qutlet-core' ),
						'required'     => 0,
						'rows'         => 6,
						'new_lines'    => '',
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
				'description'           => __( 'Override promptu AI per produkt — rejestruje qutlet-core (P-7.2a). Logikę generacji (odczyt, wywołanie AI Client) niesie qutlet-ai (P-7.2b/P-7.3).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
