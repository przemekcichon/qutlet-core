<?php
/**
 * Slice OrderSync — rejestracja własnego statusu zamówienia `wc-shipped` (P-6.5b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\OrderSync;

/**
 * Rejestruje własny status zamówienia WooCommerce `wc-shipped` („Wysłane").
 *
 * WooCommerce nie ma natywnego stanu „wysłane" (D-6.5.5): oś realizacji Allegro
 * `fulfillment.status = SENT`/`READY_FOR_PICKUP` (`mapping-allegro.md` §8c) nie ma
 * dokąd trafić, a mapowanie jej na `wc-processing` cofałoby status, na
 * `wc-completed` — zlewało wysłane z odebranym. Rejestracja statusu Woo to **glue
 * do WooCommerce → core** (CLAUDE.md, granice repo), więc mieszka tutaj, nie w
 * `qutlet-allegro`. Literał `wc-shipped` jest VERBATIM z kontraktu `§12.5`
 * (`docs/kontrakt-danych.md`) — wchodzi do kontraktu NAJPIERW (D-5.G2), przed
 * rejestracją i konsumpcją.
 *
 * Feature rozproszony `OrderSync/` (ta sama nazwa slice'a w qutlet-allegro): core
 * tylko REJESTRUJE status i jego semantykę (opłacone, nieterminalne); USTAWIA go
 * pull statusów Allegro→Woo (qutlet-allegro, P-6.5c). Tu zero logiki syncu.
 *
 * Semantyka (kontrakt §12.5, D-6.5.5):
 * - **opłacone** — status jest dodawany do `wc_get_is_paid_statuses()` (filtr
 *   `woocommerce_order_is_paid_statuses`), żeby `WC_Order::is_paid()`, `date_paid`
 *   i raporty traktowały wysłane zamówienie jak opłacone (tranzycja
 *   `wc-processing → wc-shipped` NIE może cofnąć stanu opłacenia);
 * - **nieterminalne** — świadomie NIE oznaczamy statusu jako zakończonego; tor
 *   rekoncyliacji `--full` (P-6.5c, D-6.5.6) iteruje właśnie zamówienia
 *   nieterminalne, więc `wc-shipped` musi w tym zbiorze pozostać;
 * - **między `wc-processing` a `wc-completed`** — wstawiany zaraz po `wc-processing`
 *   na liście statusów (kolejność w dropdownie admina).
 *
 * Mechanizm (zweryfikowany w Woo 10.9.4):
 * - `woocommerce_register_shop_order_post_statuses` — tablica definicji trafia do
 *   `register_post_status()` w `WC_Post_Types::register_post_status()` (`init`,
 *   priorytet 9); rejestruje status posta zamówienia (widoczność w liście admina
 *   przez `show_in_admin_all_list`/`show_in_admin_status_list`);
 * - `wc_order_statuses` — `wc_get_order_statuses()` (`[slug => etykieta]`) zasila
 *   dropdown statusów w adminie ORAZ `wc_get_order_status_name()`, z którego
 *   „Moje konto" bierze czytelną etykietę „Wysłane";
 * - `woocommerce_order_is_paid_statuses` — patrz semantyka „opłacone" wyżej;
 *   UWAGA: ta lista trzyma slugi BEZ prefiksu `wc-` (stąd {@see self::STATUS_UNPREFIXED}).
 */
final class OrderStatuses {

	/**
	 * Slug statusu z prefiksem `wc-` (klucz `WC_Order` / post status).
	 *
	 * VERBATIM z kontraktu `docs/kontrakt-danych.md` §12.5.
	 */
	public const STATUS = 'wc-shipped';

	/**
	 * Slug bez prefiksu `wc-` — forma, jakiej używa `wc_get_is_paid_statuses()`
	 * (por. `WC_Order::get_status()` zwraca status bez prefiksu). To ten sam status
	 * co {@see self::STATUS}, tylko bez `wc-`.
	 */
	public const STATUS_UNPREFIXED = 'shipped';

	/**
	 * Status Woo, PO którym wstawiamy `wc-shipped` na liście (kolejność „między
	 * `wc-processing` a `wc-completed`", kontrakt §12.5). Gdyby go zabrakło —
	 * {@see self::insert_after()} dokleja na końcu (bezpieczny fallback).
	 */
	private const AFTER_STATUS = 'wc-processing';

	/**
	 * Wpina filtry rejestrujące status. Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5). Same filtry
	 * odpalają się dopiero, gdy Woo buduje listę statusów, więc kolejność
	 * ładowania wtyczek nie ma tu znaczenia.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( self::class, 'register_post_status_definition' ) );
		add_filter( 'wc_order_statuses', array( self::class, 'add_to_order_statuses_list' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( self::class, 'add_to_paid_statuses' ) );
	}

	/**
	 * Filtr `woocommerce_register_shop_order_post_statuses`: dokłada definicję
	 * post statusu `wc-shipped` (trafia do `register_post_status()`).
	 *
	 * @param mixed $statuses Tablica definicji post statusów zamówienia (`[slug => args]`).
	 * @return array<string,mixed> Tablica z dołożonym `wc-shipped`.
	 */
	public static function register_post_status_definition( $statuses ): array {
		return self::insert_after(
			is_array( $statuses ) ? $statuses : array(),
			self::AFTER_STATUS,
			self::STATUS,
			self::post_status_args()
		);
	}

	/**
	 * Filtr `wc_order_statuses`: dokłada `wc-shipped => „Wysłane"` do listy
	 * statusów (dropdown w adminie + etykieta w „Moje konto").
	 *
	 * @param mixed $statuses Tablica `[slug => etykieta]`.
	 * @return array<string,string> Tablica z dołożonym `wc-shipped`.
	 */
	public static function add_to_order_statuses_list( $statuses ): array {
		return self::insert_after(
			is_array( $statuses ) ? $statuses : array(),
			self::AFTER_STATUS,
			self::STATUS,
			self::label()
		);
	}

	/**
	 * Filtr `woocommerce_order_is_paid_statuses`: dokłada `shipped` (BEZ prefiksu)
	 * do listy statusów „opłaconych" — semantyka „opłacone" (kontrakt §12.5).
	 *
	 * @param mixed $statuses Lista slugów statusów uznawanych za opłacone (bez `wc-`).
	 * @return array<int,string> Lista z dołożonym `shipped` (bez duplikatu).
	 */
	public static function add_to_paid_statuses( $statuses ): array {
		return self::append_unique(
			is_array( $statuses ) ? array_values( $statuses ) : array(),
			self::STATUS_UNPREFIXED
		);
	}

	/**
	 * Definicja post statusu w kształcie oczekiwanym przez `register_post_status()`
	 * (te same klucze, co natywne statusy Woo — `WC_Post_Types::register_post_status()`).
	 * `public => false` jak wszystkie statusy Woo (i tak widoczne w „Moje konto").
	 *
	 * @return array<string,mixed>
	 */
	private static function post_status_args(): array {
		return array(
			'label'                     => self::label(),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: liczba zamówień */
			'label_count'               => _n_noop(
				'Wysłane <span class="count">(%s)</span>',
				'Wysłane <span class="count">(%s)</span>',
				'qutlet-core'
			),
		);
	}

	/**
	 * Czytelna etykieta statusu. Kontekst `Order status` jak w natywnych statusach Woo.
	 *
	 * @return string
	 */
	private static function label(): string {
		return _x( 'Wysłane', 'Order status', 'qutlet-core' );
	}

	/**
	 * Wstawia parę `$key => $value` ZARAZ ZA kluczem `$after_key`, zachowując
	 * kolejność pozostałych. Gdy `$after_key` nie istnieje — dokleja na końcu
	 * (bezpieczny fallback). Czysta funkcja (bez WP) — pokryta testami.
	 *
	 * @param array<string,mixed> $list      Tablica asocjacyjna (kolejność istotna).
	 * @param string              $after_key Klucz, po którym wstawiamy.
	 * @param string              $key       Nowy klucz.
	 * @param mixed               $value     Wartość dla nowego klucza.
	 * @return array<string,mixed> Nowa tablica z wstawioną parą.
	 */
	public static function insert_after( array $list, string $after_key, string $key, $value ): array {
		if ( ! array_key_exists( $after_key, $list ) ) {
			$list[ $key ] = $value;

			return $list;
		}

		$result = array();

		foreach ( $list as $existing_key => $existing_value ) {
			$result[ $existing_key ] = $existing_value;

			if ( $existing_key === $after_key ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Dokleja `$value` na koniec listy, jeśli jeszcze go nie ma (idempotentnie).
	 * Czysta funkcja (bez WP) — pokryta testami.
	 *
	 * @param array<int,string> $list  Lista wartości.
	 * @param string            $value Wartość do dołożenia.
	 * @return array<int,string> Lista z `$value` (bez duplikatu).
	 */
	public static function append_unique( array $list, string $value ): array {
		if ( in_array( $value, $list, true ) ) {
			return $list;
		}

		$list[] = $value;

		return $list;
	}
}
