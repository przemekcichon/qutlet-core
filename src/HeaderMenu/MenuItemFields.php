<?php
/**
 * Slice HeaderMenu — pola ACF pozycji menu kategorii, P-16.2a.
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\HeaderMenu;

/**
 * Rejestruje grupę pól ACF „Menu Item" (`nav_menu_item`, ACF Pro 6.8.7) na
 * pozycjach menu przypisanych do lokalizacji `kategorie` — pierwszy przypadek
 * rejestracji ACF w core NIE na `product`/`klasa_stanu_definicja` (D-16.G1).
 *
 * Pola (literały z `docs/kontrakt-danych.md` §14.2 — VERBATIM, case-sensitive):
 * - `widoczna_na_belce` — true_false, domyślnie `false`. `true` → pozycja
 *   renderuje się TAKŻE jako stała pigułka `.subnav-band`/`.mnav-panel`; NIE
 *   jest alternatywą dla przynależności do kolumny (obie właściwości niezależne).
 * - `grupa_mega_menu` — taxonomy (target {@see MegaMenuGroupTaxonomy}),
 *   `field_type=select`, single value, `save_terms`/`load_terms` włączone,
 *   WYMAGANE — każda pozycja menu kategorii musi trafić do dokładnie jednej
 *   kolumny (mega menu nie ma „pozycji bez grupy").
 *
 * **Literał-most między repo (D-16.G1, KRYTYCZNY, kontrakt §14):** lokalizacja
 * ACF poniżej scope'uje grupę pól regułą `nav_menu_item == location/kategorie`
 * — `kategorie` (self::LOCATION_KATEGORIE) MUSI być DOKŁADNIE tym samym
 * stringiem, który `qutlet-theme` (P-16.2b, osobna, późniejsza sesja) przekaże
 * jako pierwszy argument `register_nav_menu()`. Zmiana sluga w JEDNYM repo bez
 * drugiego cicho odłącza te pola od pozycji menu (dropdown przestaje się
 * pokazywać w Wygląd → Menu, bez żadnego błędu/ostrzeżenia) — core NIE importuje
 * kodu z `qutlet-theme` (granica repo, `CLAUDE.md` §Struktura), więc dopasowanie
 * jest WYŁĄCZNIE przez kontrakt, nie przez współdzieloną stałą PHP (wzorem
 * `ProductConditionFields::FIELD_KEY_KLASA_STANU` vs. `qutlet-allegro\
 * ProductWriter::ACF_KEY_CONDITION`).
 *
 * Mechanizm lokalizacji: `class-acf-location-nav-menu-item.php` (`name=
 * nav_menu_item`) deleguje matching do `class-acf-location-nav-menu.php` —
 * wartość reguły `location/{slug}` rozwiązuje `{slug}` przez
 * `get_nav_menu_locations()` w tle (zweryfikowane w kodzie ACF Pro tej
 * instalacji), więc grupa pól scope'uje się do pozycji przypisanego menu
 * niezależnie od tego, jak redaktor nazwie samo menu w Wygląd → Menu.
 */
final class MenuItemFields {

	/**
	 * Klucz grupy pól (ACF wymaga unikalnego klucza `group_…`).
	 */
	private const GROUP_KEY = 'group_qutlet_header_menu_item';

	/**
	 * Slug lokalizacji menu kategorii — patrz „Literał-most między repo" w
	 * docblocku klasy. MUSI być identyczny z pierwszym argumentem
	 * `register_nav_menu()` w `qutlet-theme` (P-16.2b).
	 */
	private const LOCATION_KATEGORIE = 'kategorie';

	/**
	 * Klucz pola `widoczna_na_belce`.
	 */
	private const FIELD_KEY_WIDOCZNA_NA_BELCE = 'field_qutlet_widoczna_na_belce';

	/**
	 * Klucz pola `grupa_mega_menu`.
	 */
	private const FIELD_KEY_GRUPA_MEGA_MENU = 'field_qutlet_grupa_mega_menu';

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
	 * Rejestruje grupę pól ACF na pozycjach menu lokalizacji `kategorie`.
	 *
	 * @return void
	 */
	public static function register(): void {
		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP_KEY,
				'title'                 => __( 'Qutlet — pozycja menu kategorii', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'           => self::FIELD_KEY_WIDOCZNA_NA_BELCE,
						'label'         => __( 'Widoczna od razu na belce', 'qutlet-core' ),
						'name'          => 'widoczna_na_belce',
						'type'          => 'true_false',
						'instructions'  => __( 'Zaznaczone: pozycja pojawia się TAKŻE jako stała pigułka nad mega menu (i w sekcji „Kategorie" mobilnego menu), NIEZALEŻNIE od przynależności do kolumny poniżej. Odznaczone: pozycja istnieje wyłącznie wewnątrz swojej kolumny mega menu.', 'qutlet-core' ),
						'required'      => 0,
						'default_value' => 0,
						'ui'            => 1,
					),
					array(
						'key'           => self::FIELD_KEY_GRUPA_MEGA_MENU,
						'label'         => __( 'Grupa mega menu (kolumna)', 'qutlet-core' ),
						'name'          => 'grupa_mega_menu',
						'type'          => 'taxonomy',
						'instructions'  => __( 'Kolumna mega menu, do której należy ta pozycja. Wymagane — każda pozycja menu kategorii musi trafić do dokładnie jednej kolumny. Zarządzanie listą kolumn: Wygląd → Grupy mega menu.', 'qutlet-core' ),
						'required'      => 1,
						'taxonomy'      => MegaMenuGroupTaxonomy::TAXONOMY,
						'field_type'    => 'select',
						'add_term'      => 0, // Nowe grupy TYLKO przez ekran „Wygląd → Grupy mega menu" — nie ad-hoc z edycji pozycji menu.
						'save_terms'    => 1,
						'load_terms'    => 1,
						'multiple'      => 0,
						'allow_null'    => 0,
						'return_format' => 'id',
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'nav_menu_item',
							'operator' => '==',
							'value'    => 'location/' . self::LOCATION_KATEGORIE,
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => __( 'Pola pozycji menu kategorii (mega menu) — rejestruje qutlet-core (P-16.2a).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
