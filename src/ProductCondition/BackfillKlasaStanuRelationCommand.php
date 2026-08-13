<?php
/**
 * Slice ProductCondition — jednorazowy backfill relacji „klasa stanu" (P-12.2a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-core backfill-klasa-stanu-relacja` — jednorazowo ustawia REALNĄ
 * relację `wp_set_object_terms()` między produktem a {@see
 * ClassDefinitionsTaxonomy} dla każdego produktu, który ma dziś TYLKO
 * historyczny literał w postmeta `klasa_stanu` (`A`-`D`, `Nowe` — zapisywany
 * przez `qutlet-allegro\OfferSync\ProductWriter` PRZED cutoverem P-12.2a) i
 * jeszcze żadnej relacji z tą taksonomią.
 *
 * ## Dlaczego to nie jest opcjonalne (uwaga operacyjna, patrz docblock
 * {@see ClassDefinitionsTaxonomy})
 * {@see ProductConditionFields} zmienia typ pola `klasa_stanu` z ACF `select`
 * na `taxonomy` (`load_terms`/`required` włączone) — od tej zmiany ACF czyta
 * wybraną klasę WYŁĄCZNIE z relacji, nie z postmeta. Produkt bez relacji
 * (czyli WSZYSTKIE już zaimportowane produkty przed pierwszym uruchomieniem
 * tej komendy) pokaże PUSTY dropdown na ekranie edycji — zapis formularza
 * (z JAKIEGOKOLWIEK powodu, nie tylko zmiany klasy) BLOKUJE się na walidacji
 * ACF „wartość jest wymagana" (zmierzone runtime, `docs/plan.md` P-12.2a),
 * baza zostaje NIENARUSZONA. Skutek to wymuszona reklasyfikacja i
 * zablokowana edycja produktu, NIE utrata danych — ale i to jest powód, żeby
 * komenda przebiegła NATYCHMIAST po wdrożeniu tej zmiany w każdym
 * środowisku, PRZED jakimkolwiek zapisem ekranu edycji produktu.
 *
 * ## Nie jest to migracja per-produkt wartości `klasa_stanu`
 * Literał w postmeta NIE jest zmieniany ani kasowany przez tę komendę —
 * dokładany jest WYŁĄCZNIE brakujący wpis w tabeli relacji terminów
 * (`wp_set_object_terms()`). Kod nieznany bytowi {@see ClassDefinitionsTaxonomy}
 * (np. `Nowe` przed ręcznym seedem termu, D-12.1c.1) jest POMIJANY z
 * ostrzeżeniem — degradacja bezpieczna, nie fatal.
 *
 * ## Idempotencja
 * Produkt, który ma JUŻ jakąkolwiek relację z {@see ClassDefinitionsTaxonomy::TAXONOMY}
 * (niezależnie czy zgodną z literałem), jest POMIJANY — komenda nigdy nie
 * nadpisuje istniejącej relacji (bezpieczne, wielokrotne uruchomienie).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class BackfillKlasaStanuRelationCommand {

	/**
	 * `meta_key` historycznego literału `klasa_stanu` (VERBATIM, kontrakt §2).
	 */
	private const CONDITION_META = 'klasa_stanu';

	/**
	 * Rozmiar strony iteracji produktów.
	 */
	private const PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (wzorzec pozostałych komend backfill repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Ustawia relację `klasa_stanu_definicja` dla produktów, które mają dziś
	 * TYLKO historyczny literał `klasa_stanu`, bez relacji.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Policz kandydatów i pokaż, co zostałoby zrelacjonowane — bez jednego zapisu.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-core backfill-klasa-stanu-relacja --dry-run
	 *     wp qutlet-core backfill-klasa-stanu-relacja
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run       = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$checked       = 0;
		$related       = 0;
		$already       = 0;
		$unknown_kod   = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any', // Bez kosza (wzorzec pozostałych komend backfill repo).
					'posts_per_page' => self::PAGE_LIMIT,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- jednorazowa migracja (P-12.2a), nie ścieżka na krytycznym torze.
						array(
							'key'     => self::CONDITION_META,
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);

			if ( array() === $product_ids ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				$product_id = (int) $product_id;
				++$checked;

				$existing_terms = wp_get_object_terms(
					$product_id,
					ClassDefinitionsTaxonomy::TAXONOMY,
					array( 'fields' => 'ids' )
				);

				if ( is_wp_error( $existing_terms ) ) {
					$existing_terms = array();
				}

				if ( array() !== $existing_terms ) {
					++$already;

					continue; // Relacja już istnieje — nie nadpisujemy (idempotencja).
				}

				$kod = trim( (string) get_post_meta( $product_id, self::CONDITION_META, true ) );

				if ( '' === $kod ) {
					continue; // meta_query dopasowuje != '', ale trim() może to jeszcze zmienić — guard.
				}

				$definicja = ClassDefinitionsTaxonomy::get( $kod );

				if ( null === $definicja ) {
					++$unknown_kod;
					WP_CLI::warning( sprintf( 'Produkt %d: kod „%s" nie ma definicji w taksonomii (term jeszcze nie wyseedowany) — pominięto, wymaga ręcznego dodania klasy.', $product_id, $kod ) );

					continue;
				}

				if ( $dry_run ) {
					++$related;
					WP_CLI::log( sprintf( '  (dry-run) produkt %d dostałby relację z klasą „%s" (kod „%s", term_id %d).', $product_id, $definicja['nazwa'], $kod, $definicja['term_id'] ) );

					continue;
				}

				wp_set_object_terms( $product_id, array( $definicja['term_id'] ), ClassDefinitionsTaxonomy::TAXONOMY, false );

				++$related;
				WP_CLI::log( sprintf( '  produkt %d: relacja z klasą „%s" (kod „%s", term_id %d) ustawiona.', $product_id, $definicja['nazwa'], $kod, $definicja['term_id'] ) );
			}

			if ( count( $product_ids ) < self::PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano backfill na bezpieczniku %d stron — uruchom komendę ponownie (idempotentna, dokończy resztę).', self::MAX_PAGES ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'backfill-klasa-stanu-relacja (dry-run): sprawdzono %d, %d dostałoby relację, %d już miało relację, %d nieznany kod.', $checked, $related, $already, $unknown_kod ) );

			return;
		}

		WP_CLI::success( sprintf( 'backfill-klasa-stanu-relacja: sprawdzono %d, zrelacjonowano %d, już miało relację %d, nieznany kod %d.', $checked, $related, $already, $unknown_kod ) );
	}
}
