<?php
/**
 * Slice HeaderMenu — byt „grupa mega menu" (kolumna), P-16.2a.
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\HeaderMenu;

/**
 * Rejestruje taksonomię `mega_menu_grupa` — rozszerzalny byt niosący LISTĘ
 * kolumn mega menu kategorii (dziś 4 na sztywno w kodzie: „Mobile i noszone" /
 * „Komputery" / „Audio i Foto" / „Dom i gaming", docelowo maks. 6, D-16.G3).
 * Wzorem {@see \Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy} (§2.2,
 * D-16.G2) — admin zarządza nazwami/kolejnością kolumn przez ekran WP, bez
 * zmiany kodu. `docs/kontrakt-danych.md` §14.3.
 *
 * Różnica wobec wzorca `ClassDefinitionsTaxonomy`: tam `object_type` to
 * `product` (taksonomia dostaje darmowy ekran „Produkty → Klasy stanu", bo
 * `product` jest zwykłym CPT w pętli, która automatycznie dokłada submenu
 * taksonomii pod ekranem post typu — `wp-admin/menu.php`). Tu `object_type`
 * to `nav_menu_item` — wbudowany CPT WP z `show_ui => false`, który NIGDY nie
 * trafia do tamtej pętli (`get_post_types(['show_ui' => true, '_builtin' =>
 * false, …])` + osobny hardkodowany `['post', 'page']`), więc WordPress core
 * NIE dokłada submenu automatycznie niezależnie od `show_in_menu` (zweryfikowane
 * w `wp-admin/menu.php` tej instalacji — jedyna pętla łącząca taksonomię z
 * post typem po `object_type` iteruje WYŁĄCZNIE po tamtej liście). Ekran
 * `edit-tags.php?taxonomy=mega_menu_grupa` działa (wymaga tylko `show_ui`,
 * `wp-admin/edit-tags.php`), ale bez WŁASNEGO wpisu w menu admina byłby
 * osiągalny wyłącznie przez wpisanie URL-a ręcznie. {@see
 * self::register_admin_submenu()} dokłada go RĘCZNIE (ten sam mechanizm,
 * którym WordPress core sam wpina submenu CPT-ów o `show_in_menu` jako string
 * — `_add_post_type_submenus()`, `wp-includes/post.php` — dla taksonomii core
 * tego nie robi, więc slice musi).
 *
 * **Uwaga vs. plan (`docs/plan.md`/kontrakt §14.3 szkicowały `show_in_menu =>
 * 'nav-menus.php'` bezpośrednio w `register_taxonomy()`):** ground-truth tej
 * sesji (czytanie `wp-admin/menu.php` + `class-wp-taxonomy.php`) i PHPStan
 * (stuby WP typują `show_in_menu` taksonomii ŚCIŚLE jako `bool` — w
 * odróżnieniu od CPT, gdzie `bool|string` jest poprawnym typem) potwierdzają,
 * że string nie ma tu żadnego efektu (WordPress core NIE czyta go jako
 * parent-slug dla taksonomii, wyłącznie dla post typów, patrz akapit wyżej).
 * Rejestrujemy więc `show_in_menu => true` (włącza wyłącznie bool-owy warunek
 * „pokaż UI" z `wp-admin/menu.php`, który i tak nigdy się nie uruchomi dla tej
 * taksonomii z powodu opisanego wyżej) — faktyczne umieszczenie „zagnieżdżony
 * pod Wygląd, obok Menu" (kontrakt §14.3) realizuje WYŁĄCZNIE
 * {@see self::register_admin_submenu()} przez `self::ADMIN_PARENT_SLUG`. Zero
 * zmiany zachowania widocznego dla admina — czysta korekta wewnętrznego
 * argumentu WP, żaden literał danych (nazwy pól/taksonomii) się nie zmienia.
 */
final class MegaMenuGroupTaxonomy {

	/**
	 * Nazwa taksonomii (max 32 znaki — limit WP).
	 */
	public const TAXONOMY = 'mega_menu_grupa';

	/**
	 * Slug TOP-LEVEL rodzica w menu admina — Wygląd (D-16.G2/kontrakt §14.3:
	 * „zagnieżdżony pod Wygląd, obok Menu", NIE pod Produkty jak
	 * `klasa_stanu_definicja`). MUSI być top-level slug (`themes.php`), NIE
	 * `nav-menus.php` — `nav-menus.php` to SAMO submenu „Menu" pod `themes.php`
	 * (`$submenu['themes.php'][10] = […, 'nav-menus.php']`, `wp-admin/menu.php`
	 * tej instalacji), a `add_submenu_page()` (`wp-admin/includes/plugin.php`)
	 * dopisuje WYŁĄCZNIE do `$submenu[$parent_slug]`, gdzie `$parent_slug` musi
	 * być kluczem `$menu` (top-level) — sidebar admina renderuje `$submenu[...]`
	 * TYLKO dla top-level slugów z `$menu`, więc wpis pod `nav-menus.php` ląduje
	 * w tablicy, którą nic nigdy nie odczytuje (zero błędu, po prostu niewidoczny
	 * link — realny bug tej sesji, zgłoszony przez użytkownika po ręcznym
	 * sprawdzeniu Wygląd w adminie, P-16.2a nie zweryfikowało tego runtime).
	 * Jedyne miejsce użycia: {@see self::register_admin_submenu()} —
	 * `register_taxonomy()` dostaje zwykłe `show_in_menu => true` (patrz „Uwaga
	 * vs. plan" w docblocku klasy, czemu NIE string).
	 */
	private const ADMIN_PARENT_SLUG = 'themes.php';

	/**
	 * Klucz grupy pól ACF (term meta).
	 */
	private const GROUP_KEY = 'group_qutlet_mega_menu_grupa';

	/**
	 * Klucz pola `kolejnosc` (term meta, §14.3).
	 */
	private const FIELD_KEY_KOLEJNOSC = 'field_qutlet_mega_menu_grupa_kolejnosc';

	/**
	 * Wpina rejestrację taksonomii, grupy pól ACF i wpisu w menu admina.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_taxonomy' ) );
		add_action( 'acf/init', array( self::class, 'register_fields' ) );
		add_action( 'admin_menu', array( self::class, 'register_admin_submenu' ) );
	}

	/**
	 * Rejestruje taksonomię. `meta_box_cb` wyłączony (jak
	 * `ClassDefinitionsTaxonomy`) — przypisanie pozycji menu do grupy dzieje
	 * się WYŁĄCZNIE przez pole ACF `grupa_mega_menu` ({@see MenuItemFields}),
	 * nie przez natywny metabox taksonomii (którego `nav_menu_item` i tak nie
	 * ma standardowego ekranu edycji do wyświetlenia, patrz kontrakt §14.3).
	 *
	 * @return void
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( 'nav_menu_item' ),
			array(
				'labels'             => array(
					'name'          => __( 'Grupy mega menu', 'qutlet-core' ),
					'singular_name' => __( 'Grupa mega menu', 'qutlet-core' ),
					'add_new_item'  => __( 'Dodaj grupę mega menu', 'qutlet-core' ),
					'edit_item'     => __( 'Edytuj grupę mega menu', 'qutlet-core' ),
					'menu_name'     => __( 'Grupy mega menu', 'qutlet-core' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'show_admin_column'  => false,
				'show_tagcloud'      => false,
				'show_in_quick_edit' => false,
				'meta_box_cb'        => false,
				'query_var'          => false,
				'rewrite'            => false,
			)
		);
	}

	/**
	 * Dokłada wpis „Grupy mega menu" w menu admina pod Wygląd, obok Menu
	 * (kontrakt §14.3) — patrz docblock klasy, czemu WordPress core NIE robi
	 * tego automatycznie dla taksonomii na `nav_menu_item`. Wołane na
	 * `admin_menu`, PO `init` (na którym rejestruje się taksonomia) — kolejność
	 * hooków WP gwarantuje, że `get_taxonomy()` niżej zwróci już zarejestrowany
	 * byt.
	 *
	 * @return void
	 */
	public static function register_admin_submenu(): void {
		$taxonomy = get_taxonomy( self::TAXONOMY );

		if ( false === $taxonomy ) {
			return; // Taksonomia niezarejestrowana (np. hook init nie odpalił) — nic do dodania.
		}

		add_submenu_page(
			self::ADMIN_PARENT_SLUG,
			__( 'Grupy mega menu', 'qutlet-core' ),
			__( 'Grupy mega menu', 'qutlet-core' ),
			$taxonomy->cap->manage_terms,
			'edit-tags.php?taxonomy=' . self::TAXONOMY
		);
	}

	/**
	 * Rejestruje grupę pól ACF (term meta) na ekranie dodawania/edycji termu
	 * tej taksonomii — lokalizacja `taxonomy` (ACF Pro), wzorem
	 * {@see \Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy::register_fields()}.
	 *
	 * @return void
	 */
	public static function register_fields(): void {
		acf_add_local_field_group(
			array(
				'key'                   => self::GROUP_KEY,
				'title'                 => __( 'Qutlet — grupa mega menu', 'qutlet-core' ),
				'fields'                => array(
					array(
						'key'          => self::FIELD_KEY_KOLEJNOSC,
						'label'        => __( 'Kolejność', 'qutlet-core' ),
						'name'         => 'kolejnosc',
						'type'         => 'number',
						'instructions' => __( 'Pozycja kolumny lewo→prawo w mega menu — taksonomia nie ma natywnego porządku. Docelowo maks. 6 grup dla czytelności menu (wskazówka, nie twardy limit).', 'qutlet-core' ),
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
				'description'           => __( 'Pole kolejności kolumny mega menu — rejestruje qutlet-core (P-16.2a).', 'qutlet-core' ),
				'show_in_rest'          => 0,
			)
		);
	}
}
