<?php
/**
 * Slice ProductInfo — rejestracja warstwy SUROWEJ produktu (P-5.1b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductInfo;

/**
 * Rejestruje pola WARSTWY SUROWEJ na produkcie WooCommerce (`post_type == product`).
 *
 * Warstwa surowa = wierna kopia tego, co przyszło z Allegro (D-5.G4). Ukryta na
 * froncie (motyw jej nie czyta — D-5.G3/D-8.G1), w adminie tylko do odczytu,
 * NADPISYWANA przy każdym sync (producent = `qutlet-allegro`, feature rozproszony,
 * ta sama nazwa slice'a `ProductInfo/`). Sens: kontekst dla AI (FAZA 7) i zasiew
 * sandboxa (FAZA 3A).
 *
 * Pola (literały z `docs/kontrakt-danych.md` §9.1 — VERBATIM, case-sensitive):
 * - `_qutlet_allegro_offer`             — pełna oferta Allegro JSON verbatim (string).
 * - `_qutlet_allegro_description_raw`   — opis prozą wyprowadzony z JSON-a (string/HTML).
 * - `_qutlet_allegro_specification_raw` — specyfikacja parsed, tablica {etykieta, wartosc}.
 *
 * Mechanizm: `register_post_meta()` (D-5.G4) — NIE ACF. ACF to narzędzie do
 * *edycji*, a tych pól nikt nie edytuje. Prefiks `_qutlet_` = meta prywatna
 * (`is_protected_meta`, ukryta w UI „Custom Fields", jak `_qutlet_reading_time`).
 * Edycja przez użytkownika zablokowana (`auth_callback` → false); sync zapisuje
 * bezpośrednio przez `update_post_meta()`, które `auth_callback` nie dotyczy.
 * `show_in_rest = false` — warstwa niewidoczna publicznie.
 *
 * KTO wypełnia te pola: sync z Allegro (FAZA 6), nie ten slice. P-5.1b tylko je
 * REJESTRUJE (deklaruje istnienie, typ i kształt jako kontrakt dla producenta).
 */
final class RawLayerMeta {

	/**
	 * `meta_key` pełnej oferty Allegro (JSON verbatim) — kontrakt §9.1 (VERBATIM).
	 */
	public const META_OFFER = '_qutlet_allegro_offer';

	/**
	 * `meta_key` opisu prozą wyprowadzonego z oferty — kontrakt §9.1 (VERBATIM).
	 */
	public const META_DESCRIPTION_RAW = '_qutlet_allegro_description_raw';

	/**
	 * `meta_key` specyfikacji parsed (tablica etykieta→wartość) — kontrakt §9.1 (VERBATIM).
	 */
	public const META_SPECIFICATION_RAW = '_qutlet_allegro_specification_raw';

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
	 * Rejestruje trzy pola warstwy surowej jako prywatne, nieedytowalne post meta.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_OFFER,
			array(
				'type'              => 'string',
				'description'       => 'Pełna oferta Allegro (JSON, verbatim). Warstwa surowa — nadpisywana przy sync.',
				'single'            => true,
				'show_in_rest'      => false,
				// R/O dla użytkownika: sync pisze przez update_post_meta(), które
				// auth_callback pomija. Verbatim → bez sanitize (nie zniekształcamy JSON-a).
				'auth_callback'     => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_DESCRIPTION_RAW,
			array(
				'type'          => 'string',
				'description'   => 'Opis prozą wyprowadzony z oferty Allegro. Warstwa surowa — nadpisywana przy sync.',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SPECIFICATION_RAW,
			array(
				'type'              => 'array',
				'description'       => 'Specyfikacja parsed (tablica {etykieta, wartosc}) z oferty Allegro. Warstwa surowa — nadpisywana przy sync.',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => '__return_false',
				'sanitize_callback' => array( self::class, 'sanitize_specification' ),
			)
		);
	}

	/**
	 * Sanityzuje surową specyfikację do zadeklarowanego kształtu (kontrakt §9.1):
	 * lista par `{ etykieta: string, wartosc: string }`. Wpisy o złej strukturze są
	 * odrzucane, a etykieta/wartość sprowadzone do czystego tekstu. Nie-tablica → `[]`.
	 *
	 * Producentem danych jest zaufany sync (FAZA 6); to lekki bezpiecznik kształtu,
	 * nie parser — logika ekstrakcji z oferty mieszka po stronie sync.
	 *
	 * @param mixed $value Wartość przekazana do `update_post_meta`.
	 * @return array<int, array{etykieta: string, wartosc: string}> Znormalizowana lista par.
	 */
	public static function sanitize_specification( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['etykieta'], $row['wartosc'] ) ) {
				continue;
			}

			$clean[] = array(
				'etykieta' => sanitize_text_field( (string) $row['etykieta'] ),
				'wartosc'  => sanitize_text_field( (string) $row['wartosc'] ),
			);
		}

		return $clean;
	}
}
