<?php
/**
 * Plugin Name:       Qutlet Core
 * Plugin URI:        https://github.com/przemekcichon/qutlet-core
 * Description:       Model danych Qutlet: pola ACF, Custom Post Types i glue do WooCommerce. Wystawia dane, z których korzystają motyw i pozostałe wtyczki Qutlet. Bez warstwy graficznej.
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Requires at least: 6.4
 * Author:            Qutlet
 * Text Domain:       qutlet-core
 * License:           proprietary
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core;

// Blokada bezpośredniego wywołania pliku poza WordPressem.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wersja wtyczki (jedno źródło prawdy — używać zamiast literału).
 */
const VERSION = '0.1.0';

/*
 * Autoloader Composera (D-G1): ładowany z guardem. Brak `vendor/autoload.php`
 * NIE jest fatal errorem — pokazujemy notice w adminie i przerywamy bootstrap,
 * żeby nie wywrócić całego WordPressa.
 */
$qutlet_core_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $qutlet_core_autoload ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );

	return;
}

require_once $qutlet_core_autoload;

// Model danych rejestrujemy dopiero, gdy twarde zależności są obecne (D-G5).
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Punkt wejścia wtyczki. Uruchamiany na `plugins_loaded`.
 *
 * FAZA 0 = czysty szkielet: brak slice'ów i rejestracji modelu danych.
 * Kolejne fazy dokładają tu inicjalizację swoich slice'ów z `src/`.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! dependencies_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependencies_notice' );

		return; // No-op: bez twardych zależności core niczego nie rejestruje.
	}

	// Slice'y modelu danych (pola ACF, CPT, glue do WooCommerce). Każdy slice
	// sam wpina swoje hooki — bootstrap tylko go inicjalizuje.
	ProductCondition\ProductConditionFields::init();
	AllegroChannel\AllegroChannelFields::init();
	ReadingTime\ReadingTimeMeta::init();

	// ProductInfo (P-5.1b): warstwa surowa (prywatne meta z Allegro) + przerobiona
	// (opis ACF). Specyfikacja przerobiona = natywne atrybuty WC (bez rejestracji).
	ProductInfo\RawLayerMeta::init();
	ProductInfo\RewrittenFields::init();

	// ProductInfo (P-5.3): podgląd warstwy surowej w adminie (metabox, read-only).
	ProductInfo\RawLayerMetaBox::init();

	// AllegroLink (P-5.2b): dyskretne pola nie-Woo z mappingu (id oferty, MPN,
	// kategoria Allegro + ścieżka) — prywatne meta, wypełnia sync (FAZA 6).
	AllegroLink\AllegroLinkMeta::init();

	// Pricing (P-6.1a): globalna stawka rabatu (opcja + strona pod menu Woo) i
	// nadpisanie per produkt (zakładka General). Formułę ceny stosuje import
	// (qutlet-allegro, P-6.1b) — core wystawia tylko powierzchnię danych.
	Pricing\DiscountRateSettingsPage::init();
	Pricing\ProductDiscountRateField::init();

	// OfferSync (P-6.2a): mostek zdarzeń stanu zamówienia Woo → akcja domenowa
	// (D-6.G3). Transfer do Allegro robi konsument (qutlet-allegro, P-6.2b).
	OfferSync\StockEvents::init();

	// OrderSync (P-6.5b): rejestracja własnego statusu zamówienia `wc-shipped`
	// („Wysłane") — glue do WooCommerce (D-6.5.5). Ustawia go pull statusów
	// Allegro→Woo (qutlet-allegro, P-6.5c); core tylko rejestruje status.
	OrderSync\OrderStatuses::init();
}

/**
 * Sprawdza obecność twardych zależności core (D-G5): WooCommerce + ACF PRO.
 *
 * Weryfikujemy OBECNOŚĆ na `plugins_loaded` (kolejność callbacków to sprawa
 * dependentów — patrz D-G5). Literały wykrywania sprawdzone w realnym kodzie:
 * WooCommerce definiuje klasę `WooCommerce`; ACF PRO definiuje stałą `ACF_PRO`
 * (`acf_is_pro()` to dokładnie `defined( 'ACF_PRO' ) && ACF_PRO`), której wersja
 * darmowa ACF nie ustawia. Oba testy to literały-stringi — nie wymagają stubów.
 *
 * @return bool True, gdy oba wymagania są aktywne.
 */
function dependencies_met(): bool {
	return class_exists( 'WooCommerce' ) && defined( 'ACF_PRO' );
}

/**
 * Notice w adminie: brak autoloadera Composera.
 *
 * @return void
 */
function render_missing_autoloader_notice(): void {
	$message = __(
		'Qutlet Core: brak autoloadera Composera (vendor/autoload.php). Uruchom „composer install" w katalogu wtyczki.',
		'qutlet-core'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Notice w adminie: brak twardych zależności (WooCommerce / ACF PRO).
 *
 * @return void
 */
function render_missing_dependencies_notice(): void {
	$message = __(
		'Qutlet Core wymaga aktywnych wtyczek WooCommerce oraz Advanced Custom Fields PRO. Do czasu ich aktywacji wtyczka nie robi nic.',
		'qutlet-core'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
