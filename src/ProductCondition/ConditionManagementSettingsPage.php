<?php
/**
 * Slice ProductCondition — strona ustawień „Zarządzanie stanami" (P-22.4b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Strona ustawień pod menu WooCommerce (wzorzec `Pricing\DiscountRateSettingsPage`,
 * §11 kontraktu) — dziś JEDNO pole: tekst zastępczy „co w zestawie" dla widgetu
 * „Inne sztuki tego modelu" (`docs/plan.md` P-22.4, D-22.4.1/D-22.4.2).
 *
 * Konsumowane z `qutlet-theme` (`ProductPage::contents_sentence()`) przez
 * `use Qutlet\Core\ProductCondition\ConditionManagementSettingsPage;` +
 * stałą {@see self::FALLBACK_OPTION} — ten sam wzorzec cross-repo, jakim
 * `ProductPage` już dziś czyta `ClassDefinitionsTaxonomy`.
 *
 * **Odrzucona alternatywa (D-22.4.2):** dopisanie pola do istniejącej
 * `qutlet-allegro\OfferSync\ConditionMapPage` („Mapowanie stanów") — TAMTA
 * strona jest Allegro-specyficzna (mapowanie wartości „Stan" oferty Allegro →
 * nasza klasa) i jawnie read-only (D-12.1c.2); tekst zastępczy nie dotyczy
 * synchronizacji Allegro, więc dopisanie złamałoby granicę repo (`CLAUDE.md`
 * → „ruszasz dane między qutlet a Allegro → allegro"). `ConditionMapPage`
 * zostaje bez zmian, ta strona jest osobnym bytem — nazwa celowo ogólna
 * („Zarządzanie stanami", nie „Tekst zastępczy zestawu"), bo miejsce na
 * kolejne globalne ustawienia związane z klasami stanu w przyszłości.
 */
final class ConditionManagementSettingsPage {

	/**
	 * Slug strony (podmenu WooCommerce).
	 */
	private const PAGE_SLUG = 'qutlet-zarzadzanie-stanami';

	/**
	 * Grupa opcji Settings API (`settings_fields()` / `register_setting()`).
	 */
	private const OPTION_GROUP = 'qutlet_zarzadzanie_stanami';

	/**
	 * Opcja: tekst zastępczy „co w zestawie" w widgecie „Inne sztuki tego
	 * modelu" (`docs/kontrakt-danych.md` §19) — używany, gdy repeater
	 * `zawartosc_zestawu_pozycje` produktu jest pusty ALBO żaden wiersz nie ma
	 * `w_zestawie=true`. Puste ustawienie → `qutlet-theme` pomija cały wiersz
	 * „co w zestawie" (D-22.4.1) — celowo BEZ wymyślonej treści domyślnej.
	 */
	public const FALLBACK_OPTION = 'qutlet_zawartosc_zestawu_domyslny_tekst';

	/**
	 * Capability strony i zapisu opcji — ustawienie sklepowe, nie systemowe
	 * (spójnie z `DiscountRateSettingsPage`/`ConditionMapPage`).
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Wpina rejestrację menu i opcji. Wołane z bootstrapu core (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );

		// `options.php` sprawdza domyślnie `manage_options`; bez tego filtra zapis
		// przez Shop Managera (manage_woocommerce) kończyłby się odmową mimo
		// widocznej strony.
		add_filter(
			'option_page_capability_' . self::OPTION_GROUP,
			array( self::class, 'option_page_capability' )
		);
	}

	/**
	 * Rejestruje podmenu pod menu WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Zarządzanie stanami', 'qutlet-core' ),
			__( 'Zarządzanie stanami', 'qutlet-core' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Rejestruje opcję w Settings API.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::FALLBACK_OPTION,
			array(
				'type'              => 'string',
				'description'       => 'Tekst zastępczy "co w zestawie" w widgecie "Inne sztuki tego modelu", gdy produkt nie ma wypełnionego repeatera zawartości zestawu.',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Capability zapisu grupy opcji (filtr `option_page_capability_{group}`).
	 *
	 * @return string
	 */
	public static function option_page_capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Renderuje stronę ustawień: jedno pole tekstowe.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$value = get_option( self::FALLBACK_OPTION, '' );
		$value = is_string( $value ) ? $value : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zarządzanie stanami', 'qutlet-core' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Ustawienia globalne związane z klasami stanu produktów. Mapowanie wartości "Stan" z oferty Allegro na naszą klasę znajdziesz na osobnej stronie "Mapowanie stanów" (WooCommerce → Mapowanie stanów).',
					'qutlet-core'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::FALLBACK_OPTION ); ?>">
								<?php esc_html_e( 'Tekst zastępczy „co w zestawie"', 'qutlet-core' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="<?php echo esc_attr( self::FALLBACK_OPTION ); ?>"
								name="<?php echo esc_attr( self::FALLBACK_OPTION ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
							/>
							<p class="description">
								<?php
								esc_html_e(
									'Widget "Inne sztuki tego modelu" na stronie produktu pokazuje przy każdej sztuce jedno zdanie "co w zestawie", złożone z pozycji zestawu oznaczonych jako dołączone. Gdy produkt nie ma żadnej takiej pozycji, widget pokazuje ten tekst zamiast pustego wiersza. Pozostaw puste, żeby wiersz "co w zestawie" był wtedy pomijany.',
									'qutlet-core'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
