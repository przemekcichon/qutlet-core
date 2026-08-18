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
 *
 * Render (P-20.4a, D-20.3/D-20.G3): grupa NIE renderuje się we własnym metaboksie ACF —
 * {@see self::remove_own_metabox()} zdejmuje go z ekranu edycji produktu (wzorzec 1:1
 * {@see \Qutlet\Core\AiRewrite\PromptOverrideField::remove_own_metabox()}, D-13.6.1).
 * `qutlet-ai` renderuje pole WEWNĄTRZ scalonego metaboksu „Nazwa produktu (AI)"
 * ({@see \Qutlet\Ai\AiRewrite\TitleGenerationMetaBox}, P-20.4b), wołając publiczną
 * {@see self::render_field()} — NIE funkcje ACF bezpośrednio, z tego samego powodu co
 * `PromptOverrideField` (`qutlet-ai` bez twardej zależności na ACF Pro, D-G5).
 */
final class RewrittenFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_product_info';

	/**
	 * Ekran (typ posta), na którym pole żyje — produkt WooCommerce. Też ekran,
	 * z którego zdejmujemy własny metabox ACF (patrz {@see self::remove_own_metabox()}).
	 */
	private const SCREEN = 'product';

	/**
	 * Wpina rejestrację na `acf/init` — moment gotowości ACF na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );

		// Priorytet 20 — PO domyślnym (10) hooku, na którym ACF rejestruje WŁASNE
		// metaboxy (`ACF_Form_Post::add_meta_boxes()`), wzorem
		// `PromptOverrideField::init()` (D-13.6.1) — metabox ACF musi istnieć w
		// `$wp_meta_boxes` w momencie, w którym go zdejmujemy.
		add_action( 'add_meta_boxes', array( self::class, 'remove_own_metabox' ), 20 );
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
						'label'        => __( 'Druga linia nazwy produktu', 'qutlet-core' ),
						'name'         => 'podnazwa',
						'type'         => 'text',
						'instructions' => __( 'Druga linia nazwy, gdy AI rozbije zbyt długą oryginalną nazwę Allegro na tytuł (post_title) + tę drugą linię. Redagowalna ręcznie; sync z Allegro jej NIE nadpisuje. Puste → motyw pokazuje sam tytuł.', 'qutlet-core' ),
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

	/**
	 * Zdejmuje z ekranu edycji produktu metabox, który ACF automatycznie tworzy
	 * dla grupy `self::GROUP_KEY` (ID `acf-{key grupy}`, `add_meta_box()` w
	 * `ACF_Form_Post::add_meta_boxes()`) — wzorzec 1:1
	 * {@see \Qutlet\Core\AiRewrite\PromptOverrideField::remove_own_metabox()}
	 * (D-13.6.1). Zdjęcie metaboxa NIE wpływa na zapis — patrz uzasadnienie w
	 * `PromptOverrideField`. Render przenosi się do `qutlet-ai`
	 * ({@see self::render_field()}, P-20.4a/P-20.4b, D-20.3).
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
	 * Renderuje pole `podnazwa` (edytowalny input, z etykietą i instrukcją) w
	 * miejscu wywołania — dziś z metaboksu `qutlet-ai` „Nazwa produktu (AI)"
	 * ({@see \Qutlet\Ai\AiRewrite\TitleGenerationMetaBox}, P-20.4b). Wzorzec 1:1
	 * {@see \Qutlet\Core\AiRewrite\PromptOverrideField::render_field()} — patrz
	 * tamten docblock po pełne uzasadnienie mechanizmu i `function_exists()`
	 * guard.
	 *
	 * Publiczna metoda, NIE hook WP — `qutlet-ai` importuje tę klasę i woła
	 * metodę wprost (hard-dependuje na core, D-G5).
	 *
	 * @param int $product_id ID produktu.
	 * @return void
	 */
	public static function render_field( int $product_id ): void {
		if ( ! function_exists( 'acf_get_fields' ) || ! function_exists( 'acf_render_fields' ) ) {
			return;
		}

		$field_group = acf_get_field_group( self::GROUP_KEY );
		$fields      = acf_get_fields( self::GROUP_KEY );

		if ( array() === $fields ) {
			return;
		}

		$instruction_placement = is_array( $field_group ) && isset( $field_group['instruction_placement'] )
			? (string) $field_group['instruction_placement']
			: 'label';

		acf_render_fields( $fields, $product_id, 'div', $instruction_placement );
	}
}
