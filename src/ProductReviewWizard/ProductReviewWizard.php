<?php
/**
 * Slice ProductReviewWizard — kreator (wizard) przeglądu świeżo zaimportowanego
 * produktu (P-17.2, D-17.1/D-17.3/D-17.4/D-17.5/D-17.6).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductReviewWizard;

use Qutlet\Core\AllegroChannel\AllegroChannelFields;
use Qutlet\Core\Pricing\DiscountRate;
use Qutlet\Core\ProductCondition\MarketPriceField;
use Qutlet\Core\ProductCondition\ProductConditionFields;
use WP_Post;

/**
 * Nakładka (modal) nad natywnym ekranem edycji produktu (D-17.1) — JS/CSS
 * orkiestrujący WIDOCZNOŚĆ i KOLEJNOŚĆ już istniejących metaboxów w 6 kroków,
 * BEZ przejmowania ich logiki zapisu. Ta klasa dostarcza WYŁĄCZNIE stronę
 * serwerową (enqueue, trigger, konfiguracja kroków jako selektory DOM
 * przekazane do JS) — samą orkiestrację robi
 * `assets/js/product-review-wizard.js` (fizyczne przenoszenie DOM node'ów
 * kroku do karty kreatora i chowanie reszty, D-17.6).
 *
 * Kroki 1–2 (`qutlet-ai`) mają własny, niezależny AJAX (P-13.2c/P-17.1) —
 * przeniesienie ich metaboxów do karty kreatora NIE zmienia tego mechanizmu,
 * bo węzły DOM zostają wewnątrz `<form id="post">` (JS przenosi, nie
 * klonuje). Kroki 3–5 (pola natywne Woo/ACF) zostają częścią tego samego
 * zbiorczego submitu — bez zmiany zapisu (D-17.5).
 *
 * Identyfikatory metaboxów `qutlet-ai` ({@see self::AI_TITLE_METABOX_ID},
 * {@see self::AI_GENERATION_METABOX_ID}) są zduplikowane świadomie — core NIE
 * importuje klas z `qutlet-ai` (granica repo, `CLAUDE.md` § Struktura),
 * wzorem {@see \Qutlet\Core\ProductCondition\ProductConditionFields}
 * (duplikacja ekstrakcji parametru „Stan" z `qutlet-allegro`). Literały
 * potwierdzone ground-truthem z `qutlet-ai\src\AiRewrite\
 * TitleGenerationMetaBox::META_BOX_ID` / `GenerationMetaBox::META_BOX_ID`.
 */
final class ProductReviewWizard {

	/**
	 * Ekran (typ posta), na którym działa kreator — produkt WooCommerce.
	 */
	private const SCREEN = 'product';

	/**
	 * Query arg na linku z listy produktów (D-17.4, trigger „oba miejsca") —
	 * obecność `=1` otwiera kreator automatycznie po wejściu na ekran edycji.
	 */
	private const AUTO_OPEN_QUERY_ARG = 'qutlet_wizard';

	/**
	 * Uchwyt (handle) skryptu/stylu JS/CSS kreatora.
	 */
	private const SCRIPT_HANDLE = 'qutlet-core-product-review-wizard';

	/**
	 * Metabox ID `TitleGenerationMetaBox::META_BOX_ID` (`qutlet-ai`) — patrz
	 * docblock klasy.
	 */
	private const AI_TITLE_METABOX_ID = 'qutlet_ai_title_generator';

	/**
	 * Metabox ID `GenerationMetaBox::META_BOX_ID` (`qutlet-ai`) — patrz
	 * docblock klasy.
	 */
	private const AI_GENERATION_METABOX_ID = 'qutlet_ai_generation';

	/**
	 * Wpina enqueue, trigger (przycisk na ekranie edycji + link na liście
	 * produktów, D-17.4) i rejestrację metaboxa podglądu kategorii (D-17.3).
	 * Wołane z bootstrapu core (na `plugins_loaded`, po sprawdzeniu twardych
	 * zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		CategoryPreviewMetaBox::init();

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'edit_form_top', array( self::class, 'render_trigger_button' ) );
		add_filter( 'post_row_actions', array( self::class, 'add_row_action' ), 10, 2 );
	}

	/**
	 * Ładuje JS/CSS kreatora WYŁĄCZNIE na ekranie edycji produktu (wzorzec
	 * {@see \Qutlet\Ai\AiRewrite\GenerationMetaBox::enqueue_script()}).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$screen = get_current_screen();

		if ( null === $screen || self::SCREEN !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/css/product-review-wizard.css', \Qutlet\Core\PLUGIN_FILE ),
			array(),
			\Qutlet\Core\VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/product-review-wizard.js', \Qutlet\Core\PLUGIN_FILE ),
			array(),
			\Qutlet\Core\VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wyłącznie flaga UI (auto-otwarcie modala z linku listy produktów), bez żadnej akcji z efektem ubocznym.
		$auto_open = isset( $_GET[ self::AUTO_OPEN_QUERY_ARG ] ) && '1' === $_GET[ self::AUTO_OPEN_QUERY_ARG ];

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'qutletProductReviewWizard',
			array(
				'steps'    => self::steps(),
				'autoOpen' => $auto_open,
				'i18n'     => array(
					'close'  => __( 'Zamknij', 'qutlet-core' ),
					'back'   => __( 'Wstecz', 'qutlet-core' ),
					'next'   => __( 'Dalej', 'qutlet-core' ),
					'finish' => __( 'Zakończ', 'qutlet-core' ),
					'title'  => __( 'Kreator przeglądu produktu', 'qutlet-core' ),
				),
			)
		);
	}

	/**
	 * Konfiguracja 6 kroków kreatora — dla każdego kroku lista selektorów CSS
	 * węzłów DOM do fizycznego przeniesienia do karty kreatora (JS). Kroki 1,
	 * 2, 4, 5, 6 wskazują CAŁE metaboxy (po DOM id); krok 3 wskazuje TYLKO
	 * dwa konkretne wiersze pól w natywnym Product Data (nie cały box Woo,
	 * zgodnie z opisem punktu planu — „pola natywne Woo... przeniesione
	 * wizualnie do kroku 3").
	 *
	 * Klasa wiersza pola Woo (`woocommerce_wp_text_input()`,
	 * `wc-meta-box-functions.php`) to zawsze `{$field['id']}_field` — literały
	 * budujemy z publicznych stałych meta_key ({@see DiscountRate::META_OVERRIDE},
	 * {@see MarketPriceField::META_KEY}), nie duplikujemy ich jako string.
	 *
	 * @return array<int, array{title: string, selectors: array<int, string>}>
	 */
	private static function steps(): array {
		return array(
			array(
				'title'     => __( 'Nazwa', 'qutlet-core' ),
				'selectors' => array( '#' . self::AI_TITLE_METABOX_ID ),
			),
			array(
				'title'     => __( 'Opis', 'qutlet-core' ),
				'selectors' => array( '#' . self::AI_GENERATION_METABOX_ID ),
			),
			array(
				'title'     => __( 'Cena', 'qutlet-core' ),
				'selectors' => array(
					'.' . DiscountRate::META_OVERRIDE . '_field',
					'.' . MarketPriceField::META_KEY . '_field',
				),
			),
			array(
				'title'     => __( 'Stan i zawartość', 'qutlet-core' ),
				'selectors' => array( '#' . ProductConditionFields::metabox_id() ),
			),
			array(
				'title'     => __( 'Kanał Allegro', 'qutlet-core' ),
				'selectors' => array( '#' . AllegroChannelFields::metabox_id() ),
			),
			array(
				'title'     => __( 'Kategoria', 'qutlet-core' ),
				'selectors' => array( '#' . CategoryPreviewMetaBox::META_BOX_ID ),
			),
		);
	}

	/**
	 * Renderuje przycisk otwierający kreator na ekranie edycji produktu
	 * (D-17.4). Pomijamy `post-new.php` (auto-draft) — produkt świeżo tworzony
	 * ręcznie nie ma jeszcze żadnej z warstw, które kreator ma przeglądać.
	 *
	 * @param WP_Post $post Bieżący produkt.
	 * @return void
	 */
	public static function render_trigger_button( WP_Post $post ): void {
		if ( self::SCREEN !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		printf(
			'<p class="qutlet-wizard-trigger-row"><button type="button" class="button button-primary" data-qutlet-wizard-open>%s</button></p>',
			esc_html__( 'Otwórz kreator', 'qutlet-core' )
		);
	}

	/**
	 * Dokłada link „Otwórz kreator" do akcji wiersza na liście produktów
	 * (D-17.4) — prowadzi na ekran edycji z flagą auto-otwarcia
	 * ({@see self::AUTO_OPEN_QUERY_ARG}).
	 *
	 * @param array<string, string> $actions Akcje wiersza (edit/trash/…).
	 * @param WP_Post                $post    Produkt bieżącego wiersza.
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, WP_Post $post ): array {
		if ( self::SCREEN !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$edit_link = get_edit_post_link( $post->ID, 'raw' );

		if ( null === $edit_link ) {
			return $actions;
		}

		$actions['qutlet_wizard'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( self::AUTO_OPEN_QUERY_ARG, '1', $edit_link ) ),
			esc_html__( 'Otwórz kreator', 'qutlet-core' )
		);

		return $actions;
	}
}
