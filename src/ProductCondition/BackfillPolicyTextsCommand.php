<?php
/**
 * Slice ProductCondition — backfill dzisiejszej treści do nowych pól tekstów
 * polityk (P-22.5).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-core backfill-teksty-polityk-klasa-stanu` — jednorazowo wypełnia
 * 12 nowych pól tekstów polityk ({@see ClassDefinitionsTaxonomy::register_fields()},
 * D-22.5.1/D-22.5.2) dzisiejszą treścią, dziś zaszytą jako hardkodowane
 * literały w `qutlet-theme\content-single-product.php` (ground-truth
 * `docs/plan.md` P-22.5, sesja 2026-08-19). Seedowana treść jest identyczna
 * dla A-D — różnicowanie per klasa to przyszła praca redakcyjna admina
 * (ten sam wzorzec co `dlaczego_taniej`, P-12.1a).
 *
 * ## Idempotencja
 * Działa PO POLU, nie po termie: pole już wypełnione (niepuste) → pomijane,
 * niezależnie od pozostałych pól tego samego termu — nie nadpisuje ręcznej
 * edycji admina, ale i nie blokuje backfillu pól, których admin jeszcze nie
 * dotknął. Klasa „Nowe" (jeśli istnieje) NIE jest tu seedowana — tak samo jak
 * {@see SeedClassDefinitionsCommand} nie seeduje jej pozostałych pól
 * (D-12.1a.3), admin wypełnia ją ręcznie.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (jak pozostałe
 * komendy repo).
 */
final class BackfillPolicyTextsCommand {

	/**
	 * Dzisiejsza treść (identyczna dla A-D) — literały skopiowane z
	 * `qutlet-theme` (ground-truth, patrz docblock klasy). Placeholder
	 * `{okres}` w `gwarancja_opis`/`reklamacja_opis` podstawia motyw przy
	 * renderze (`ProductPage::period_years_text()`).
	 *
	 * @var array<string, string>
	 */
	private const SEED_DATA = array(
		'zwrot_naglowek'               => '14 dni na zwrot',
		'zwrot_tag_qutlet'             => 'Koszt po Twojej stronie',
		'zwrot_tag_allegro'            => 'Możliwy bezpłatny',
		'wysylka_naglowek'             => 'Wysyłka w 1 dzień roboczy',
		'zwrot_opis_qutlet'            => 'W razie zwrotu produktu kupionego w naszym sklepie, koszty przesyłki zwrotnej pokrywasz sam.',
		'zwrot_opis_allegro'           => 'Zwrot całkowicie bezpłatny przy wyborze Allegro Delivery oraz dla Allegrowiczów Smart.',
		'wysylka_opis'                 => 'Wysyłamy w najbliższy dzień roboczy (sesja rano/popołudnie).',
		'zwrot_akordeon_opis_qutlet'   => '14 dni na zmianę zdania. Koszt przesyłki zwrotnej po stronie kupującego.',
		'zwrot_akordeon_opis_allegro'  => '14 dni na zmianę zdania. Zwrot bezpłatny — przy wyborze Allegro Delivery lub abonamentu Smart.',
		'gwarancja_opis'               => '{okres} gwarancji na każdy produkt. Reklamacje realizujemy w naszym serwisie — szybko i bezproblemowo.',
		'reklamacja_opis'              => '{okres} (zamiast ustawowych 2 lat — dopuszczalne dla towarów używanych, gdy kupujący zostanie wyraźnie poinformowany).',
		'stan_uzywany_opis'            => 'Gwarancja i prawo do reklamacji są identyczne dla każdego egzemplarza.',
	);

	/**
	 * Klasy, które dostają backfill (D-12.1a.3 — „Nowe" wyłączona, wypełniana
	 * ręcznie przez admina, jak pozostałe pola bytu).
	 *
	 * @var list<string>
	 */
	private const SEEDED_KODY = array( 'A', 'B', 'C', 'D' );

	/**
	 * Wypełnia puste pola tekstów polityk dla klas A-D.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Pokaż, co zostałoby wypełnione — bez jednego zapisu (wzorzec innych komend backfill/seed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-core backfill-teksty-polityk-klasa-stanu --dry-run
	 *     wp qutlet-core backfill-teksty-polityk-klasa-stanu
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run   = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$existing  = ClassDefinitionsTaxonomy::all();
		$filled    = 0;
		$skipped   = 0;

		foreach ( self::SEEDED_KODY as $kod ) {
			if ( ! isset( $existing[ $kod ] ) ) {
				WP_CLI::warning( sprintf( 'Klasa o kodzie „%s" nie istnieje jeszcze w taksonomii — pomijam.', $kod ) );

				continue;
			}

			$term_id = $existing[ $kod ]['term_id'];

			foreach ( self::SEED_DATA as $meta_key => $default_value ) {
				$current = (string) get_term_meta( $term_id, $meta_key, true );

				if ( '' !== $current ) {
					++$skipped;

					continue;
				}

				if ( $dry_run ) {
					++$filled;
					WP_CLI::log( sprintf( '  (dry-run) wypełniłby „%s" dla klasy „%s" (kod „%s").', $meta_key, $existing[ $kod ]['nazwa'], $kod ) );

					continue;
				}

				update_term_meta( $term_id, $meta_key, $default_value );
				++$filled;
				WP_CLI::log( sprintf( '  wypełniono „%s" dla klasy „%s" (kod „%s").', $meta_key, $existing[ $kod ]['nazwa'], $kod ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'backfill-teksty-polityk-klasa-stanu (dry-run): %d wypełniłoby się, %d już wypełnionych.', $filled, $skipped ) );

			return;
		}

		WP_CLI::success( sprintf( 'backfill-teksty-polityk-klasa-stanu: wypełniono %d, pominięto (już wypełnione) %d.', $filled, $skipped ) );
	}
}
