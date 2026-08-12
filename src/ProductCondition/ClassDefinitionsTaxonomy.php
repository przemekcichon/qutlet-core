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
 * **REWIZJA D-1.2.1** (`docs/kontrakt-danych.md` §2 — świadomie odrzuciła
 * taksonomię na rzecz ACF select; decyzja użytkownika, sesja 2026-08-12,
 * `docs/plan.md` P-12.1a): taksonomia wraca, ale jako byt OPISOWY (definicje),
 * NIE jako mechanizm przypisania klasy do produktu — produkt NIE dostaje relacji
 * `wp_set_object_terms()` z tą taksonomią. Powód: `qutlet-allegro`
 * ({@see \Qutlet\Allegro\OfferSync\ProductWriter}) i `qutlet-theme`
 * (`ProductPage::acf_field('klasa_stanu', …)`, kilka miejsc) czytają/piszą
 * `klasa_stanu` jako PROSTY LITERAŁ w postmeta — obie ścieżki są POZA zakresem
 * tej sesji (P-12.1b/P-12.1c, osobne branche/PR-y). Zerwanie tego kontraktu
 * teraz zepsułoby sync i render na żywej stronie do czasu ich wdrożenia
 * (decyzja użytkownika: zachować kontrakt wstecz). Dlatego {@see
 * ProductConditionFields} NADAL rejestruje `klasa_stanu` jako ACF `select`,
 * zapisujący ten sam literał co dziś (`A`-`D`) — zmienia się WYŁĄCZNIE SKĄD
 * pochodzą `choices` (dawniej hardkodowana tablica, dziś ta taksonomia, przez
 * {@see ProductConditionFields::inject_dynamic_choices()}).
 *
 * „Migracja danych" (D-12.1a.2) w tym modelu = jednorazowe SEEDOWANIE tej
 * taksonomii wierszami A-D ({@see SeedClassDefinitionsCommand}), nie migracja
 * per-produkt — istniejące produkty nie zmieniają ani formatu, ani wartości
 * swojego pola `klasa_stanu`.
 *
 * `kod` (term meta) to techniczny klucz łączący literał zapisany na produkcie
 * (`A`-`D`, zapisywany przez `qutlet-allegro`) z wierszem tej taksonomii —
 * NIE slug WP (który `sanitize_title()` bezwarunkowo obniża do lowercase),
 * więc nie nadaje się na klucz wymagający wielkiej litery. Termu `name` niesie
 * pełną, opisową nazwę klasy (np. „Jak nowy") — administrator zarządza klasami
 * pod tą nazwą, literał `kod` jest technicznym szczegółem niewidocznym w
 * typowym flow edycyjnym (Produkty → Klasy stanu).
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
	 * Rejestruje taksonomię — DEFINICJE, nie relacja z produktem (patrz docblock
	 * klasy). Dołączona do `product` WYŁĄCZNIE, żeby admin dostał ekran
	 * „Produkty → Klasy stanu" (Tags-like, za darmo); `meta_box_cb` wyłączony,
	 * żeby na ekranie edycji produktu nie pokazał się mylący panel wyboru termu
	 * (rzeczywisty wybór klasy zostaje na polu ACF `klasa_stanu`).
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
						'instructions' => __( 'Techniczny identyfikator zapisywany na produkcie (pole „Klasa stanu"). Dla dzisiejszych klas: A/B/C/D — case-sensitive, MUSI być unikalny. Zmiana kodu istniejącej klasy odłącza od niej już zsynchronizowane produkty.', 'qutlet-core' ),
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
	 * (indeksowanie po `kod`), robiąc jedną z definicji niewidoczną w `choices`
	 * pola `klasa_stanu` bez żadnego ostrzeżenia. `tag_ID` to natywne, ukryte pole
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
				'term_id'                   => $term->term_id,
				'nazwa'                     => $term->name,
				'kolor'                     => (string) get_term_meta( $term->term_id, 'kolor', true ),
				'opis_chip'                 => (string) get_term_meta( $term->term_id, 'opis_chip', true ),
				'stan_wizualny'             => (string) get_term_meta( $term->term_id, 'stan_wizualny', true ),
				'charakterystyka'           => (string) get_term_meta( $term->term_id, 'charakterystyka', true ),
				'dlaczego_taniej'           => (string) get_term_meta( $term->term_id, 'dlaczego_taniej', true ),
				'okres_gwarancji_miesiace'  => (int) get_term_meta( $term->term_id, 'okres_gwarancji_miesiace', true ),
				'okres_reklamacji_miesiace' => (int) get_term_meta( $term->term_id, 'okres_reklamacji_miesiace', true ),
			);
		}

		ksort( $rows );

		return $rows;
	}

	/**
	 * Jedna definicja po `kod` (join key) — `null`, gdy nieznana.
	 *
	 * @param string $kod Literał zapisany na produkcie (pole `klasa_stanu`).
	 * @return array{term_id: int, nazwa: string, kolor: string, opis_chip: string, stan_wizualny: string, charakterystyka: string, dlaczego_taniej: string, okres_gwarancji_miesiace: int, okres_reklamacji_miesiace: int}|null
	 */
	public static function get( string $kod ): ?array {
		return self::all()[ $kod ] ?? null;
	}
}
