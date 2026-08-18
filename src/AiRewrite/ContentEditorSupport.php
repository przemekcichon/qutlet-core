<?php
/**
 * Slice AiRewrite — zdjęcie natywnego wsparcia edytora treści dla `product` (P-20.6a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AiRewrite;

use WP_Error;
use WP_Post;

/**
 * Zdejmuje natywne wsparcie edytora treści (`post_type_supports( 'product',
 * 'editor' )`) — Woo/CPT-glue umożliwiający scalenie natywnego boksu „Opis
 * produktu" z metaboksem `qutlet-ai` „Generacja AI (przeróbka)" (D-20.6,
 * D-20.G4).
 *
 * Ground-truth (sesja planistyczna FAZY 20, potwierdzone ponownie przy
 * realizacji — `docs/kontrakt-danych.md` §9.2/§13): render natywnego edytora
 * (`wp-admin/edit-form-advanced.php`, bramkowany tą flagą) i ZAPIS
 * `post_content` (`_wp_translate_postdata()`, `wp-admin/includes/post.php:47`)
 * to DWIE NIEZALEŻNE ścieżki — zapis mapuje `$_POST['content']` →
 * `post_content` BEZWARUNKOWO, bez sprawdzenia tej flagi. WooCommerce też
 * nigdzie nie odpytuje tej flagi dla `product` (deklaruje wsparcie raz, przy
 * rejestracji CPT, `class-wc-post-types.php:341`). `qutlet-ai`
 * (`GenerationMetaBox::render()`) renderuje WŁASNE `wp_editor( …, 'content',
 * … )` wewnątrz scalonego metaboksu — ten sam ID pola (`content`), mechanizm
 * zapisu i JS synchronizacji po „Zaakceptuj"
 * (`rewrite-generator.js::setContentField()`) działają bez zmian.
 *
 * Efekt uboczny (świadomie zaakceptowany, niski risk): rdzeń WP bramkuje tą
 * samą flagą też link a11y „Skip to Editor" w `#titlediv`
 * (`edit-form-advanced.php:543`) oraz „empty content" guard w
 * `wp_insert_post()` (`wp-includes/post.php:4580-4584` — blokuje zapis posta z
 * JEDNOCZEŚNIE pustym tytułem, treścią i excerptem; wymaga wsparcia
 * title+editor+excerpt naraz). Oba efekty nie mają praktycznego znaczenia dla
 * `product` (skip-link to czysta wygoda klawiatury, a auto-drafty i tak mają
 * niepusty `post_title`, więc guard i tak nigdy nie odpalał się w normalnym
 * flow admina).
 *
 * KRYTYCZNE — ryzyko operacyjne (D-20.6): musi wejść razem/bezpośrednio po
 * merge'u `qutlet-ai` (P-20.6b) — okno między merge'ami zostawia ekran BEZ
 * ŻADNEGO edytora treści (ten box zniknie, zanim `qutlet-ai` zacznie
 * renderować własny wewnątrz scalonego metaboksu).
 *
 * Drugi efekt uboczny — TEN JUŻ WYMAGAŁ MITYGACJI (znaleziony niezależną
 * recenzją PR-a, nie sesją planistyczną): `WP_REST_Posts_Controller::
 * get_item_schema()` (`class-wp-rest-posts-controller.php:2590-2594`) i
 * `::prepare_item_for_database()` (tamże, linia ok. 1315) też bramkują pole
 * `content` tą samą flagą DLA typów postów SPOZA `$fixed_schemas` (tylko
 * `post`/`page`/`attachment` mają na sztywno wpisane `editor` — `product` nie
 * jest w tej liście). `product` ma `show_in_rest = true` bez własnej
 * `rest_controller_class` (`class-wc-post-types.php:416`), więc korzysta z
 * domyślnego `WP_REST_Posts_Controller` — usunięcie wsparcia edytora
 * SKASOWAŁOBY pole `content` (odczyt I zapis) z natywnego endpointu
 * `wp/v2/product` CAŁKOWICIE, bez żadnego błędu (potwierdzone empirycznie:
 * `GET /wp-json/wp/v2/product/{id}` bez klucza `content` w odpowiedzi) — inna
 * ścieżka niż `_wp_translate_postdata()` (submit klasycznego ekranu, patrz
 * wyżej), więc TEN sam ground-truth jej nie pokrywał.
 *
 * {@see self::register_content_rest_field()} przywraca pole `content` w REST
 * dla `product` niezależnie od `post_type_supports`, replikując DOKŁADNIE
 * kształt/zachowanie natywnego pola z `WP_REST_Posts_Controller` (schema
 * `raw`/`rendered`/`block_version`/`protected`, ta sama logika w
 * get/update callbackach) — REST zachowuje się identycznie jak przed tą
 * zmianą. Nic w `qutlet-core`/`qutlet-ai`/`qutlet-allegro` dziś nie korzysta z
 * `wp/v2/product` (grep przy realizacji — zero trafień), ale to publiczny,
 * domyślnie dostępny do odczytu endpoint w projekcie budującym własną,
 * niezależną od Allegro stronę — realny kandydat na przyszłą integrację
 * (headless/JS frontend, narzędzie zewnętrzne), więc milcząca utrata pola
 * byłaby zaskoczeniem dla każdego przyszłego konsumenta.
 */
final class ContentEditorSupport {

	/**
	 * Ekran (typ posta), z którego zdejmujemy wsparcie edytora.
	 */
	private const SCREEN = 'product';

	/**
	 * Wpina zdjęcie wsparcia na `init`, priorytet DOMYŚLNY (10) — PO
	 * `WC_Post_Types::register_post_types()` (ten sam hook, priorytet 5,
	 * `class-wc-post-types.php:341` deklaruje `'editor'` przy rejestracji CPT
	 * `product`) — zdjęcie PRZED rejestracją byłoby no-opem. Rejestruje też
	 * mitygację REST (patrz docblock klasy) na `rest_api_init`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'remove_editor_support' ) );
		add_action( 'rest_api_init', array( self::class, 'register_content_rest_field' ) );
	}

	/**
	 * @return void
	 */
	public static function remove_editor_support(): void {
		remove_post_type_support( self::SCREEN, 'editor' );
	}

	/**
	 * Przywraca pole `content` w REST (`wp/v2/product`) — schema i zachowanie
	 * 1:1 z `WP_REST_Posts_Controller` dla typów postów, którym `editor` NADAL
	 * wspiera (np. `post`). Patrz docblock klasy — dlaczego to jest konieczne,
	 * nie kosmetyka.
	 *
	 * @return void
	 */
	public static function register_content_rest_field(): void {
		register_rest_field(
			self::SCREEN,
			'content',
			array(
				'get_callback'    => array( self::class, 'get_content_field' ),
				'update_callback' => array( self::class, 'update_content_field' ),
				'schema'          => array(
					'description' => __( 'The content for the post.', 'qutlet-core' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'raw'           => array(
							'description' => __( 'Content for the post, as it exists in the database.', 'qutlet-core' ),
							'type'        => 'string',
							'context'     => array( 'edit' ),
						),
						'rendered'      => array(
							'description' => __( 'HTML content for the post, transformed for display.', 'qutlet-core' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
						'block_version' => array(
							'description' => __( 'Version of the content block format used by the post.', 'qutlet-core' ),
							'type'        => 'integer',
							'context'     => array( 'edit' ),
							'readonly'    => true,
						),
						'protected'     => array(
							'description' => __( 'Whether the content is protected with a password.', 'qutlet-core' ),
							'type'        => 'boolean',
							'context'     => array( 'view', 'edit', 'embed' ),
							'readonly'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * `get_callback` dla `content` — replika `WP_REST_Posts_Controller::
	 * prepare_item_for_response()` (sekcja `content`, ta sama logika
	 * `post_password_required()`/`the_content`/`block_version()`). `$response`
	 * to częściowo zbudowana odpowiedź REST (WP_REST_Controller przekazuje ją,
	 * nie `WP_Post`) — zawiera co najmniej `id`.
	 *
	 * @param array<string, mixed> $response Częściowa odpowiedź REST (ma `id`).
	 * @return array<string, mixed>
	 */
	public static function get_content_field( array $response ): array {
		$post = isset( $response['id'] ) ? get_post( (int) $response['id'] ) : null;

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return array(
			'raw'           => $post->post_content,
			// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- filtr rdzenia WP (the_content), nie nasz string.
			'rendered'      => post_password_required( $post ) ? '' : apply_filters( 'the_content', $post->post_content ),
			'block_version' => block_version( $post->post_content ),
			'protected'     => (bool) $post->post_password,
		);
	}

	/**
	 * `update_callback` dla `content` — replika `WP_REST_Posts_Controller::
	 * prepare_item_for_database()` (sekcja `content`: string albo
	 * `{raw: string}`) + zapis przez `wp_update_post()`. `$post` to JUŻ
	 * zaktualizowany/utworzony obiekt (framework woła to PO
	 * `wp_insert_post()`/`wp_update_post()` głównego żądania,
	 * `update_additional_fields_for_object( $post, $request )` w rdzeniu) —
	 * ten sam wzorzec co pola meta rejestrowane przez `register_rest_field()`
	 * gdzie indziej w projekcie.
	 *
	 * @param mixed   $value Wartość pola `content` z żądania (string albo `{raw: string}`).
	 * @param WP_Post $post  Produkt (już zaktualizowany przez główne żądanie).
	 * @return true|WP_Error
	 */
	public static function update_content_field( $value, WP_Post $post ) {
		if ( is_string( $value ) ) {
			$content = $value;
		} elseif ( is_array( $value ) && isset( $value['raw'] ) && is_string( $value['raw'] ) ) {
			$content = $value['raw'];
		} else {
			return new WP_Error(
				'rest_invalid_content',
				__( 'Invalid content.', 'qutlet-core' ),
				array( 'status' => 400 )
			);
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);

		return is_wp_error( $updated ) ? $updated : true;
	}
}
