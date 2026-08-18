<?php
/**
 * Slice AiRewrite — zdjęcie natywnego wsparcia edytora treści dla `product` (P-20.6a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\AiRewrite;

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
	 * `product`) — zdjęcie PRZED rejestracją byłoby no-opem.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'remove_editor_support' ) );
	}

	/**
	 * @return void
	 */
	public static function remove_editor_support(): void {
		remove_post_type_support( self::SCREEN, 'editor' );
	}
}
