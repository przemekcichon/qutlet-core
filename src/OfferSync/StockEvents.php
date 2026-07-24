<?php
/**
 * Slice OfferSync — mostek zdarzeń stanu magazynowego zamówienia Woo (P-6.2a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\OfferSync;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Tłumaczy zamówieniowe zdarzenia stanu magazynowego WooCommerce na PRODUKTOWĄ
 * akcję domenową qutlet — bez HTTP, bez wiedzy o Allegro, bez nowych pól.
 *
 * Feature rozproszony `OfferSync/` (ta sama nazwa slice'a w qutlet-allegro):
 * glue do Woo mieszka wyłącznie w core (CLAUDE.md, granice repo), a transfer
 * stanu do Allegro robi qutlet-allegro — subskrybując {@see self::ACTION}
 * zamiast dotykać hooków Woo. Konsument bierze literał akcji ze stałej tej
 * klasy (twarda zależność od core, jak `AllegroLinkMeta`/`DiscountRate`).
 *
 * Hooki źródłowe (zweryfikowane w Woo 10.9.4, `includes/wc-stock-functions.php`):
 * - `woocommerce_reduce_order_item_stock` — po zdjęciu stanu przez zamówienie
 *   (`wc_reduce_stock_levels()`); `$change = {product, from, to}`;
 * - `woocommerce_restore_order_item_stock` — po przywróceniu stanu przez
 *   cofnięcie/anulowanie zamówienia (`wc_increase_stock_levels()`).
 * Oba są PER POZYCJA zamówienia i odpalają się wyłącznie dla produktów z
 * włączonym zarządzaniem stanem — dokładnie ten podzbiór, który sync utrzymuje.
 *
 * Świadomie NIE hakujemy `woocommerce_product_set_stock` (każdy zapis stanu,
 * także ręczna edycja w adminie i zapisy samego syncu): D-6.G3 propaguje z Woo
 * wyłącznie zdarzenia STEROWANE ZAMÓWIENIEM (sprzedaż i jej cofnięcie); ręczne
 * podnoszenie stanu robi się na Allegro, a nasłuch na `set_stock` zapętlałby
 * pull syncu z powrotem w push.
 */
final class StockEvents {

	/**
	 * Akcja domenowa: stan produktu zmieniony przez zamówienie Woo.
	 *
	 * Sygnatura dla subskrybentów:
	 * `do_action( self::ACTION, int $product_id, int $new_stock, string $direction, int $order_id )`
	 * gdzie `$direction` to {@see self::DIRECTION_REDUCE} albo {@see self::DIRECTION_RESTORE}.
	 */
	public const ACTION = 'qutlet_product_order_stock_changed';

	/**
	 * Kierunek zmiany: zdjęcie stanu przez sprzedaż (redukcja zamówieniowa).
	 */
	public const DIRECTION_REDUCE = 'reduce';

	/**
	 * Kierunek zmiany: przywrócenie stanu przez cofnięcie/anulowanie zamówienia.
	 */
	public const DIRECTION_RESTORE = 'restore';

	/**
	 * Wpina nasłuch na hooki zamówieniowe Woo. Wołane z bootstrapu core
	 * (na `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5); same
	 * zdarzenia odpalają się dopiero przy przetwarzaniu zamówień, więc kolejność
	 * ładowania wtyczek nie ma tu znaczenia.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_reduce_order_item_stock', array( self::class, 'on_item_stock_reduced' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( self::class, 'on_item_stock_restored' ), 10, 4 );
	}

	/**
	 * Pozycja zamówienia zdjęła stan (`wc_reduce_stock_levels()`).
	 *
	 * Typy celowo `mixed` + twarde sprawdzenia runtime: to publiczny hook — Woo
	 * dokumentuje `{product, from, to}`, ale odpalić go może cokolwiek.
	 *
	 * @param mixed $item   Pozycja zamówienia (nieużywana — produkt niesie `$change`).
	 * @param mixed $change Zmiana `{product: WC_Product, from: int|float, to: int|float}`.
	 * @param mixed $order  Zamówienie (`WC_Order`).
	 * @return void
	 */
	public static function on_item_stock_reduced( $item, $change, $order ): void {
		unset( $item );

		$product = is_array( $change ) ? ( $change['product'] ?? null ) : null;
		$new     = is_array( $change ) ? ( $change['to'] ?? null ) : null;

		if ( ! $product instanceof WC_Product || ! is_numeric( $new ) ) {
			return;
		}

		self::dispatch( $product->get_id(), (int) $new, self::DIRECTION_REDUCE, $order );
	}

	/**
	 * Pozycja zamówienia przywróciła stan (`wc_increase_stock_levels()`).
	 *
	 * Typy celowo `mixed` + twarde sprawdzenia runtime (jak wyżej).
	 *
	 * @param mixed $item      Pozycja zamówienia (`WC_Order_Item_Product`).
	 * @param mixed $new_stock Nowy stan po przywróceniu (int|float).
	 * @param mixed $old_stock Poprzedni stan (nieużywany).
	 * @param mixed $order     Zamówienie (`WC_Order`).
	 * @return void
	 */
	public static function on_item_stock_restored( $item, $new_stock, $old_stock, $order ): void {
		unset( $old_stock );

		$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

		if ( ! $product instanceof WC_Product || ! is_numeric( $new_stock ) ) {
			return;
		}

		self::dispatch( $product->get_id(), (int) $new_stock, self::DIRECTION_RESTORE, $order );
	}

	/**
	 * Odpala akcję domenową z ujednoliconym ładunkiem.
	 *
	 * @param int    $product_id Id produktu.
	 * @param int    $new_stock  Nowy stan magazynowy.
	 * @param string $direction  {@see self::DIRECTION_REDUCE} / {@see self::DIRECTION_RESTORE}.
	 * @param mixed  $order      Zamówienie źródłowe (`WC_Order`; dla logów konsumenta).
	 * @return void
	 */
	private static function dispatch( int $product_id, int $new_stock, string $direction, $order ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$order_id = $order instanceof WC_Order ? $order->get_id() : 0;

		/**
		 * Stan produktu zmieniony przez zamówienie Woo (sprzedaż albo jej cofnięcie).
		 *
		 * @param int    $product_id Id produktu.
		 * @param int    $new_stock  Nowy stan magazynowy (po zmianie).
		 * @param string $direction  `reduce` (sprzedaż) / `restore` (cofnięcie).
		 * @param int    $order_id   Id zamówienia źródłowego (0, gdy nieznane).
		 */
		do_action( self::ACTION, $product_id, $new_stock, $direction, $order_id );
	}
}
