<?php
/**
 * Slice Cart — dane koszyka rozszerzające Store API WooCommerce (P-26.2).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\Cart;

use Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy;
use Qutlet\Core\ProductCondition\MarketPriceField;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestruje rozszerzenia schematu Store API (`woocommerce_store_api_register_endpoint_data()`)
 * dla endpointów `cart-item`/`cart` — klasa stanu, kolor, teksty
 * gwarancji/reklamacji, stara cena/oszczędności (kontrakt §2/§2.2/§6).
 *
 * **P-26.2 (audyt bezpieczeństwa `qutlet-theme` 2026-08-21, ustalenie #1):**
 * przeniesione z `\Qutlet\Theme\features\Cart\Cart` — rejestracja hooka Store
 * API to formalnie „glue do Woo", które wg `CLAUDE.md` żyje w `qutlet-core`,
 * nie w motywie (D-8.6a.1/D-12.G2 w kodzie były świadomą decyzją tymczasową,
 * audyt zgłosił granicę do ponownej decyzji). Reszta slice'a Cart
 * (enqueue fragmentów, `CartBlocksIntegration`, mini-koszyk headera) ZOSTAJE
 * w motywie — to warstwa graficzna/JS, nie dane.
 *
 * Konsument tych samych danych: `assets/js/cart-block-filters.js` (blok
 * Cart) i `assets/js/checkout-block-filters.js` (blok Checkout, D-12.G2 —
 * endpoint `cart-item` jest współdzielony, kasa dostaje dane bez osobnej
 * rejestracji) w `qutlet-theme`. Zero zmian w JS — czyta wyłącznie przez
 * Store API, agnostyczne względem tego, kto zarejestrował endpoint.
 *
 * `period_years_text()` (+ pomocnicze `period_months_text()`/`pl_plural()`)
 * są DUPLIKOWANE z `\Qutlet\Theme\features\ProductPage\ProductPage`
 * (D-26.2.1) — jedna, krótka, czysta funkcja formatująca (zero zależności od
 * WP/Woo/ACF), więc wprowadzanie mechanizmu współdzielenia kodu między repo
 * byłoby przedwczesną abstrakcją. Oryginał w motywie zostaje bez zmian (używany
 * też gdzie indziej, poza zakresem tego przeniesienia).
 */
final class CartStoreApiData {

	/**
	 * Namespace danych rozszerzających Store API (`item.extensions.<ns>`).
	 *
	 * @var string
	 */
	const EXTENSION_NAMESPACE = 'qutlet-klasa';

	/**
	 * Podpina rejestrację danych Store API. Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * `woocommerce_blocks_loaded` odpala się z konstruktora `Bootstrap`
	 * (`src/Blocks/Domain/Bootstrap.php` WooCommerce), zwykle na
	 * `plugins_loaded` — kolejność względem bootstrapu `qutlet-core`
	 * (INNA wtyczka, ten sam event `plugins_loaded`) nie jest gwarantowana
	 * przez WP-core. `add_action()` po fakcie nigdy by się nie odpalił, więc
	 * rejestrujemy się WPROST, gdy hook już przeleciał — ten sam wzorzec
	 * obronny, jaki miał dawniej `\Qutlet\Theme\features\Cart\Cart::boot()`
	 * (P-26.2, przeniesiony razem z rejestracją).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api_data' ) );
		}
	}

	/**
	 * Rejestruje rozszerzenia schematu Store API dla `cart-item` i `cart`.
	 *
	 * @return void
	 */
	public static function register_store_api_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				// Literał 'cart-item', nie CartItemSchema::IDENTIFIER — klasa żyje w
				// src/StoreApi (WooCommerce), poza zasięgiem woocommerce-stubs (PHPStan).
				'endpoint'        => 'cart-item',
				'namespace'       => self::EXTENSION_NAMESPACE,
				'data_callback'   => array( self::class, 'cart_item_data' ),
				'schema_callback' => array( self::class, 'cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'cart',
				'namespace'       => self::EXTENSION_NAMESPACE,
				'data_callback'   => array( self::class, 'cart_totals_data' ),
				'schema_callback' => array( self::class, 'cart_totals_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Dane per-wiersz koszyka: klasa stanu + kolor + gwarancja/reklamacja +
	 * stara cena (kontrakt §2/§2.2, P-12.1b — REWIZJA: `klasa_kolor`/
	 * `gwarancja_text`/`reklamacja_text` czytane z bytu {@see
	 * ClassDefinitionsTaxonomy} przez {@see ClassDefinitionsTaxonomy::for_product()}
	 * (P-12.2c — REWIZJA: relacja per-produkt, nie literał + join po `kod`)).
	 * TE SAME dane czyta blok Checkout (`assets/js/checkout-block-filters.js`
	 * w `qutlet-theme`) — D-12.G2, endpoint Store API `cart-item` jest
	 * współdzielony między Cart i Checkout, więc kasa dostaje je bez osobnej
	 * rejestracji.
	 *
	 * @param array $cart_item Wiersz koszyka (`WC_Cart::get_cart()`).
	 * @return array<string, string>
	 */
	public static function cart_item_data( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$product_id     = $product->get_id();
		$definition     = ClassDefinitionsTaxonomy::for_product( $product_id ); // P-12.2c: relacja, nie literał + join po `kod`.
		$condition_code = $definition['kod'] ?? '';
		$market_price   = (float) get_post_meta( $product_id, MarketPriceField::META_KEY, true );
		$sale_price     = (float) $product->get_price();

		return array(
			'klasa_stanu'            => $condition_code,
			'klasa_kolor'            => $definition['kolor'] ?? '',
			'gwarancja_text'         => null !== $definition ? sprintf(
				/* translators: %s: formatted warranty period (e.g. "1 rok"). */
				__( 'Gwarancja %s', 'qutlet-core' ),
				self::period_years_text( $definition['okres_gwarancji_miesiace'] )
			) : '',
			'reklamacja_text'        => null !== $definition ? sprintf(
				/* translators: %s: formatted claim period (e.g. "1 rok"). */
				__( 'Reklamacja %s', 'qutlet-core' ),
				self::period_years_text( $definition['okres_reklamacji_miesiace'] )
			) : '',
			'old_price_formatted'    => $market_price > $sale_price ? wp_kses_post( wc_price( $market_price ) ) : '',
			// Oszczędność PER SZTUKĘ (jak `old_price_formatted`, NIE razy ilość) —
			// ten sam punkt odniesienia co cena sprzedaży w wierszu, która też jest
			// jednostkowa, nie linią razem. Suma całego koszyka (razy ilość) już
			// istnieje w `cart_totals_data()` (`total_savings_formatted`) — osobne pole,
			// osobny cel (wiersz produktu vs. podsumowanie).
			'item_savings_formatted' => $market_price > $sale_price ? wp_kses_post( wc_price( $market_price - $sale_price ) ) : '',
		);
	}

	/**
	 * Schemat pól z `cart_item_data()` (wymagany przez Store API).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cart_item_schema(): array {
		return array(
			'klasa_stanu'            => array(
				'description' => __( 'Kod klasy stanu (join key bytu klas stanu, dziś A-D).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'klasa_kolor'            => array(
				'description' => __( 'Kolor klasy stanu (hex, z bytu klas stanu) — kropka odznaki.', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'gwarancja_text'         => array(
				'description' => __( 'Sformatowany tekst „Gwarancja X" (okres z bytu klas stanu).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'reklamacja_text'        => array(
				'description' => __( 'Sformatowany tekst „Reklamacja X" (okres z bytu klas stanu).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'old_price_formatted'    => array(
				'description' => __( 'Sformatowana cena rynkowa nowego (tylko gdy wyższa od ceny sprzedaży).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'item_savings_formatted' => array(
				'description' => __( 'Sformatowana oszczędność per sztuka vs. cena rynkowa nowego (tylko gdy > 0).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Suma oszczędności całego koszyka vs. ceny rynkowe nowych produktów
	 * (kontrakt §6, odpowiednik `data-cart-savings-row` z prototypu).
	 *
	 * @return array<string, string>
	 */
	public static function cart_totals_data(): array {
		$cart          = WC()->cart;
		$total_savings = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$market_price = (float) get_post_meta( $product->get_id(), MarketPriceField::META_KEY, true );
			$sale_price   = (float) $product->get_price();

			if ( $market_price > $sale_price ) {
				$total_savings += ( $market_price - $sale_price ) * (int) $cart_item['quantity'];
			}
		}

		return array(
			// Store API zwraca gotowy HTML (wc_price()) — JS wstrzykuje ten wiersz
			// jako WĘZEŁ DOM (ten sam mechanizm co odznaki per-wiersz), nie przez
			// registerCheckoutFilters, więc surowy HTML jest tu bezpieczny (patrz
			// nagłówek assets/js/cart-block-filters.js w qutlet-theme).
			'subtotal_formatted'      => wp_kses_post( wc_price( (float) $cart->get_subtotal() ) ),
			'total_savings_formatted' => $total_savings > 0.0 ? wp_kses_post( wc_price( $total_savings ) ) : '',
		);
	}

	/**
	 * Schemat pól z `cart_totals_data()`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cart_totals_schema(): array {
		return array(
			'subtotal_formatted'      => array(
				'description' => __( 'Sformatowana wartość produktów (suma cen sprzedaży, bez dostawy).', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'total_savings_formatted' => array(
				'description' => __( 'Suma oszczędności koszyka vs. ceny rynkowe nowych produktów.', 'qutlet-core' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Okres w miesiącach jako tekst po polskiej odmianie liczebnika, w LATACH
	 * („1 rok"/„2 lata"/„5 lat"). Degraduje do zapisu w miesiącach, gdy
	 * `$months` nie jest wielokrotnością 12 (dzisiejsze klasy: 12 albo 24) —
	 * żeby nie zgadywać niepełnych lat.
	 *
	 * DUPLIKAT {@see \Qutlet\Theme\features\ProductPage\ProductPage::period_years_text()}
	 * (D-26.2.1) — oryginał w motywie zostaje bez zmian, używany też gdzie
	 * indziej (render strony produktu, poza zakresem tego przeniesienia).
	 *
	 * @param int $months Liczba miesięcy (`okres_gwarancji_miesiace`/`okres_reklamacji_miesiace`).
	 * @return string Pusty string, gdy `$months` <= 0.
	 */
	private static function period_years_text( int $months ): string {
		if ( $months <= 0 ) {
			return '';
		}

		if ( 0 !== $months % 12 ) {
			return self::period_months_text( $months );
		}

		$years = intdiv( $months, 12 );

		return sprintf(
			'%d %s',
			$years,
			self::pl_plural( $years, __( 'rok', 'qutlet-core' ), __( 'lata', 'qutlet-core' ), __( 'lat', 'qutlet-core' ) )
		);
	}

	/**
	 * Okres w miesiącach jako tekst po polskiej odmianie liczebnika, w
	 * MIESIĄCACH („12 miesięcy") — fallback {@see self::period_years_text()}
	 * dla okresów niebędących pełną wielokrotnością 12.
	 *
	 * DUPLIKAT {@see \Qutlet\Theme\features\ProductPage\ProductPage::period_months_text()}
	 * (D-26.2.1, patrz docblock {@see self::period_years_text()}).
	 *
	 * @param int $months Liczba miesięcy.
	 * @return string Pusty string, gdy `$months` <= 0.
	 */
	private static function period_months_text( int $months ): string {
		if ( $months <= 0 ) {
			return '';
		}

		return sprintf(
			'%d %s',
			$months,
			self::pl_plural( $months, __( 'miesiąc', 'qutlet-core' ), __( 'miesiące', 'qutlet-core' ), __( 'miesięcy', 'qutlet-core' ) )
		);
	}

	/**
	 * Polska odmiana liczebnika (1 / 2-4 / pozostałe, z nieregularnym
	 * wyjątkiem 12-14 wyłączonym z „2-4").
	 *
	 * DUPLIKAT {@see \Qutlet\Theme\features\ProductPage\ProductPage::pl_plural()}
	 * (D-26.2.1, patrz docblock {@see self::period_years_text()}).
	 *
	 * @param int    $count Liczba.
	 * @param string $one   Forma dla 1.
	 * @param string $few   Forma dla 2-4 (poza 12-14).
	 * @param string $many  Forma dla pozostałych.
	 * @return string
	 */
	private static function pl_plural( int $count, string $one, string $few, string $many ): string {
		if ( 1 === $count ) {
			return $one;
		}

		$mod10  = $count % 10;
		$mod100 = $count % 100;

		if ( $mod10 >= 2 && $mod10 <= 4 && ! ( $mod100 >= 12 && $mod100 <= 14 ) ) {
			return $few;
		}

		return $many;
	}
}
