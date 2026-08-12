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
 *
 * Render (P-13.6a, D-13.G4 [USTALONE — decyzja użytkownika, sesja 2026-08-12]):
 * grupa NIE renderuje się we własnym metaboksie ACF — {@see self::remove_own_metabox()}
 * zdejmuje go z ekranu edycji produktu. `qutlet-ai` renderuje pole WEWNĄTRZ
 * metaboksu „Qutlet — generacja AI" ({@see \Qutlet\Ai\AiRewrite\GenerationMetaBox},
 * P-13.6b), wołając publiczną metodę {@see self::render_field()} — NIE funkcje ACF
 * bezpośrednio. Powód: `qutlet-ai` ma twardą zależność WYŁĄCZNIE na core + Woo
 * (D-G5), nie na ACF Pro (patrz komentarz `PromptSettings` — cross-plugin odczyt
 * idzie przez `get_post_meta()`, nie `get_field()`, z tego samego powodu); gdyby
 * `qutlet-ai` wołał `acf_render_field()`/`get_field_object()` samodzielnie, ACF
 * stałby się niezadeklarowaną twardą zależnością (fatal przy wyłączonym ACF).
 * Rejestracja I render zostają więc w core — `qutlet-ai` woła metodę tej klasy,
 * wzorem już istniejącego bezpośredniego użycia `Qutlet\Core\ProductInfo\RawLayerMeta`
 * w `GenerationMetaBox` (D-7.G6 nienaruszona: core rejestruje ACF, ai nie).
 */
final class PromptOverrideField {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_ai_rewrite';

	/**
	 * Ekran (typ posta), na którym pole żyje — produkt WooCommerce. Też ekran,
	 * z którego zdejmujemy własny metabox ACF (patrz {@see self::remove_own_metabox()}).
	 */
	private const SCREEN = 'product';

	/**
	 * Wpina rejestrację na `acf/init` — moment, w którym ACF jest gotowe na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );

		// Priorytet 20 — PO domyślnym (10) hooku, na którym ACF rejestruje
		// WŁASNE metaboxy (`ACF_Form_Post::add_meta_boxes()`, wpięte w
		// `initialize()` na `add_meta_boxes` priorytet 10, sprawdzone w
		// `advanced-custom-fields-pro/includes/forms/form-post.php`) — żeby
		// metabox ACF istniał w `$wp_meta_boxes` w momencie, w którym go
		// zdejmujemy (zdjęcie PRZED dodaniem byłoby no-opem).
		add_action( 'add_meta_boxes', array( self::class, 'remove_own_metabox' ), 20 );
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
							'value'    => self::SCREEN,
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => __( 'Override promptu AI per produkt — rejestruje qutlet-core (P-7.2a). Render — patrz self::render_field() / GenerationMetaBox w qutlet-ai (P-13.6a/b, D-13.G4).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}

	/**
	 * Zdejmuje z ekranu edycji produktu metabox, który ACF automatycznie tworzy
	 * dla grupy `self::GROUP_KEY` (ID `acf-{key grupy}`, `add_meta_box()` w
	 * `ACF_Form_Post::add_meta_boxes()` — ustalone czytaniem
	 * `advanced-custom-fields-pro/includes/forms/form-post.php`). Zdjęcie
	 * metaboxa NIE wpływa na zapis: `ACF_Form_Post::save_post()` wisi na osobnym
	 * hooku (`save_post`, wpiętym w konstruktorze, niezależnie od ekranu) i sam
	 * odnajduje grupy pól właściwe dla zapisywanego posta przez
	 * `acf_get_field_groups()` (dopasowanie po `location`, nie po tym, czy
	 * metabox się kiedykolwiek wyrenderował) — pole nadal się zapisuje, mimo że
	 * jego własny box nie istnieje. Render przenosi się do `qutlet-ai`
	 * ({@see self::render_field()}, P-13.6a/D-13.G4).
	 *
	 * @param string $post_type Typ posta bieżącego ekranu edycji.
	 * @return void
	 */
	public static function remove_own_metabox( string $post_type ): void {
		if ( self::SCREEN !== $post_type ) {
			return;
		}

		remove_meta_box( 'acf-' . self::GROUP_KEY, self::SCREEN, 'normal' );
	}

	/**
	 * Renderuje pole `prompt_ai` (edytowalny input, z etykietą i instrukcją) w
	 * miejscu wywołania — dziś z metaboksu `qutlet-ai` „Qutlet — generacja AI"
	 * ({@see \Qutlet\Ai\AiRewrite\GenerationMetaBox}, P-13.6b). Ten sam mechanizm,
	 * którym ACF renderuje pola we WŁASNYM metaboksie (`acf_get_fields()` +
	 * `acf_render_fields()`, wzorzec 1:1 z `ACF_Form_Post::render_meta_box()`) —
	 * jedyna różnica to wywołujący metabox, nie sposób renderu. Zapis
	 * (`$_POST['acf'][…]`) działa identycznie niezależnie od tego, który metabox
	 * pole wyrenderował (patrz {@see self::remove_own_metabox()}).
	 *
	 * Publiczna metoda, NIE hook WP (D-13.G4 [USTALONE] — decyzja użytkownika,
	 * sesja 2026-08-12): `qutlet-ai` importuje tę klasę i woła metodę wprost,
	 * wzorem już istniejącego bezpośredniego użycia `Qutlet\Core\ProductInfo\RawLayerMeta`
	 * w `GenerationMetaBox` — `qutlet-ai` i tak hard-dependuje na `qutlet-core`
	 * (D-G5), więc bezpośrednie wywołanie klasy nie jest nowym rodzajem sprzężenia.
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	public static function render_field( int $product_id ): void {
		$fields = acf_get_fields( self::GROUP_KEY );

		if ( array() === $fields ) {
			return;
		}

		acf_render_fields( $fields, $product_id, 'div', 'label' );
	}
}
