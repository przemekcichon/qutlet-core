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
 * - `klasa_stanu`                 — ACF `taxonomy` (P-12.2a, REWIZJA D-1.2.1/
 *   P-12.1a — poprzednio `select`), wymagane, single-value. Zapisuje REALNĄ
 *   relację `wp_set_object_terms()` z {@see ClassDefinitionsTaxonomy}
 *   (`save_terms`/`load_terms` włączone) — cutover, decyzja użytkownika,
 *   sesja 2026-08-13, `docs/plan.md` P-12.2, D-12.2.1. Wcześniej pole było
 *   `select` z `choices` dobudowywanymi dynamicznie z tego samego bytu, a
 *   „przypisanie" klasy do produktu było gołym literałem w postmeta — ACF
 *   teraz zarządza relacją natywnie, `choices` nie trzeba już wstrzykiwać
 *   ręcznie (UI budowany z realnych termów taksonomii). Ryzyko operacyjne
 *   przejścia (auto-mapa importu Allegro, backfill) — patrz docblock
 *   {@see ClassDefinitionsTaxonomy}.
 * - (message, read-only) `Gwarancja / reklamacja dla wybranej klasy` — P-13.7b.
 *   Nie zapisuje żadnej wartości; treść dopisywana dynamicznie na
 *   `acf/pre_render_field` ({@see self::inject_klasa_stanu_terminy_message()}).
 *   Odczyt okresów gwarancji/reklamacji USTAWOWEJ z bytu klasy stanu
 *   PRZYPISANEJ dziś do produktu ({@see ClassDefinitionsTaxonomy::for_product()},
 *   pola `okres_gwarancji_miesiace`/`okres_reklamacji_miesiace`, P-12.1a) —
 *   informacyjnie, żeby kurator widział konsekwencję wyboru `klasa_stanu` bez
 *   przechodzenia do ekranu „Produkty → Klasy stanu".
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
 * czyta dokładnie ten literał (`get_field()` / `get_post_meta()`). Wyjątek od
 * P-12.2a: `klasa_stanu` (ACF `taxonomy`) NIE trzyma już swojej wartości jako
 * plain literału w postmeta — konsumenci czytają przez {@see
 * ClassDefinitionsTaxonomy::for_product()}.
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
	 * Klucz pola `klasa_stanu` (P-12.1a; typ ACF od P-12.2a: `taxonomy`, patrz
	 * {@see self::register()}) — VERBATIM z `qutlet-allegro\OfferSync\
	 * ProductWriter::ACF_KEY_CONDITION`, kontrakt między repo.
	 */
	private const FIELD_KEY_KLASA_STANU = 'field_qutlet_klasa_stanu';

	/**
	 * Klucz pola read-only (typ ACF `message`, P-13.7b) — informacja o
	 * okresach gwarancji/reklamacji USTAWOWEJ zdefiniowanych na bycie klasy
	 * ({@see ClassDefinitionsTaxonomy}) PRZYPISANEJ dziś do tego produktu.
	 * Guard analogiczny do {@see self::FIELD_KEY_ALLEGRO_STAN_RAW} —
	 * `acf/pre_render_field` jest globalny.
	 */
	private const FIELD_KEY_KLASA_STANU_TERMINY = 'field_qutlet_klasa_stanu_terminy';

	/**
	 * Wpina rejestrację na `acf/init` — moment, w którym ACF jest gotowe na
	 * `acf_add_local_field_group()` (zalecenie ACF). Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * `acf/pre_render_field` (P-13.7a, rozszerzone P-13.7b) dopisuje treść pól-
	 * komunikatów {@see self::FIELD_KEY_ALLEGRO_STAN_RAW} i {@see
	 * self::FIELD_KEY_KLASA_STANU_TERMINY} PRZED renderem — jedyny hook ACF,
	 * który dostaje `$post_id` wprost jako argument (`acf_render_fields()`), więc
	 * nie trzeba zgadywać ID produktu z globalnego stanu.
	 *
	 * Pole `klasa_stanu` jest ACF `taxonomy` od P-12.2a (patrz {@see
	 * self::register()}) — dodanie nowej klasy (D-12.G1) jest widoczne od razu
	 * bez cache'owania, bo ACF czyta listę termów taksonomii na żywo przy
	 * renderze; nie ma już osobnego kroku wstrzykiwania `choices`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'acf/init', array( self::class, 'register' ) );
		add_filter( 'acf/pre_render_field', array( self::class, 'inject_condition_raw_message' ), 10, 2 );
		add_filter( 'acf/pre_render_field', array( self::class, 'inject_klasa_stanu_terminy_message' ), 10, 2 );
		add_filter( 'acf/format_value/key=' . self::FIELD_KEY_KLASA_STANU, array( self::class, 'format_condition_as_kod' ), 20 );
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
						'key'           => self::FIELD_KEY_KLASA_STANU,
						'label'         => __( 'Klasa stanu', 'qutlet-core' ),
						'name'          => 'klasa_stanu',
						'type'          => 'taxonomy',
						'instructions'  => __( 'Ocena stanu egzemplarza. Wybór tworzy realną relację z taksonomią „Klasy stanu" (Produkty → Klasy stanu).', 'qutlet-core' ),
						'required'      => 1,
						// P-12.2a (D-12.2.1, cutover): relacja NATYWNA z ClassDefinitionsTaxonomy —
						// `save_terms`/`load_terms` włączone, ACF sam woła wp_set_object_terms()
						// przy zapisie i czyta z relacji (get_the_terms()), nie z postmeta.
						'taxonomy'      => ClassDefinitionsTaxonomy::TAXONOMY,
						'field_type'    => 'select',
						'add_term'      => 0, // Nowe klasy TYLKO przez ekran „Produkty → Klasy stanu" (D-12.G1) — nie ad-hoc z edycji produktu.
						'save_terms'    => 1,
						'load_terms'    => 1,
						'multiple'      => 0,
						'allow_null'    => 0,
						'return_format' => 'id',
					),
					array(
						'key'          => self::FIELD_KEY_KLASA_STANU_TERMINY,
						'label'        => __( 'Gwarancja / reklamacja dla wybranej klasy', 'qutlet-core' ),
						'name'         => 'klasa_stanu_terminy_display',
						'type'         => 'message',
						// Treść dopisywana dynamicznie — patrz self::inject_klasa_stanu_terminy_message().
						'message'      => '',
						'new_lines'    => '',
						'esc_html'     => 1,
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
	 * Dopisuje treść pola-komunikatu {@see self::FIELD_KEY_KLASA_STANU_TERMINY}
	 * TUŻ PRZED renderem (P-13.7b) — sam guard i mechanizm identyczny jak
	 * {@see self::inject_condition_raw_message()}.
	 *
	 * @param array<string,mixed> $field   Definicja pola ACF przed renderem.
	 * @param int|string          $post_id ID kontekstu formularza ACF (tu: ID produktu).
	 * @return array<string,mixed>
	 */
	public static function inject_klasa_stanu_terminy_message( array $field, $post_id ): array {
		if ( self::FIELD_KEY_KLASA_STANU_TERMINY !== ( $field['key'] ?? null ) ) {
			return $field;
		}

		$product_id       = is_numeric( $post_id ) ? (int) $post_id : 0;
		$field['message'] = self::klasa_stanu_terminy_message( $product_id );

		return $field;
	}

	/**
	 * Treść komunikatu: gwarancja/reklamacja USTAWOWEJ klasy PRZYPISANEJ dziś
	 * do produktu (relacja, {@see ClassDefinitionsTaxonomy::for_product()}),
	 * albo nota o jej braku (produkt jeszcze niesklasyfikowany — świeży
	 * `auto-draft` albo niezrelacjonowany, patrz docblock
	 * {@see ClassDefinitionsTaxonomy::for_product()}).
	 *
	 * Okresy wyświetlane w miesiącach (skrót „mies.") — ta sama jednostka co
	 * label pól definicji klasy (`Okres gwarancji (miesiące)` §2.2 kontraktu),
	 * bez konwersji na lata: pluralizacja polska (1 rok/2-4 lata/5+ lat) jest
	 * już zaimplementowana w `qutlet-theme\ProductPage::period_years_text()`
	 * (front klienta) — core NIE importuje kodu z theme (granica repo,
	 * `CLAUDE.md` §Struktura), a ten komunikat jest wyłącznie informacją dla
	 * kuratora w adminie, nie treścią klienta, więc duplikowanie tamtej
	 * pluralizacji tutaj byłoby zbędną abstrakcją.
	 *
	 * @param int $product_id ID produktu (0 = brak kontekstu, np. formularz poza ekranem edycji produktu).
	 * @return string
	 */
	private static function klasa_stanu_terminy_message( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return __( 'Brak kontekstu produktu.', 'qutlet-core' );
		}

		$definicja = ClassDefinitionsTaxonomy::for_product( $product_id );

		if ( null === $definicja ) {
			return __( 'Produkt nie ma jeszcze przypisanej klasy stanu — wybierz i zapisz klasę, żeby zobaczyć jej okresy gwarancji i reklamacji.', 'qutlet-core' );
		}

		return sprintf(
			/* translators: 1: nazwa klasy stanu (np. „Jak nowy"), 2: okres gwarancji w miesiącach, 3: okres reklamacji w miesiącach. */
			__( 'Klasa „%1$s" → gwarancja: %2$d mies., reklamacja: %3$d mies.', 'qutlet-core' ),
			$definicja['nazwa'],
			$definicja['okres_gwarancji_miesiace'],
			$definicja['okres_reklamacji_miesiace']
		);
	}

	/**
	 * Kompatybilność wsteczna dla `get_field('klasa_stanu')` (P-12.2a, D-12.2.1
	 * cutover): pole zmienia typ na `taxonomy` (`return_format=id`,
	 * `field_type=select`), więc bez tego filtra ACF zwróciłby `term_id`
	 * (int, np. `166`) — `qutlet-allegro`/`qutlet-theme` (poza zakresem tej
	 * sesji, osobne punkty P-12.2b/P-12.2c) wciąż oczekują dawnego kodu
	 * (`A`-`D`, `Nowe`). Filtr jest zawężony przez sam mechanizm rejestracji
	 * (`acf/format_value/key=…`, zmienna hooka), fires PO wewnętrznym
	 * `format_value()` typu `taxonomy` ACF (ta sama kolejność wariantów co
	 * `acf/update_value` — `type` przed `key`), więc dostaje już finalny
	 * `term_id`, nie surową wartość z bazy.
	 *
	 * Brak relacji → ACF zwraca `false` (`empty($value)` w jego
	 * `format_value()`) — nic do zmapowania, `is_numeric(false)` jest
	 * `false`, więc `$value` przechodzi bez zmian (konsumenci już obsługują
	 * pusty/falsy kod, patrz `qutlet-theme\Cart::cart_item_data()`).
	 *
	 * Relacja do termu BEZ wypełnionego `kod` (recenzja P-12.2a, rundy 2) →
	 * zwracamy `''`, NIE surowy `term_id` — degradacja identyczna jak
	 * {@see ClassDefinitionsTaxonomy::all()}/{@see ClassDefinitionsTaxonomy::for_product()}
	 * (obie pomijają/zwracają `null` dla termu bez `kod`). Bez tej gałęzi
	 * konsument (np. `Cart::cart_item_data()`) wypuściłby surowy `term_id` na
	 * powierzchnię klienta („Klasa 166" w koszyku) — ten stan jest
	 * mało prawdopodobny (formularz definicji klasy ma `kod` jako
	 * `required=1`), ale osiągalny przez term utworzony poza tym formularzem.
	 *
	 * @param mixed $value Wartość PO wewnętrznym formatowaniu ACF (`term_id`, int, albo `false`).
	 * @return mixed Kod klasy (string, np. `C`), `''` (relacja do termu bez `kod`) — albo `$value` bez zmian, gdy nie ma żadnej relacji.
	 */
	public static function format_condition_as_kod( $value ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		return (string) get_term_meta( (int) $value, 'kod', true );
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
