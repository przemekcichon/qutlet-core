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
 * 2 pola tekstów polityk per klasa (`gwarancja_opis`/`reklamacja_opis`,
 * {@see ClassDefinitionsTaxonomy::register_fields()}, D-22.5.1/D-22.5.2)
 * dzisiejszą treścią, dziś zaszytą jako hardkodowane literały w
 * `qutlet-theme\content-single-product.php` (ground-truth `docs/plan.md`
 * P-22.5, sesja 2026-08-19). Seedowana treść jest identyczna dla wszystkich
 * klas — różnicowanie per klasa to przyszła praca redakcyjna admina (ten sam
 * wzorzec co `dlaczego_taniej`, P-12.1a).
 *
 * **REWIZJA D-22.5.4:** pierwotnie ta komenda seedowała 12 pól — 10 z nich
 * PRZENIESIONO na opcje globalne (`StoreContentSettingsPage`, kontrakt §19.2)
 * po decyzji użytkownika, że nie są związane z fizycznym stanem egzemplarza.
 * Zostają WYŁĄCZNIE `gwarancja_opis`/`reklamacja_opis` — jedyne dwa już dziś
 * sprzężone z inną wartością per-klasa (`okres_gwarancji_miesiace`/
 * `okres_reklamacji_miesiace` przez placeholder `{okres}`).
 *
 * **REWIZJA D-22.5.3 (recenzja P-22.5, sesja 2026-08-19, decyzja
 * użytkownika):** pierwotna wersja celowała w sztywną listę kodów
 * `A`/`B`/`C`/`D` (wzorem {@see SeedClassDefinitionsCommand}) — recenzja
 * ujawniła (zweryfikowane `wp term list klasa_stanu_definicja`/`wp term meta
 * list` na Localu), że ŻADNA klasa o kodzie A-D nie istnieje dziś na tym
 * środowisku: taksonomia niesie WYŁĄCZNIE 7 realnych klas nazwanych surowymi
 * wartościami Allegro „Stan" (`Na części`/`Nowy`/`Nowy z defektem`/`Po
 * zwrocie`/`Powystawowy`/`Uszkodzony`/`Używany`), z term meta `kod`
 * identycznym z `name` na każdej (ten sam fakt, niezależnie ground-truthowany
 * przy P-9.7, `docs/plan.md`). Sztywna lista A-D była więc martwym kodem
 * (zero efektu) na realnych danych. Komenda iteruje teraz PO WSZYSTKICH
 * klasach zwróconych przez {@see ClassDefinitionsTaxonomy::all()} —
 * niezależnie od tego, jak się dziś nazywają / jaki mają `kod` — zamiast
 * zakładać konkretny zestaw kodów.
 *
 * ## Idempotencja
 * Działa PO POLU, nie po termie: pole już wypełnione (niepuste) → pomijane,
 * niezależnie od pozostałych pól tego samego termu — nie nadpisuje ręcznej
 * edycji admina, ale i nie blokuje backfillu pól, których admin jeszcze nie
 * dotknął.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (jak pozostałe
 * komendy repo).
 */
final class BackfillPolicyTextsCommand {

	/**
	 * Dzisiejsza treść (identyczna dla wszystkich klas) — literały skopiowane
	 * z `qutlet-theme` (ground-truth, patrz docblock klasy). Placeholder
	 * `{okres}` w `gwarancja_opis`/`reklamacja_opis` podstawia motyw przy
	 * renderze (`ProductPage::period_years_text()`).
	 *
	 * @var array<string, string>
	 */
	private const SEED_DATA = array(
		'gwarancja_opis'  => '{okres} gwarancji na każdy produkt. Reklamacje realizujemy w naszym serwisie — szybko i bezproblemowo.',
		'reklamacja_opis' => '{okres} (zamiast ustawowych 2 lat — dopuszczalne dla towarów używanych, gdy kupujący zostanie wyraźnie poinformowany).',
	);

	/**
	 * Wypełnia puste pola tekstów polityk dla WSZYSTKICH dziś zdefiniowanych
	 * klas stanu.
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

		$dry_run  = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$existing = ClassDefinitionsTaxonomy::all();
		$filled   = 0;
		$skipped  = 0;

		if ( array() === $existing ) {
			WP_CLI::warning( 'Brak zdefiniowanych klas w taksonomii klasa_stanu_definicja — nic do zrobienia.' );

			return;
		}

		foreach ( $existing as $kod => $definicja ) {
			$term_id = $definicja['term_id'];

			foreach ( self::SEED_DATA as $meta_key => $default_value ) {
				$current = (string) get_term_meta( $term_id, $meta_key, true );

				if ( '' !== $current ) {
					++$skipped;

					continue;
				}

				if ( $dry_run ) {
					++$filled;
					WP_CLI::log( sprintf( '  (dry-run) wypełniłby „%s" dla klasy „%s" (kod „%s").', $meta_key, $definicja['nazwa'], $kod ) );

					continue;
				}

				update_term_meta( $term_id, $meta_key, $default_value );
				++$filled;
				WP_CLI::log( sprintf( '  wypełniono „%s" dla klasy „%s" (kod „%s").', $meta_key, $definicja['nazwa'], $kod ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'backfill-teksty-polityk-klasa-stanu (dry-run): %d wypełniłoby się, %d już wypełnionych.', $filled, $skipped ) );

			return;
		}

		WP_CLI::success( sprintf( 'backfill-teksty-polityk-klasa-stanu: wypełniono %d, pominięto (już wypełnione) %d.', $filled, $skipped ) );
	}
}
