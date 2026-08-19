<?php
/**
 * Slice ProductCondition — byt „definicja klasy stanu" (P-12.1a, D-12.1a.1).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Rejestruje taksonomię `klasa_stanu_definicja` — rozszerzalny byt niosący PER
 * KLASĘ: kolor, nazwę (natywne `name` termu), krótki opis na chipsie, stan
 * wizualny, charakterystykę, tekst „dlaczego taniej" (opcjonalny), okres
 * gwarancji i okres reklamacji (D-12.G3 — dwa osobne pola). Dodanie nowej klasy
 * (włącznie z „Nowe", D-12.G1) = dodanie termu w adminie, zero kodu.
 *
 * **REWIZJA D-1.2.1 → CUTOVER P-12.2a** (`docs/kontrakt-danych.md` §2/§2.2,
 * decyzja użytkownika, sesja 2026-08-13, `docs/plan.md` P-12.2, D-12.2.1):
 * produkt DOSTAJE realną relację `wp_set_object_terms()` z tą taksonomią —
 * poprzednia decyzja (P-12.1a) trzymała ją WYŁĄCZNIE jako byt opisowy (goły
 * literał `klasa_stanu` w postmeta był jedynym „przypisaniem"), co dawało
 * ZAWSZE 0 w liczniku „produktów" na ekranie „Produkty → Klasy stanu" —
 * zgłoszone jako confusing. Mechanizm: {@see ProductConditionFields} zmienia
 * TYP pola `klasa_stanu` z ACF `select` na ACF `taxonomy` (`save_terms`/
 * `load_terms` włączone) — ACF sam woła `wp_set_object_terms()` przy zapisie i
 * czyta z relacji, nie z postmeta. {@see self::for_product()} daje konsumentom
 * (P-12.2b/P-12.2c) czysty odczyt klasy PRODUKTU przez `get_the_terms()`.
 *
 * **Ryzyko operacyjne przejścia (D-12.2.1, „Uwaga operacyjna" w
 * `docs/plan.md` P-12.2a):** `qutlet-allegro\OfferSync\ProductWriter` (poza
 * zakresem tej sesji) dziś woła `update_field(ACF_KEY_CONDITION, $kod, …)`
 * gołym literałem (`'A'`…) — po zmianie typu pola na `taxonomy` ACF potrzebuje
 * `term_id`, nie kodu; `intval($kod)` daje `0`, więc `wp_set_object_terms()`
 * pomija tę wartość i relacja NIE POWSTAJE (postmeta `klasa_stanu` i tak
 * dostaje literał — zapis metadanych ACF jest bezwarunkowy). Taki NOWY
 * produkt renderuje się na żywej stronie BEZ chipa/wiersza tabeli/tekstu
 * gwarancji-reklamacji, nie wchodzi do filtra/facetów klasy stanu, i NIE da
 * się go zapisać z wp-admin (pole wymagane, puste) do czasu ręcznej
 * klasyfikacji — objawy identyczne jak przy produkcie bez backfillu. Skutek
 * dotyczy KAŻDEGO produktu zaimportowanego od merge'u tej sesji do merge'u
 * P-12.2b (osobna, przyszła sesja) — trzeba więc uruchamiać {@see
 * BackfillKlasaStanuRelationCommand} PO KAŻDYM `import-offers` w tym oknie,
 * nie tylko raz na starcie. Edycja RĘCZNA w adminie (dropdown) działa
 * poprawnie od razu, bo idzie przez natywny formularz ACF, nie przez
 * `update_field()` z gołym stringiem.
 *
 * {@see BackfillKlasaStanuRelationCommand} MUSI przebiec NATYCHMIAST po
 * wdrożeniu tej zmiany w każdym środowisku (Local teraz, produkcja później)
 * — PRZED jakimkolwiek zapisem ekranu edycji produktu, bo do czasu backfillu
 * dropdown klasy renderuje się jako PUSTY (brak jeszcze relacji), a zapis
 * formularza (nawet z innego powodu, np. zmiana ceny) BLOKUJE się na
 * walidacji ACF „wartość jest wymagana" (`required=1`, zmierzone runtime —
 * baza zostaje NIENARUSZONA, skutek to wymuszona reklasyfikacja i
 * zablokowana edycja produktu, NIE utrata danych).
 *
 * `kod` (term meta) to techniczny klucz łączący klasę z historycznym literałem
 * (`A`-`D`, `Nowe`) — NIE slug WP (który `sanitize_title()` bezwarunkowo
 * obniża do lowercase), więc nie nadaje się na klucz wymagający wielkiej
 * litery. Termu `name` niesie pełną, opisową nazwę klasy (np. „Jak nowy") —
 * administrator zarządza klasami pod tą nazwą, literał `kod` jest technicznym
 * szczegółem niewidocznym w typowym flow edycyjnym (Produkty → Klasy stanu).
 */
final class ClassDefinitionsTaxonomy {

	/**
	 * Nazwa taksonomii (max 32 znaki — limit WP).
	 */
	public const TAXONOMY = 'klasa_stanu_definicja';

	/**
	 * Klucz grupy pól ACF (term meta).
	 */
	private const GROUP_KEY = 'group_qutlet_klasa_stanu_definicja';

	/**
	 * Klucz pola `kod` (join key) — {@see self::validate_unique_kod()} dopasowuje
	 * po tym kluczu, żeby duplikat NIE nadpisał po cichu istniejącej klasy w
	 * {@see self::all()} (recenzja sesji P-12.1a — bez tej walidacji admin
	 * dodający klasę przez D-12.G1 mógł niechcący „ukryć" wcześniejszą).
	 */
	private const FIELD_KEY_KOD = 'field_qutlet_klasa_kod';

	/**
	 * Wpina rejestrację taksonomii i grupy pól ACF na `init`/`acf/init`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_taxonomy' ) );
		add_action( 'acf/init', array( self::class, 'register_fields' ) );
		add_filter( 'acf/validate_value/key=' . self::FIELD_KEY_KOD, array( self::class, 'validate_unique_kod' ), 10, 2 );
	}

	/**
	 * Rejestruje taksonomię. Dołączona do `product` m.in. żeby admin dostał
	 * ekran „Produkty → Klasy stanu" (Tags-like, za darmo); `meta_box_cb`
	 * wyłączony, żeby na ekranie edycji produktu nie pokazał się DRUGI,
	 * konkurencyjny panel wyboru termu — wybór klasy zostaje WYŁĄCZNIE na
	 * polu ACF `klasa_stanu` (od P-12.2a: typ `taxonomy`, ten sam mechanizm
	 * relacji, patrz docblock klasy), nie na natywnym metaboxie taksonomii.
	 *
	 * @return void
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( 'product' ),
			array(
				'labels'            => array(
					'name'          => __( 'Klasy stanu', 'qutlet-core' ),
					'singular_name' => __( 'Klasa stanu', 'qutlet-core' ),
					'add_new_item'  => __( 'Dodaj klasę stanu', 'qutlet-core' ),
					'edit_item'     => __( 'Edytuj klasę stanu', 'qutlet-core' ),
					'menu_name'     => __( 'Klasy stanu', 'qutlet-core' ),
				),
				'public'            => false,
				'publicly_queryable' => false,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => false,
				'show_admin_column' => false,
				'show_tagcloud'     => false,
				'show_in_quick_edit' => false,
				'meta_box_cb'       => false,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Rejestruje grupę pól ACF (term meta) na ekranie dodawania/edycji termu tej
	 * taksonomii — lokalizacja `taxonomy` (ACF Pro), admin UI „za darmo" (D-12.1a.1).
	 *
	 * @return void
	 */
	public static function register_fields(): void {
		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP_KEY,
				'title'                 => __( 'Qutlet — definicja klasy stanu', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => self::FIELD_KEY_KOD,
						'label'        => __( 'Kod (klucz techniczny)', 'qutlet-core' ),
						'name'         => 'kod',
						'type'         => 'text',
						'instructions' => __( 'Techniczny identyfikator historycznie zapisywany na produkcie (pole „Klasa stanu", literał w polu — dziś relacja z tym termem, patrz mechanizm P-12.2a). Dla dzisiejszych klas: A/B/C/D — case-sensitive, MUSI być unikalny. Zmiana kodu istniejącej klasy odłącza od niej już zsynchronizowane produkty (join po tym kodzie), np. przy kolejnym backfillu.', 'qutlet-core' ),
						'required'     => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_kolor',
						'label'        => __( 'Kolor', 'qutlet-core' ),
						'name'         => 'kolor',
						'type'         => 'color_picker',
						'instructions' => __( 'Kolor kropki/chipsa klasy.', 'qutlet-core' ),
						'required'     => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_opis_chip',
						'label'        => __( 'Opis na chipsie', 'qutlet-core' ),
						'name'         => 'opis_chip',
						'type'         => 'text',
						'instructions' => __( 'Krótki tekst pokazywany na chipsie/pigułce (np. „Klasa A · Jak nowy"). Wolny format — nie każda klasa musi zaczynać się od „Klasa X".', 'qutlet-core' ),
						'required'     => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_stan_wizualny',
						'label'        => __( 'Stan wizualny', 'qutlet-core' ),
						'name'         => 'stan_wizualny',
						'type'         => 'text',
						'instructions' => __( 'Kolumna „Stan wizualny" w tabeli klasyfikacji na stronie produktu.', 'qutlet-core' ),
						'required'     => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_charakterystyka',
						'label'        => __( 'Charakterystyka', 'qutlet-core' ),
						'name'         => 'charakterystyka',
						'type'         => 'text',
						'instructions' => __( 'Kolumna „Charakterystyka" w tabeli klasyfikacji na stronie produktu.', 'qutlet-core' ),
						'required'     => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_dlaczego_taniej',
						'label'        => __( 'Dlaczego taniej', 'qutlet-core' ),
						'name'         => 'dlaczego_taniej',
						'type'         => 'textarea',
						'instructions' => __( 'Tekst „Skąd niższa cena?" per klasa. Może być PUSTY (np. klasa „Nowe" nie ma czego tłumaczyć).', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_okres_gwarancji',
						'label'        => __( 'Okres gwarancji (miesiące)', 'qutlet-core' ),
						'name'         => 'okres_gwarancji_miesiace',
						'type'         => 'number',
						'instructions' => __( 'Dobrowolne zobowiązanie sprzedawcy. Liczba całkowita miesięcy (np. 12 = „1 rok").', 'qutlet-core' ),
						'required'     => 1,
						'min'          => 0,
						'step'         => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_okres_reklamacji',
						'label'        => __( 'Okres reklamacji ustawowej (miesiące)', 'qutlet-core' ),
						'name'         => 'okres_reklamacji_miesiace',
						'type'         => 'number',
						'instructions' => __( 'Rękojmia/reklamacja ustawowa (D-12.G3 — osobna podstawa prawna od gwarancji, nawet jeśli dziś liczbowo równa). Liczba całkowita miesięcy.', 'qutlet-core' ),
						'required'     => 1,
						'min'          => 0,
						'step'         => 1,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_naglowek',
						'label'        => __( 'Zwrot — nagłówek (perk)', 'qutlet-core' ),
						'name'         => 'zwrot_naglowek',
						'type'         => 'text',
						'instructions' => __( '„14 dni na zwrot” — wspólny nagłówek dla obu kanałów zakupu. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_tag_qutlet',
						'label'        => __( 'Zwrot — tag kanału Qutlet', 'qutlet-core' ),
						'name'         => 'zwrot_tag_qutlet',
						'type'         => 'text',
						'instructions' => __( '„Koszt po Twojej stronie” — panel kanału Qutlet WYŁĄCZNIE. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_tag_allegro',
						'label'        => __( 'Zwrot — tag kanału Allegro', 'qutlet-core' ),
						'name'         => 'zwrot_tag_allegro',
						'type'         => 'text',
						'instructions' => __( '„Możliwy bezpłatny” — panel kanału Allegro WYŁĄCZNIE. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_wysylka_naglowek',
						'label'        => __( 'Wysyłka — nagłówek (perk)', 'qutlet-core' ),
						'name'         => 'wysylka_naglowek',
						'type'         => 'text',
						'instructions' => __( '„Wysyłka w 1 dzień roboczy” — wspólny nagłówek dla obu kanałów. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_opis_qutlet',
						'label'        => __( 'Zwrot — opis kanału Qutlet', 'qutlet-core' ),
						'name'         => 'zwrot_opis_qutlet',
						'type'         => 'textarea',
						'instructions' => __( 'Zdanie pod etykietą „Polityka zwrotów:” (etykieta zostaje statyczna w motywie) — panel kanału Qutlet. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_opis_allegro',
						'label'        => __( 'Zwrot — opis kanału Allegro', 'qutlet-core' ),
						'name'         => 'zwrot_opis_allegro',
						'type'         => 'textarea',
						'instructions' => __( 'Zdanie pod etykietą „Polityka zwrotów:” (etykieta zostaje statyczna w motywie) — panel kanału Allegro. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_wysylka_opis',
						'label'        => __( 'Wysyłka — opis (akordeon)', 'qutlet-core' ),
						'name'         => 'wysylka_opis',
						'type'         => 'textarea',
						'instructions' => __( 'Akordeon „Dostawa i zwroty”, karta „Szybka wysyłka”. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_akordeon_opis_qutlet',
						'label'        => __( 'Zwrot — opis kanału Qutlet (akordeon)', 'qutlet-core' ),
						'name'         => 'zwrot_akordeon_opis_qutlet',
						'type'         => 'textarea',
						'instructions' => __( 'Akordeon „Dostawa i zwroty”, karta zwrotu kanału Qutlet. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_zwrot_akordeon_opis_allegro',
						'label'        => __( 'Zwrot — opis kanału Allegro (akordeon)', 'qutlet-core' ),
						'name'         => 'zwrot_akordeon_opis_allegro',
						'type'         => 'textarea',
						'instructions' => __( 'Akordeon „Dostawa i zwroty”, karta „Zwrot — Allegro”. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_gwarancja_opis',
						'label'        => __( 'Gwarancja — opis', 'qutlet-core' ),
						'name'         => 'gwarancja_opis',
						'type'         => 'textarea',
						'instructions' => __( 'Zdanie-otoczka w akordeonie „Gwarancja i reklamacje”. Użyj placeholdera {okres} — motyw podstawia sformatowany okres gwarancji (np. „12 miesięcy”). Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_reklamacja_opis',
						'label'        => __( 'Reklamacja — opis', 'qutlet-core' ),
						'name'         => 'reklamacja_opis',
						'type'         => 'textarea',
						'instructions' => __( 'Zdanie w akordeonie „Gwarancja i reklamacje”, karta „Prawo do reklamacji”. Użyj placeholdera {okres} — motyw podstawia sformatowany okres reklamacji. Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
					array(
						'key'          => 'field_qutlet_klasa_stan_uzywany_opis',
						'label'        => __( 'Stan używany — zapewnienie', 'qutlet-core' ),
						'name'         => 'stan_uzywany_opis',
						'type'         => 'textarea',
						'instructions' => __( 'Drugie zdanie w .know-fine (pierwsze, „Wszystkie produkty w Qutlet sprzedawane są jako używane.”, zostaje statyczne w motywie). Puste → motyw pokazuje dzisiejszy tekst domyślny.', 'qutlet-core' ),
						'required'     => 0,
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => self::TAXONOMY,
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => __( 'Pola definicji klasy stanu — rejestruje qutlet-core (P-12.1a).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}

	/**
	 * Wymusza unikalność `kod` między termami (recenzja P-12.1a) — bez tej
	 * walidacji duplikat po cichu NADPISZE wcześniejszy wpis w {@see self::all()}
	 * (indeksowanie po `kod`), robiąc jedną z definicji niewidoczną (m.in. w
	 * {@see self::for_product()} i backfillu) bez żadnego ostrzeżenia. `tag_ID` to natywne, ukryte pole
	 * formularza edycji termu WP (`wp-admin/edit-tags.php`) — obecne przy EDYCJI
	 * istniejącego termu (wyłącza go z porównania), nieobecne przy DODAWANIU
	 * nowego (nic do wyłączenia).
	 *
	 * @param bool|string $valid Wynik dotychczasowej walidacji (np. `required`).
	 * @param mixed       $value Wartość pola `kod` przed zapisem.
	 * @return bool|string
	 */
	public static function validate_unique_kod( $valid, $value ) {
		if ( true !== $valid ) {
			return $valid; // Wcześniejsza walidacja już odrzuciła wartość.
		}

		$kod = trim( (string) $value );

		if ( '' === $kod ) {
			return $valid;
		}

		$current_term_id = isset( $_POST['tag_ID'] ) ? (int) $_POST['tag_ID'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- tylko porównanie ID do komunikatu walidacji, sam zapis chroniony natywnym nonce'em edycji termu.
		$existing         = self::all()[ $kod ] ?? null;

		if ( null !== $existing && $existing['term_id'] !== $current_term_id ) {
			return sprintf(
				/* translators: 1: kod, 2: nazwa klasy, która już go używa. */
				__( 'Kod „%1$s" jest już użyty przez klasę „%2$s" — musi być unikalny.', 'qutlet-core' ),
				$kod,
				$existing['nazwa']
			);
		}

		return $valid;
	}

	/**
	 * Wszystkie zdefiniowane klasy, kluczowane po `kod` (join key, patrz docblock
	 * klasy). Sortowanie po `kod` (rosnąco) dla deterministycznego porządku w
	 * `<select>` — taksonomia nie ma natywnego porządku poza tym.
	 *
	 * @return array<string, array{
	 *     term_id: int,
	 *     nazwa: string,
	 *     kolor: string,
	 *     opis_chip: string,
	 *     stan_wizualny: string,
	 *     charakterystyka: string,
	 *     dlaczego_taniej: string,
	 *     okres_gwarancji_miesiace: int,
	 *     okres_reklamacji_miesiace: int,
	 *     zwrot_naglowek: string,
	 *     zwrot_tag_qutlet: string,
	 *     zwrot_tag_allegro: string,
	 *     wysylka_naglowek: string,
	 *     zwrot_opis_qutlet: string,
	 *     zwrot_opis_allegro: string,
	 *     wysylka_opis: string,
	 *     zwrot_akordeon_opis_qutlet: string,
	 *     zwrot_akordeon_opis_allegro: string,
	 *     gwarancja_opis: string,
	 *     reklamacja_opis: string,
	 *     stan_uzywany_opis: string,
	 * }>
	 */
	public static function all(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$rows = array();

		foreach ( $terms as $term ) {
			$kod = (string) get_term_meta( $term->term_id, 'kod', true );

			if ( '' === $kod ) {
				continue; // Term bez wypełnionego kodu jeszcze nie jest użyteczny — pomijamy.
			}

			$rows[ $kod ] = array(
				'term_id'                     => $term->term_id,
				'nazwa'                       => $term->name,
				'kolor'                       => (string) get_term_meta( $term->term_id, 'kolor', true ),
				'opis_chip'                   => (string) get_term_meta( $term->term_id, 'opis_chip', true ),
				'stan_wizualny'               => (string) get_term_meta( $term->term_id, 'stan_wizualny', true ),
				'charakterystyka'             => (string) get_term_meta( $term->term_id, 'charakterystyka', true ),
				'dlaczego_taniej'             => (string) get_term_meta( $term->term_id, 'dlaczego_taniej', true ),
				'okres_gwarancji_miesiace'    => (int) get_term_meta( $term->term_id, 'okres_gwarancji_miesiace', true ),
				'okres_reklamacji_miesiace'   => (int) get_term_meta( $term->term_id, 'okres_reklamacji_miesiace', true ),
				'zwrot_naglowek'              => (string) get_term_meta( $term->term_id, 'zwrot_naglowek', true ),
				'zwrot_tag_qutlet'            => (string) get_term_meta( $term->term_id, 'zwrot_tag_qutlet', true ),
				'zwrot_tag_allegro'           => (string) get_term_meta( $term->term_id, 'zwrot_tag_allegro', true ),
				'wysylka_naglowek'            => (string) get_term_meta( $term->term_id, 'wysylka_naglowek', true ),
				'zwrot_opis_qutlet'           => (string) get_term_meta( $term->term_id, 'zwrot_opis_qutlet', true ),
				'zwrot_opis_allegro'          => (string) get_term_meta( $term->term_id, 'zwrot_opis_allegro', true ),
				'wysylka_opis'                => (string) get_term_meta( $term->term_id, 'wysylka_opis', true ),
				'zwrot_akordeon_opis_qutlet'  => (string) get_term_meta( $term->term_id, 'zwrot_akordeon_opis_qutlet', true ),
				'zwrot_akordeon_opis_allegro' => (string) get_term_meta( $term->term_id, 'zwrot_akordeon_opis_allegro', true ),
				'gwarancja_opis'              => (string) get_term_meta( $term->term_id, 'gwarancja_opis', true ),
				'reklamacja_opis'             => (string) get_term_meta( $term->term_id, 'reklamacja_opis', true ),
				'stan_uzywany_opis'           => (string) get_term_meta( $term->term_id, 'stan_uzywany_opis', true ),
			);
		}

		ksort( $rows );

		return $rows;
	}

	/**
	 * Jedna definicja po `kod` (join key) — `null`, gdy nieznana.
	 *
	 * @param string $kod Techniczny kod klasy (`A`-`D`, `Nowe`) — term meta `kod`.
	 * @return array{term_id: int, nazwa: string, kolor: string, opis_chip: string, stan_wizualny: string, charakterystyka: string, dlaczego_taniej: string, okres_gwarancji_miesiace: int, okres_reklamacji_miesiace: int, zwrot_naglowek: string, zwrot_tag_qutlet: string, zwrot_tag_allegro: string, wysylka_naglowek: string, zwrot_opis_qutlet: string, zwrot_opis_allegro: string, wysylka_opis: string, zwrot_akordeon_opis_qutlet: string, zwrot_akordeon_opis_allegro: string, gwarancja_opis: string, reklamacja_opis: string, stan_uzywany_opis: string}|null
	 */
	public static function get( string $kod ): ?array {
		return self::all()[ $kod ] ?? null;
	}

	/**
	 * Definicja klasy PRZYPISANEJ do produktu — czyta przez realną relację
	 * (`get_the_terms()`, P-12.2a, D-12.2.1), NIE przez zewnętrzny literał.
	 * `null`, gdy produkt nie ma jeszcze relacji (świeży produkt przed
	 * klasyfikacją, LUB produkt zaimportowany PRZED backfillem tej sesji —
	 * {@see BackfillKlasaStanuRelationCommand}) albo relacja wskazuje na term
	 * bez wypełnionego `kod` (niekompletna definicja).
	 *
	 * Pole `klasa_stanu` (ACF `taxonomy`, single-value) niesie NAJWYŻEJ jeden
	 * term na produkt — bierzemy pierwszy z {@see get_the_terms()}.
	 *
	 * **D-12.2.4 (semantyka „puste" po cutoverze, `docs/plan.md` P-12.2):**
	 * „puste" dla konsumentów zapisu (P-12.2b) = `null` z tej metody, NIE pusty
	 * string postmeta — zachowuje identyczny skutek co dawny
	 * `'' === get_post_meta($id, 'klasa_stanu', true)` (D-6.1.4: ręczna ocena
	 * sprzedawcy nigdy nadpisywana kolejnym importem), o ile backfill
	 * przebiegł PRZED cutoverem zapisu (patrz docblock klasy — ryzyko
	 * operacyjne).
	 *
	 * @param int $product_id ID produktu.
	 * @return array{kod: string, term_id: int, nazwa: string, kolor: string, opis_chip: string, stan_wizualny: string, charakterystyka: string, dlaczego_taniej: string, okres_gwarancji_miesiace: int, okres_reklamacji_miesiace: int, zwrot_naglowek: string, zwrot_tag_qutlet: string, zwrot_tag_allegro: string, wysylka_naglowek: string, zwrot_opis_qutlet: string, zwrot_opis_allegro: string, wysylka_opis: string, zwrot_akordeon_opis_qutlet: string, zwrot_akordeon_opis_allegro: string, gwarancja_opis: string, reklamacja_opis: string, stan_uzywany_opis: string}|null
	 */
	public static function for_product( int $product_id ): ?array {
		$terms = get_the_terms( $product_id, self::TAXONOMY );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}

		$term = reset( $terms );
		$kod  = (string) get_term_meta( $term->term_id, 'kod', true );

		if ( '' === $kod ) {
			return null;
		}

		$definicja = self::get( $kod );

		if ( null === $definicja ) {
			return null;
		}

		return array( 'kod' => $kod ) + $definicja;
	}
}
