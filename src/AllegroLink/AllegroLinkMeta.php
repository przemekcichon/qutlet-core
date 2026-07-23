<?php
/**
 * Slice AllegroLink — rejestracja dyskretnych pól nie-Woo z mappingu (P-5.2b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AllegroLink;

/**
 * Rejestruje DYSKRETNE pola nie-Woo z Allegro na produkcie WooCommerce
 * (`post_type == product`) — te, które „zarabiają" na osobną rejestrację, bo muszą
 * być indeksowalne/wyszukiwalne albo wystawione niezależnie od blobu oferty
 * (`_qutlet_allegro_offer`, §9.1). Reszta pól oferty zostaje w verbatim JSON albo
 * idzie natywnie do Woo (GTIN → `global_unique_id`, VAT → podatek Woo) — patrz
 * kontrakt §10.2/§10.3. To NIE opis/specyfikacja (tamto = slice `ProductInfo/`, §9).
 *
 * Grupują je „tożsamość i powiązanie produktu z jego źródłem w Allegro" (D-5.2.4),
 * stąd osobny slice `AllegroLink/` (mirror w qutlet-allegro przy sync — feature
 * rozproszony, ta sama nazwa slice'a). NIE mylić z `AllegroChannel/` (przejściowy
 * drugi kanał zakupu, §4).
 *
 * Pola (literały z `docs/kontrakt-danych.md` §10.1 — VERBATIM, case-sensitive):
 * - `_qutlet_allegro_offer_id`      — id oferty, klucz powiązania Woo↔Allegro (string).
 * - `_qutlet_mpn`                   — kod producenta (MPN), rodzeństwo GTIN (string).
 * - `_qutlet_allegro_category_id`   — źródłowa kategoria Allegro (liść), opaque (string).
 * - `_qutlet_allegro_category_path` — ścieżka przodków liść→korzeń, tablica {id, name}.
 *
 * Mechanizm: `register_post_meta()` (D-5.2.3) — NIE ACF. To fakty z Allegro, nie
 * treść autorska; ACF to narzędzie do *edycji*, a tych pól nikt nie edytuje.
 * Prefiks `_qutlet_` = meta prywatna (`is_protected_meta`, ukryta w UI „Custom
 * Fields", jak warstwa surowa §9.1). Edycja przez użytkownika zablokowana
 * (`auth_callback` → false); sync zapisuje bezpośrednio przez `update_post_meta()`,
 * które `auth_callback` nie dotyczy. `show_in_rest = false` — pola niewidoczne
 * publicznie. Nadpisywane przy każdym sync.
 *
 * KTO wypełnia te pola: sync z Allegro (FAZA 6), nie ten slice. P-5.2b tylko je
 * REJESTRUJE (deklaruje istnienie, typ i kształt jako kontrakt dla producenta).
 */
final class AllegroLinkMeta {

	/**
	 * `meta_key` id oferty Allegro (klucz powiązania Woo↔Allegro) — kontrakt §10.1 (VERBATIM).
	 */
	public const META_OFFER_ID = '_qutlet_allegro_offer_id';

	/**
	 * `meta_key` kodu producenta (MPN) — kontrakt §10.1 (VERBATIM). Bez infiksu
	 * `allegro`: MPN to identyfikator producenta intrinsyczny dla produktu, nie id Allegro.
	 */
	public const META_MPN = '_qutlet_mpn';

	/**
	 * `meta_key` źródłowej kategorii Allegro (liść) — kontrakt §10.1 (VERBATIM).
	 */
	public const META_CATEGORY_ID = '_qutlet_allegro_category_id';

	/**
	 * `meta_key` ścieżki przodków kategorii (liść→korzeń) — kontrakt §10.1 (VERBATIM).
	 */
	public const META_CATEGORY_PATH = '_qutlet_allegro_category_path';

	/**
	 * Typ obiektu (WooCommerce produkt to natywny CPT `product`).
	 */
	private const POST_TYPE = 'product';

	/**
	 * Wpina rejestrację na `init`. Meta rejestrujemy na `init` (zalecenie WP), a nie
	 * na `plugins_loaded`: typ `product` rejestruje WooCommerce właśnie na `init`
	 * (priorytet 5), więc przy domyślnym priorytecie 10 CPT już istnieje. Wołane z
	 * bootstrapu core (na `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Rejestruje cztery pola dyskretne jako prywatne, nieedytowalne post meta.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_OFFER_ID,
			array(
				'type'          => 'string',
				'description'   => 'Id oferty Allegro (klucz powiązania Woo↔Allegro, opaque string). Nadpisywane przy sync.',
				'single'        => true,
				'show_in_rest'  => false,
				// R/O dla użytkownika: sync pisze przez update_post_meta(), które
				// auth_callback pomija. Opaque string → bez sanitize (nie zniekształcamy id).
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_MPN,
			array(
				'type'          => 'string',
				'description'   => 'Kod producenta (MPN), rodzeństwo GTIN. Nadpisywane przy sync.',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CATEGORY_ID,
			array(
				'type'          => 'string',
				'description'   => 'Źródłowa kategoria Allegro (liść), opaque string. Ślad źródła, NIE zastępuje product_cat. Nadpisywane przy sync.',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CATEGORY_PATH,
			array(
				'type'              => 'array',
				'description'       => 'Ścieżka przodków kategorii Allegro (liść→korzeń), tablica {id, name}. Nadpisywane przy sync.',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => '__return_false',
				'sanitize_callback' => array( self::class, 'sanitize_category_path' ),
			)
		);
	}

	/**
	 * Sanityzuje ścieżkę kategorii do zadeklarowanego kształtu (kontrakt §10.1):
	 * lista węzłów `{ id: string, name: string }` w kolejności liść→korzeń. Wpisy o
	 * złej strukturze są odrzucane, a id/name sprowadzone do czystego tekstu (id bywa
	 * numeryczne albo UUID — oba to stringi). Kolejność zachowana. Nie-tablica → `[]`.
	 *
	 * Producentem danych jest zaufany sync (FAZA 6); to lekki bezpiecznik kształtu,
	 * nie parser — rozdzielczość drzewa (id→nazwa, cache) mieszka po stronie sync.
	 *
	 * @param mixed $value Wartość przekazana do `update_post_meta`.
	 * @return array<int, array{id: string, name: string}> Znormalizowana ścieżka liść→korzeń.
	 */
	public static function sanitize_category_path( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['id'], $node['name'] ) ) {
				continue;
			}

			$clean[] = array(
				'id'   => sanitize_text_field( (string) $node['id'] ),
				'name' => sanitize_text_field( (string) $node['name'] ),
			);
		}

		return $clean;
	}
}
