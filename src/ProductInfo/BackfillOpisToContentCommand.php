<?php
/**
 * Slice ProductInfo — jednorazowy backfill opisu ACF → post_content (P-13.3a, D-13.G3).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductInfo;

use WP_CLI;
use WP_Post;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-core backfill-opis-to-content` — jednorazowa migracja: kopiuje wartości
 * wycofanego pola ACF `opis` (`field_qutlet_opis`, {@see RewrittenFields}, usunięte z
 * rejestracji w P-13.3a) do natywnego `post_content`, żeby produkty zsynchronizowane
 * PRZED P-13.3a nie straciły opisu, gdy motyw przełączy się na `the_content()`
 * (`qutlet-theme`, P-13.3c). Decyzja o migracji (zamiast pozostawienia `opis` jako
 * nieużywanego pola): D-13.G3, `docs/plan.md` → FAZA 13.
 *
 * ## Czysto lokalna operacja
 * Operuje wyłącznie na już zapisanych meta produktów — zero żądań HTTP do Allegro.
 *
 * ## Zakres i idempotencja
 * Zapytanie filtruje kandydatów meta_query (`opis` niepuste). Po migracji meta `opis` i
 * referencja ACF `_opis` są kasowane — powtórne uruchomienie nie znajdzie już nic do
 * zrobienia (bezpieczne, wielokrotne). Produkt, którego `post_content` jest JUŻ niepuste
 * (ręcznie wypełnione), jest POMIJANY (konflikt) — migracja nigdy nie nadpisuje istniejącej
 * treści; wymaga ręcznej weryfikacji, zgłoszonej w logu. Status `any` (bez kosza) — jak
 * pozostałe komendy backfill repo (wzorzec `qutlet-allegro`
 * `BackfillOrderAttributionCommand`, D-6.2.1/D-6.3.4).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class BackfillOpisToContentCommand {

	/**
	 * `meta_key` wycofanego pola ACF `opis` (VERBATIM z {@see RewrittenFields}, kontrakt
	 * §9.2 — obowiązywał do P-13.3a).
	 */
	private const META_OPIS = 'opis';

	/**
	 * Meta referencji ACF pola `opis` (przedrostek `_`) — bez niej ACF traktowałby
	 * pozostawioną wartość jak „dummy" (patrz {@see RewriteWriter} w `qutlet-ai`, ten sam
	 * mechanizm przy zapisie). Kasowana razem z wartością — pole wycofane.
	 */
	private const META_OPIS_REF = '_opis';

	/**
	 * Rozmiar strony iteracji produktów.
	 */
	private const PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (wzorzec pozostałych komend backfill repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Migruje istniejące wartości `opis` (ACF) do `post_content` na produktach, które
	 * jeszcze nie mają natywnego opisu.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Policz kandydatów i pokaż ich id — bez jednego zapisu (wzorzec innych komend backfill).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-core backfill-opis-to-content --dry-run
	 *     wp qutlet-core backfill-opis-to-content
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run  = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$checked  = 0;
		$migrated = 0;
		$conflict = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any', // bez kosza (D-6.2.1/D-6.3.4) — wzorzec innych komend backfill.
					'posts_per_page' => self::PAGE_LIMIT,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- jednorazowa migracja (D-13.G3), nie ścieżka na krytycznym torze.
						array(
							'key'     => self::META_OPIS,
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

				$post = get_post( $product_id );

				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				if ( '' !== trim( (string) $post->post_content ) ) {
					++$conflict;
					WP_CLI::warning( sprintf( 'Produkt %d: post_content już niepuste — pominięto (opis ACF zostaje nietknięty), wymaga ręcznej weryfikacji.', $product_id ) );

					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log( sprintf( '  (dry-run) produkt %d dostałby post_content z pola opis.', $product_id ) );

					continue;
				}

				$opis = wp_kses_post( (string) get_post_meta( $product_id, self::META_OPIS, true ) );

				$updated = wp_update_post(
					array(
						'ID'           => $product_id,
						'post_content' => $opis,
					),
					true
				);

				if ( is_wp_error( $updated ) ) {
					++$conflict;
					WP_CLI::warning( sprintf( 'Produkt %d: zapis post_content nie powiódł się (%s) — opis ACF pozostawiony, wymaga ręcznej weryfikacji.', $product_id, $updated->get_error_message() ) );

					continue;
				}

				// Referencja ACF (`_opis`) kasowana razem z wartością — pole wycofane, brak
				// dalszych odczytów `get_field('opis')` (patrz docblock klasy).
				delete_post_meta( $product_id, self::META_OPIS );
				delete_post_meta( $product_id, self::META_OPIS_REF );

				++$migrated;
			}

			if ( count( $product_ids ) < self::PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano backfill na bezpieczniku %d stron — uruchom komendę ponownie (idempotentna, dokończy resztę).', self::MAX_PAGES ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'backfill-opis-to-content (dry-run): %d znaleziono, %d dostałoby migrację, %d konflikt (post_content już niepuste).', $checked, $checked - $conflict, $conflict ) );

			return;
		}

		WP_CLI::success( sprintf( 'backfill-opis-to-content: sprawdzone %d, zmigrowane %d, konflikt %d.', $checked, $migrated, $conflict ) );
	}
}
