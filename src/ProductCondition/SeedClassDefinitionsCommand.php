<?php
/**
 * Slice ProductCondition — jednorazowe seedowanie bytu „klasa stanu" (P-12.1a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-core seed-klasa-stanu` — jednorazowo tworzy w
 * {@see ClassDefinitionsTaxonomy} cztery klasy A-D z dzisiejszą treścią, dziś
 * zaszytą jako PIĘĆ hardkodowanych literałów w `qutlet-theme` (ground-truth
 * `docs/plan.md` FAZA 12, sesja 2026-08-06/12):
 * - nazwa/kolor: `ProductPage::condition_label()` + `style.css:829-832` (`.dot-a…d`);
 * - stan wizualny/charakterystyka: `content-single-product.php:414-419`
 *   (`$classification_rows`);
 * - okres gwarancji/reklamacji: `content-single-product.php:483` („12 miesięcy
 *   gwarancji"), verbatim identyczny dla wszystkich klas dziś (D-12.G3 —
 *   mimo to DWA osobne pola, na wypadek przyszłego rozdzielenia);
 * - „dlaczego taniej": `content-single-product.php:215-224` (`.eco-note`),
 *   dziś JEDEN wspólny tekst dla wszystkich klas — świadomie skopiowany do
 *   każdej z czterech bez zmian (różnicowanie per klasa to przyszła praca
 *   redakcyjna w adminie, nie coś do wymyślenia w tej migracji).
 *
 * ## Nie jest to backfill danych produktu
 * Inaczej niż {@see \Qutlet\Core\ProductInfo\BackfillOpisToContentCommand}: nic
 * na PRODUKTACH się nie zmienia — pole `klasa_stanu` zostaje tym samym literałem
 * (`A`-`D`) w postmeta, zapisywanym/czytanym przez `qutlet-allegro`/`qutlet-theme`
 * bez żadnej zmiany (decyzja o zachowaniu kontraktu wstecz, `docs/plan.md`
 * P-12.1a). „Migracja" (D-12.1a.2) to seedowanie NOWEGO bytu opisowego, z
 * którego `choices` pola `klasa_stanu` są teraz budowane dynamicznie
 * ({@see ProductConditionFields::inject_dynamic_choices()}) — zamiast
 * hardkodowanej tablicy w PHP.
 *
 * ## Idempotencja
 * Termy dopasowywane po term meta `kod` (join key, NIE slug WP — patrz
 * docblock {@see ClassDefinitionsTaxonomy}). Kod już obecny w taksonomii →
 * POMIJANY (nie nadpisuje ręcznych edycji admina) — bezpieczne, wielokrotne
 * uruchomienie.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (jak pozostałe
 * komendy repo).
 */
final class SeedClassDefinitionsCommand {

	/**
	 * Dzisiejsze cztery klasy — literały skopiowane z `qutlet-theme` (ground-truth,
	 * patrz docblock klasy). Kolejność = kolejność w `$classification_rows`.
	 *
	 * @var array<string, array{nazwa: string, kolor: string, opis_chip: string, stan_wizualny: string, charakterystyka: string, dlaczego_taniej: string, okres_gwarancji_miesiace: int, okres_reklamacji_miesiace: int}>
	 */
	private const SEED_DATA = array(
		'A' => array(
			'nazwa'                     => 'Jak nowy',
			'kolor'                     => '#3f9b3f',
			'opis_chip'                 => 'Klasa A · Jak nowy',
			'stan_wizualny'             => 'Jak nowy. Mikroryski.',
			'charakterystyka'           => 'Zwrot konsumencki. Oryginalne pudełko.',
			'dlaczego_taniej'           => 'Skąd niższa cena? To zwrot konsumencki w stanie „jak nowy”. Nie dopłacasz za nieotwierane opakowanie, a kupując używane — ograniczasz e-waste.',
			'okres_gwarancji_miesiace'  => 12,
			'okres_reklamacji_miesiace' => 12,
		),
		'B' => array(
			'nazwa'                     => 'Dobry',
			'kolor'                     => '#9bbd2f',
			'opis_chip'                 => 'Klasa B · Dobry',
			'stan_wizualny'             => 'Dobry. Widoczne ryski.',
			'charakterystyka'           => 'Używany dłużej. Pudełko zastępcze.',
			'dlaczego_taniej'           => 'Skąd niższa cena? To zwrot konsumencki w stanie „jak nowy”. Nie dopłacasz za nieotwierane opakowanie, a kupując używane — ograniczasz e-waste.',
			'okres_gwarancji_miesiace'  => 12,
			'okres_reklamacji_miesiace' => 12,
		),
		'C' => array(
			'nazwa'                     => 'Mocne ślady',
			'kolor'                     => '#e0a32f',
			'opis_chip'                 => 'Klasa C · Mocne ślady',
			'stan_wizualny'             => 'Mocne ślady zużycia.',
			'charakterystyka'           => 'Sprawny technicznie, widoczna historia użytkowania.',
			'dlaczego_taniej'           => 'Skąd niższa cena? To zwrot konsumencki w stanie „jak nowy”. Nie dopłacasz za nieotwierane opakowanie, a kupując używane — ograniczasz e-waste.',
			'okres_gwarancji_miesiace'  => 12,
			'okres_reklamacji_miesiace' => 12,
		),
		'D' => array(
			'nazwa'                     => 'Na części',
			'kolor'                     => '#b07a7a',
			'opis_chip'                 => 'Klasa D · Na części',
			'stan_wizualny'             => 'Na części.',
			'charakterystyka'           => 'Niesprawny technicznie.',
			'dlaczego_taniej'           => 'Skąd niższa cena? To zwrot konsumencki w stanie „jak nowy”. Nie dopłacasz za nieotwierane opakowanie, a kupując używane — ograniczasz e-waste.',
			'okres_gwarancji_miesiace'  => 12,
			'okres_reklamacji_miesiace' => 12,
		),
	);

	/**
	 * Seeduje klasy A-D w {@see ClassDefinitionsTaxonomy}, pomijając kody już obecne.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Pokaż, co zostałoby utworzone/pominięte — bez jednego zapisu (wzorzec innych komend backfill/seed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-core seed-klasa-stanu --dry-run
	 *     wp qutlet-core seed-klasa-stanu
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run  = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$existing = ClassDefinitionsTaxonomy::all();
		$created  = 0;
		$skipped  = 0;

		foreach ( self::SEED_DATA as $kod => $definicja ) {
			if ( isset( $existing[ $kod ] ) ) {
				++$skipped;
				WP_CLI::log( sprintf( '  pominięto „%s" — kod „%s" już istnieje w taksonomii (term_id %d).', $definicja['nazwa'], $kod, $existing[ $kod ]['term_id'] ) );

				continue;
			}

			if ( $dry_run ) {
				++$created;
				WP_CLI::log( sprintf( '  (dry-run) utworzyłby klasę „%s" (kod „%s").', $definicja['nazwa'], $kod ) );

				continue;
			}

			$term = wp_insert_term( $definicja['nazwa'], ClassDefinitionsTaxonomy::TAXONOMY );

			if ( is_wp_error( $term ) ) {
				WP_CLI::warning( sprintf( 'Klasa „%s" (kod „%s"): nie udało się utworzyć termu — %s', $definicja['nazwa'], $kod, $term->get_error_message() ) );

				continue;
			}

			$term_id = $term['term_id'];

			update_term_meta( $term_id, 'kod', $kod );
			update_term_meta( $term_id, 'kolor', $definicja['kolor'] );
			update_term_meta( $term_id, 'opis_chip', $definicja['opis_chip'] );
			update_term_meta( $term_id, 'stan_wizualny', $definicja['stan_wizualny'] );
			update_term_meta( $term_id, 'charakterystyka', $definicja['charakterystyka'] );
			update_term_meta( $term_id, 'dlaczego_taniej', $definicja['dlaczego_taniej'] );
			update_term_meta( $term_id, 'okres_gwarancji_miesiace', $definicja['okres_gwarancji_miesiace'] );
			update_term_meta( $term_id, 'okres_reklamacji_miesiace', $definicja['okres_reklamacji_miesiace'] );

			++$created;
			WP_CLI::log( sprintf( '  utworzono klasę „%s" (kod „%s", term_id %d).', $definicja['nazwa'], $kod, $term_id ) );
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'seed-klasa-stanu (dry-run): %d utworzyłoby się, %d już istnieje.', $created, $skipped ) );

			return;
		}

		WP_CLI::success( sprintf( 'seed-klasa-stanu: utworzono %d, pominięto (już istniały) %d.', $created, $skipped ) );
	}
}
