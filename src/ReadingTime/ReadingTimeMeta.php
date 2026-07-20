<?php
/**
 * Slice ReadingTime — obliczanie i zapis czasu czytania wpisu bloga (P-1.4).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ReadingTime;

use WP_Post;

/**
 * Liczy czas czytania wpisu i zapisuje go jako prywatną post meta.
 *
 * W ODRÓŻNIENIU od pozostałych slice'ów core (ProductCondition, AllegroChannel)
 * ten slice NIE rejestruje pola ACF — czas czytania to wartość liczona maszynowo,
 * nie edytowana ręcznie. Blog stoi na natywnych wpisach WP + natywnych
 * `category`/`post_tag`; core NIE rejestruje własnego CPT ani taksonomii
 * (D-1.4.1). Jedyny artefakt tego slice'a = meta czasu czytania.
 *
 * Literały (z `docs/kontrakt-danych.md` §5 — VERBATIM, case-sensitive):
 * - `meta_key` = `_qutlet_reading_time` (prefiks `_` = prywatna meta, ukryta w UI
 *   „Custom Fields"), typ int (minuty). Motyw czyta gotową wartość:
 *   `get_post_meta( $id, '_qutlet_reading_time', true )`.
 *
 * Reguła obliczenia (D-1.4.2): liczba słów treści ÷ 200 wpm, zaokrąglone W GÓRĘ,
 * minimum 1 min. WPM jest STAŁĄ w kodzie, nie ustawieniem.
 *
 * Miejsce obliczenia (D-1.4.3): liczone i zapisywane w core na `save_post`;
 * konsument (motyw) tylko czyta. Zgodne z podziałem core=dane / theme=render.
 */
final class ReadingTimeMeta {

	/**
	 * `meta_key` czasu czytania — literał z kontraktu §5 (VERBATIM).
	 */
	public const META_KEY = '_qutlet_reading_time';

	/**
	 * Prędkość czytania w słowach na minutę (D-1.4.2 — stała, nie ustawienie).
	 */
	private const WORDS_PER_MINUTE = 200;

	/**
	 * Wpina obliczanie na `save_post`. Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — patrz D-G5).
	 *
	 * Priorytet domyślny (10); `save_post` odpala się PO zapisaniu wpisu w bazie,
	 * a przekazany obiekt `WP_Post` niesie już aktualną treść — nie musimy jej
	 * dociągać ponownie. `accepted_args = 2`, żeby dostać `$post`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post', array( self::class, 'store_reading_time' ), 10, 2 );
	}

	/**
	 * Liczy czas czytania wpisu i zapisuje jako prywatną meta.
	 *
	 * Pomija autozapisy i rewizje (nie są właściwym wpisem) oraz wszystko poza
	 * typem `post` i szkice `auto-draft` (puste szkielety tworzone przy „Dodaj
	 * nowy" — nie zaśmiecamy ich meta). Zapis przez `update_post_meta` jest
	 * idempotentny: gdy wartość się nie zmienia, WP nie pisze do bazy.
	 *
	 * @param int     $post_id ID zapisywanego wpisu.
	 * @param WP_Post $post    Obiekt zapisywanego wpisu (z aktualną treścią).
	 * @return void
	 */
	public static function store_reading_time( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'post' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		$minutes = self::calculate_minutes( $post->post_content );

		update_post_meta( $post_id, self::META_KEY, $minutes );
	}

	/**
	 * Przelicza treść wpisu na czas czytania w minutach.
	 *
	 * Liczymy z surowej `post_content` (źródło prawdy w bazie w chwili zapisu),
	 * NIE z filtra `the_content`: `the_content` to filtr renderu, zależny od
	 * kontekstu pętli (global `$post`), uruchamiający shortcode'y/oEmbed
	 * (potencjalne żądania HTTP) — nieodpowiedni i kosztowny przy zapisie. Z
	 * treści usuwamy shortcode'y, a następnie znaczniki HTML (w tym komentarze-
	 * delimitery bloków `<!-- wp:… -->`), po czym liczymy słowa rozdzielone
	 * białymi znakami (tryb `u` — poprawnie dla polskich znaków).
	 *
	 * @param string $content Surowa treść wpisu (`post_content`).
	 * @return int Czas czytania w minutach (minimum 1).
	 */
	private static function calculate_minutes( string $content ): int {
		$text  = wp_strip_all_tags( strip_shortcodes( $content ) );
		$text  = trim( $text );
		$words = self::count_words( $text );

		return max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );
	}

	/**
	 * Liczy słowa w oczyszczonym tekście (białe znaki jako separator).
	 *
	 * @param string $text Tekst po usunięciu shortcode'ów i znaczników HTML.
	 * @return int Liczba słów (0 dla pustego tekstu).
	 */
	private static function count_words( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}

		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? count( $parts ) : 0;
	}
}
