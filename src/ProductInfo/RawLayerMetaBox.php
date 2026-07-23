<?php
/**
 * Slice ProductInfo — podgląd WARSTWY SUROWEJ w adminie (P-5.3, read-only).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductInfo;

use WP_Post;

/**
 * Metabox na ekranie edycji produktu, pokazujący WARSTWĘ SUROWĄ z Allegro
 * WYŁĄCZNIE DO ODCZYTU (P-5.3, D-5.G3).
 *
 * Cel: dać właścicielowi możliwość porównania „co przyszło z Allegro" z tym, co
 * pokazujemy klientowi (warstwa przerobiona — `opis` + atrybuty WC). Warstwa surowa
 * jest ukryta na froncie (D-5.G3/D-8.G1) i nadpisywana przy sync — źródłem prawdy
 * jest Allegro, więc podgląd NIE ma żadnej ścieżki edycji (brak formularza, brak
 * nonce'a, brak zapisu). Podgląd dostarcza `qutlet-core`, bo to właściciel pól
 * (`RawLayerMeta`); nie zależy od obecności `qutlet-ai`.
 *
 * Pola czytane VERBATIM przez stałe z `RawLayerMeta` (nie zgadujemy literałów):
 * - `RawLayerMeta::META_DESCRIPTION_RAW`   — opis prozą (surowy HTML z Allegro).
 * - `RawLayerMeta::META_SPECIFICATION_RAW` — specyfikacja, lista {etykieta, wartosc}.
 * - `RawLayerMeta::META_OFFER`             — pełna oferta JSON verbatim (wgląd na żądanie).
 *
 * Bezpieczeństwo renderu: opis to NIEZAUFANY HTML z Allegro → wyświetlamy przez
 * `esc_html()` (podgląd surowego źródła, XSS-safe), NIE renderujemy go jako HTML.
 * JSON i wartości specyfikacji też przez `esc_html()`. To podgląd bajtów warstwy
 * surowej, nie sformatowana prezentacja.
 */
final class RawLayerMetaBox {

	/**
	 * Ekran (typ posta), na którym pokazujemy podgląd — produkt WooCommerce.
	 */
	private const SCREEN = 'product';

	/**
	 * Identyfikator metaboxa (unikalny w obrębie ekranu).
	 */
	private const META_BOX_ID = 'qutlet_raw_layer_preview';

	/**
	 * Wpina rejestrację metaboxa na `add_meta_boxes`. Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register' ) );
	}

	/**
	 * Rejestruje metabox tylko dla ekranu edycji produktu.
	 *
	 * @param string $post_type Typ posta bieżącego ekranu edycji.
	 * @return void
	 */
	public static function register( string $post_type ): void {
		if ( self::SCREEN !== $post_type ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Qutlet — warstwa surowa z Allegro (podgląd, tylko do odczytu)', 'qutlet-core' ),
			array( self::class, 'render' ),
			self::SCREEN,
			'normal',
			'default'
		);
	}

	/**
	 * Renderuje podgląd warstwy surowej: opis prozą, specyfikację i (rozwijalnie)
	 * pełny JSON oferty. Cały render jest tylko do odczytu.
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$description = (string) get_post_meta( $post->ID, RawLayerMeta::META_DESCRIPTION_RAW, true );
		$offer       = (string) get_post_meta( $post->ID, RawLayerMeta::META_OFFER, true );

		$specification = get_post_meta( $post->ID, RawLayerMeta::META_SPECIFICATION_RAW, true );
		if ( ! is_array( $specification ) ) {
			$specification = array();
		}

		// Produkt utworzony ręcznie (nie z Allegro) → brak wszystkich trzech pól.
		if ( '' === $description && '' === $offer && array() === $specification ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Brak warstwy surowej — produkt nie pochodzi z Allegro (utworzony ręcznie) albo nie był jeszcze zsynchronizowany.', 'qutlet-core' )
			);

			return;
		}

		self::render_description( $description );
		self::render_specification( $specification );
		self::render_offer_json( $offer );
	}

	/**
	 * Renderuje opis prozą (surowy HTML z Allegro) — przez `esc_html`, w bloku z
	 * zachowaniem odstępów. Puste → nota o braku.
	 *
	 * @param string $description Surowy opis prozą.
	 * @return void
	 */
	private static function render_description( string $description ): void {
		printf( '<h4 style="margin-bottom:.25em">%s</h4>', esc_html__( 'Opis (prozą, surowy z Allegro)', 'qutlet-core' ) );

		if ( '' === $description ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Brak opisu tekstowego w ofercie.', 'qutlet-core' ) );

			return;
		}

		printf(
			'<div style="max-height:20em;overflow:auto;padding:.75em;border:1px solid #dcdcde;background:#fff;white-space:pre-wrap;word-break:break-word">%s</div>',
			esc_html( $description )
		);
	}

	/**
	 * Renderuje specyfikację jako tabelę etykieta→wartość (obie kolumny `esc_html`).
	 * Pusta lista → nota o braku.
	 *
	 * @param array<int, array{etykieta?: mixed, wartosc?: mixed}> $specification Lista par.
	 * @return void
	 */
	private static function render_specification( array $specification ): void {
		printf( '<h4 style="margin:1em 0 .25em">%s</h4>', esc_html__( 'Specyfikacja (surowa z Allegro)', 'qutlet-core' ) );

		if ( array() === $specification ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Brak parametrów w ofercie.', 'qutlet-core' ) );

			return;
		}

		echo '<table class="widefat striped" style="max-width:100%"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Etykieta', 'qutlet-core' ) );
		printf( '<th>%s</th>', esc_html__( 'Wartość', 'qutlet-core' ) );
		echo '</tr></thead><tbody>';

		foreach ( $specification as $row ) {
			$label = isset( $row['etykieta'] ) ? (string) $row['etykieta'] : '';
			$value = isset( $row['wartosc'] ) ? (string) $row['wartosc'] : '';

			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje pełną ofertę JSON w rozwijanym bloku (`<details>`), przez `esc_html`
	 * w `<pre>`. Dla czytelności podglądu ładny JSON, gdy da się zdekodować; w razie
	 * niepowodzenia pokazujemy surowy string zapisany w meta. Wartość w bazie
	 * pozostaje nietknięta (verbatim) — to reformatowanie jest wyłącznie na potrzeby
	 * wyświetlenia. Puste → nota o braku.
	 *
	 * @param string $offer Surowy JSON oferty (verbatim z meta).
	 * @return void
	 */
	private static function render_offer_json( string $offer ): void {
		echo '<details style="margin-top:1em">';
		printf( '<summary style="cursor:pointer;font-weight:600">%s</summary>', esc_html__( 'Pełna oferta Allegro (JSON)', 'qutlet-core' ) );

		if ( '' === $offer ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Brak zapisanej oferty JSON.', 'qutlet-core' ) );
			echo '</details>';

			return;
		}

		$pretty  = $offer;
		$decoded = json_decode( $offer, true );

		if ( null !== $decoded || 'null' === trim( $offer ) ) {
			$encoded = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false !== $encoded ) {
				$pretty = $encoded;
			}
		}

		printf(
			'<pre style="max-height:24em;overflow:auto;padding:.75em;border:1px solid #dcdcde;background:#fff;white-space:pre-wrap;word-break:break-word">%s</pre>',
			esc_html( $pretty )
		);

		echo '</details>';
	}
}
