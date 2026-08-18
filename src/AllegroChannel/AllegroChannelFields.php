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
 * - `allegro_url`      — meta zapisywana przez sync (D-9.1), tu WYŁĄCZNIE
 *   podglądem read-only (typ ACF `message`, FAZA 20/P-20.7b/D-20.10) —
 *   patrz {@see self::FIELD_KEY_ALLEGRO_URL_DISPLAY}.
 *
 * `cena_allegro` NIE jest już tu rejestrowane (FAZA 20/P-20.7b, D-20.8) —
 * przeniesione do natywnej zakładki „Ogólne" Product Data, ten sam meta_key,
 * ten sam mechanizm co `cena_rynkowa_nowego` ({@see
 * \Qutlet\Core\ProductCondition\MarketPriceField}) — patrz {@see AllegroPriceField}.
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
	 * Klucz pola read-only (typ ACF `message`, FAZA 20/P-20.7b/D-20.10) —
	 * podgląd `allegro_url` jako klikalny link. Filtr {@see
	 * self::inject_allegro_url_message()} dopasowuje po TYM kluczu, bo
	 * `acf/pre_render_field` jest GLOBALNY (fires dla KAŻDEGO pola KAŻDEJ
	 * grupy w adminie) — bez tego guardu ingerowałby we wszystkie pola w witrynie.
	 */
	private const FIELD_KEY_ALLEGRO_URL_DISPLAY = 'field_qutlet_allegro_url_display';

	/**
	 * Wpina rejestrację na `acf/init` — moment, w którym ACF jest gotowe na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * `acf/pre_render_field` (FAZA 20/P-20.7b, wzorzec {@see
	 * \Qutlet\Core\ProductCondition\ProductConditionFields::inject_condition_raw_message()})
	 * dopisuje treść pola-komunikatu {@see self::FIELD_KEY_ALLEGRO_URL_DISPLAY}
	 * PRZED renderem.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );
		add_filter( 'acf/pre_render_field', array( self::class, 'inject_allegro_url_message' ), 10, 2 );
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
				'title'                 => __( 'Kanał Allegro', 'qutlet-core' ),
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
						'key'       => self::FIELD_KEY_ALLEGRO_URL_DISPLAY,
						'label'     => __( 'URL oferty Allegro', 'qutlet-core' ),
						'name'      => 'allegro_url_display',
						'type'      => 'message',
						// Treść dopisywana dynamicznie — patrz self::inject_allegro_url_message().
						'message'   => '',
						'new_lines' => '',
						// Link budowany z surowej wartości `allegro_url` — escapujemy przy renderze.
						'esc_html'  => 0,
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

	/**
	 * ID metaboxa renderowanego przez ACF dla tej grupy (`acf-{key}`, wzorzec
	 * potwierdzony w `Acf_Form_Post::add_meta_boxes()`,
	 * `includes/forms/form-post.php` w ACF PRO). Publiczne dla konsumentów
	 * spoza slice'a (P-17.2 — kreator identyfikuje box po DOM id, bez
	 * zgadywania literału).
	 *
	 * @return string
	 */
	public static function metabox_id(): string {
		return 'acf-' . self::GROUP_KEY;
	}

	/**
	 * Dopisuje treść pola-komunikatu {@see self::FIELD_KEY_ALLEGRO_URL_DISPLAY}
	 * TUŻ PRZED renderem (FAZA 20/P-20.7b, D-20.10). Filtr jest GLOBALNY (fires
	 * dla każdego pola ACF w adminie) — pierwszy warunek odsiewa wszystko, co nie
	 * jest naszym polem, więc reszta witryny (inne grupy, options page,
	 * użytkownicy) nie jest tym dotknięta.
	 *
	 * @param array<string,mixed> $field   Definicja pola ACF przed renderem.
	 * @param int|string          $post_id ID kontekstu formularza ACF (tu: ID produktu).
	 * @return array<string,mixed>
	 */
	public static function inject_allegro_url_message( array $field, $post_id ): array {
		if ( self::FIELD_KEY_ALLEGRO_URL_DISPLAY !== ( $field['key'] ?? null ) ) {
			return $field;
		}

		$product_id       = is_numeric( $post_id ) ? (int) $post_id : 0;
		$field['message'] = self::allegro_url_message( $product_id );

		return $field;
	}

	/**
	 * Treść komunikatu: klikalny link do `allegro_url` albo nota o jego braku.
	 *
	 * `allegro_url` jest sync-owned (D-9.1) — zapisuje ją
	 * `qutlet-allegro\OfferSync\ProductWriter` przez `update_post_meta()`
	 * (D-20.9, po nazwie, NIE po kluczu ACF), więc czytamy ją tu tym samym,
	 * zwykłym `get_post_meta()`.
	 *
	 * @param int $product_id ID produktu (0 = brak kontekstu, np. formularz poza ekranem edycji produktu).
	 * @return string
	 */
	private static function allegro_url_message( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return esc_html__( 'Brak kontekstu produktu.', 'qutlet-core' );
		}

		$url = get_post_meta( $product_id, 'allegro_url', true );

		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return esc_html__( 'Brak zapisanego URL-a oferty — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany.', 'qutlet-core' );
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
			esc_url( $url )
		);
	}
}
