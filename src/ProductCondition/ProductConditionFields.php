<?php
/**
 * Slice ProductCondition — rejestracja pól ACF produktu (P-1.2, P-9.2).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

use Qutlet\Core\ProductInfo\RawLayerMeta;
use WP_Screen;

/**
 * Rejestruje grupę pól ACF „stan produktu" na produkcie WooCommerce.
 *
 * Pola (literały z `docs/kontrakt-danych.md` §2 — VERBATIM, case-sensitive):
 * - (message, read-only) `Stan wg Allegro (surowy, tylko do odczytu)` — P-13.7a.
 *   Nie zapisuje żadnej wartości (typ ACF `message`); treść dopisywana
 *   dynamicznie na `acf/pre_render_field` ({@see self::inject_condition_raw_message()}).
 *   Ekstrakcja z `_qutlet_allegro_offer` (offer-level `parameters[]`,
 *   `name === 'Stan'`, `.values[0]`) MIRRORuje `Qutlet\Allegro\OfferSync\
 *   OfferMapper::condition_raw()`/`parameter_value()` (kontrakt §9.1, plan
 *   P-13.7a) — core NIE importuje klas z `qutlet-allegro` (granica repo,
 *   `CLAUDE.md` §Struktura), więc ta mała ekstrakcja jest zduplikowana
 *   intencjonalnie; jeśli mapping „Stan" w allegro się zmieni, zsynchronizuj tu.
 * - `klasa_stanu`                 — select, wymagane. `choices` NIE są już
 *   hardkodowane tu (REWIZJA P-12.1a/D-1.2.1) — budowane dynamicznie na
 *   `acf/load_field` z {@see ClassDefinitionsTaxonomy}, rozszerzalnego bytu
 *   (taksonomia `klasa_stanu_definicja` + term meta), żeby dodanie nowej klasy
 *   (D-12.G1) nie wymagało zmiany kodu. Sam mechanizm zapisu (ACF select →
 *   plain postmeta, wartość = literał typu `A`/`B`/`C`/`D`) jest BEZ ZMIAN —
 *   `qutlet-allegro`/`qutlet-theme` (poza zakresem tej sesji) czytają/piszą
 *   ten literał i nie wymagają żadnej modyfikacji (patrz docblock
 *   {@see ClassDefinitionsTaxonomy}, decyzja użytkownika o zachowaniu kontraktu
 *   wstecz, sesja 2026-08-12).
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
	 * Klucz pola read-only (typ ACF `message`, P-13.7a) — surowy podgląd
	 * parametru „Stan" z Allegro. Filtr {@see self::inject_condition_raw_message()}
	 * dopasowuje po TYM kluczu, bo `acf/pre_render_field` jest GLOBALNY (fires dla
	 * KAŻDEGO pola KAŻDEJ grupy w adminie) — bez tego guardu ingerowałby we
	 * wszystkie pola w witrynie.
	 */
	private const FIELD_KEY_ALLEGRO_STAN_RAW = 'field_qutlet_allegro_stan_raw';

	/**
	 * Klucz pola `klasa_stanu` (P-12.1a) — `acf/load_field` dopasowuje po tym
	 * kluczu, żeby dobudować `choices` z {@see ClassDefinitionsTaxonomy} bez
	 * ingerowania w inne pola select w witrynie (filtr GLOBALNY, jak przy
	 * {@see self::FIELD_KEY_ALLEGRO_STAN_RAW}).
	 */
	private const FIELD_KEY_KLASA_STANU = 'field_qutlet_klasa_stanu';

	/**
	 * Wpina rejestrację na `acf/init` — moment, w którym ACF jest gotowe na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * `acf/pre_render_field` (P-13.7a) dopisuje treść pola-komunikatu
	 * {@see self::FIELD_KEY_ALLEGRO_STAN_RAW} PRZED renderem — jedyny hook ACF,
	 * który dostaje `$post_id` wprost jako argument (`acf_render_fields()`), więc
	 * nie trzeba zgadywać ID produktu z globalnego stanu.
	 *
	 * `acf/load_field` (P-12.1a) dobudowuje `choices` pola `klasa_stanu` z bytu
	 * {@see ClassDefinitionsTaxonomy} — odpalane przy KAŻDYM ładowaniu definicji
	 * pola (ekran edycji produktu, walidacja zapisu), więc nowo dodana klasa
	 * (D-12.G1) jest widoczna od razu, bez cache'owania.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );
		add_filter( 'acf/pre_render_field', array( self::class, 'inject_condition_raw_message' ), 10, 2 );
		add_filter( 'acf/load_field/key=' . self::FIELD_KEY_KLASA_STANU, array( self::class, 'inject_dynamic_choices' ) );
		add_action( 'admin_notices', array( self::class, 'render_missing_class_definitions_notice' ) );
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
						'key'          => self::FIELD_KEY_ALLEGRO_STAN_RAW,
						'label'        => __( 'Stan wg Allegro (surowy, tylko do odczytu)', 'qutlet-core' ),
						'name'         => 'allegro_stan_raw_display',
						'type'         => 'message',
						// Treść dopisywana dynamicznie — patrz self::inject_condition_raw_message().
						'message'      => '',
						'new_lines'    => '',
						// Wartość zewnętrzna (Allegro) — escapujemy przy renderze, nie ufamy jej ślepo.
						'esc_html'     => 1,
					),
					array(
						'key'          => self::FIELD_KEY_KLASA_STANU,
						'label'        => __( 'Klasa stanu', 'qutlet-core' ),
						'name'         => 'klasa_stanu',
						'type'         => 'select',
						'instructions' => __( 'Ocena stanu egzemplarza. Motyw zamienia literę na etykietę.', 'qutlet-core' ),
						'required'     => 1,
						// `choices` NIE są tu statyczne — dobudowywane na acf/load_field
						// przez self::inject_dynamic_choices() z ClassDefinitionsTaxonomy.
						// Puste tu naumyślnie: brak duplikowania danych bytu w kodzie.
						'choices'      => array(),
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

	/**
	 * Dopisuje treść pola-komunikatu {@see self::FIELD_KEY_ALLEGRO_STAN_RAW}
	 * TUŻ PRZED renderem (P-13.7a). Filtr jest GLOBALNY (fires dla każdego pola
	 * ACF w adminie) — pierwszy warunek odsiewa wszystko, co nie jest naszym
	 * polem, więc reszta witryny (inne grupy, options page, użytkownicy) nie
	 * jest tym dotknięta.
	 *
	 * @param array<string,mixed> $field   Definicja pola ACF przed renderem.
	 * @param int|string          $post_id ID kontekstu formularza ACF (tu: ID produktu).
	 * @return array<string,mixed>
	 */
	public static function inject_condition_raw_message( array $field, $post_id ): array {
		if ( self::FIELD_KEY_ALLEGRO_STAN_RAW !== ( $field['key'] ?? null ) ) {
			return $field;
		}

		$product_id        = is_numeric( $post_id ) ? (int) $post_id : 0;
		$field['message']  = self::condition_raw_message( $product_id );

		return $field;
	}

	/**
	 * Treść komunikatu: surowa wartość „Stan" albo nota o jej braku.
	 *
	 * @param int $product_id ID produktu (0 = brak kontekstu, np. formularz poza ekranem edycji produktu).
	 * @return string
	 */
	private static function condition_raw_message( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return __( 'Brak kontekstu produktu.', 'qutlet-core' );
		}

		$raw = self::condition_raw_from_offer( $product_id );

		if ( null === $raw ) {
			return __( 'Brak parametru „Stan" w ofercie — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany.', 'qutlet-core' );
		}

		return sprintf(
			/* translators: %s: surowa wartość parametru „Stan" z Allegro (np. „Nowy z defektem"). */
			__( 'Allegro zwraca: „%s"', 'qutlet-core' ),
			$raw
		);
	}

	/**
	 * Surowa wartość offer-level parametru „Stan" z `_qutlet_allegro_offer`
	 * (kontrakt §9.1). Ekstrakcja MIRRORuje `Qutlet\Allegro\OfferSync\
	 * OfferMapper::offer_parameters()`/`parameter_value()` (nazwa dopasowana
	 * ściśle, pierwsza wartość `values[0]`, trim) — patrz docblock klasy, czemu
	 * to zduplikowane tu, nie zaimportowane z `qutlet-allegro`.
	 *
	 * @param int $product_id ID produktu.
	 * @return string|null
	 */
	private static function condition_raw_from_offer( int $product_id ): ?string {
		$offer_json = get_post_meta( $product_id, RawLayerMeta::META_OFFER, true );

		if ( ! is_string( $offer_json ) || '' === trim( $offer_json ) ) {
			return null;
		}

		$offer = json_decode( $offer_json, true );

		if ( ! is_array( $offer ) || ! isset( $offer['parameters'] ) || ! is_array( $offer['parameters'] ) ) {
			return null;
		}

		foreach ( $offer['parameters'] as $parameter ) {
			if ( ! is_array( $parameter ) || ( $parameter['name'] ?? null ) !== 'Stan' ) {
				continue;
			}

			$value = $parameter['values'][0] ?? null;

			return ( is_string( $value ) && '' !== trim( $value ) ) ? trim( $value ) : null;
		}

		return null;
	}

	/**
	 * Dobudowuje `choices` pola `klasa_stanu` z {@see ClassDefinitionsTaxonomy}
	 * (P-12.1a) — `value` = `kod` (join key, literał zapisywany na produkcie,
	 * BEZ ZMIAN względem dawnego hardkodowanego A-D), `label` = opisowa `nazwa`
	 * termu. Taksonomia niezasiedlona (przed uruchomieniem
	 * {@see SeedClassDefinitionsCommand}) → `choices` zostaje pustą tablicą;
	 * {@see self::render_missing_class_definitions_notice()} sygnalizuje ten
	 * stan w adminie, zamiast po cichu wracać do hardkodowanego fallbacku
	 * (co odtworzyłoby duplikację, którą ten byt ma zlikwidować).
	 *
	 * @param array<string,mixed> $field Definicja pola ACF przed renderem/walidacją.
	 * @return array<string,mixed>
	 */
	public static function inject_dynamic_choices( array $field ): array {
		$choices = array();

		foreach ( ClassDefinitionsTaxonomy::all() as $kod => $definicja ) {
			$choices[ $kod ] = $definicja['nazwa'];
		}

		$field['choices'] = $choices;

		return $field;
	}

	/**
	 * Notice w adminie: taksonomia {@see ClassDefinitionsTaxonomy} bez ani
	 * jednej klasy — pole `klasa_stanu` (wymagane) pokazałoby pusty `<select>`,
	 * blokując sensowne ustawienie stanu na KAŻDYM produkcie. Widoczny tylko na
	 * ekranie edycji/listy produktów, żeby nie zaśmiecać reszty admina.
	 *
	 * @return void
	 */
	public static function render_missing_class_definitions_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! ( $screen instanceof WP_Screen ) || 'product' !== $screen->post_type ) {
			return;
		}

		if ( array() !== ClassDefinitionsTaxonomy::all() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Qutlet: brak zdefiniowanych klas stanu (taksonomia „Klasy stanu" jest pusta) — uruchom „wp qutlet-core seed-klasa-stanu", żeby wypełnić ją dzisiejszymi klasami A-D.', 'qutlet-core' )
		);
	}
}
