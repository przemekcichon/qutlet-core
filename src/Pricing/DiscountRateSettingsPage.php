<?php
/**
 * Slice Pricing — strona ustawień globalnej stawki rabatu (P-6.1a).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\Pricing;

/**
 * Strona ustawień globalnej stawki rabatu (D-6.1.1): podmenu pod menu WooCommerce,
 * jedno pole procentowe zapisywane przez Settings API do opcji
 * `qutlet_stawka_rabatu` (kontrakt §11).
 *
 * Wartość odpowiada średnim miesięcznym kosztom prowadzenia działalności na
 * Allegro (prowizje itd.) i jest wprowadzana ręcznie, gdy koszty się zmienią —
 * import (P-6.1b) przelicza z niej cenę sklepu `_price` przy każdym przebiegu.
 *
 * Troski WP-owe (Settings API, capability) mieszkają WEWNĄTRZ slice'a Pricing —
 * bez globalnego `settings/` (vertical slice, CLAUDE.md).
 */
final class DiscountRateSettingsPage {

	/**
	 * Slug strony ustawień (podmenu WooCommerce).
	 */
	private const PAGE_SLUG = 'qutlet-pricing';

	/**
	 * Grupa opcji Settings API (`settings_fields()` / `register_setting()`).
	 */
	private const OPTION_GROUP = 'qutlet_pricing';

	/**
	 * Capability strony i zapisu opcji. `manage_woocommerce` (rola Shop Manager +
	 * admin) — to ustawienie sklepowe, nie systemowe, więc nie `manage_options`.
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
	 * Rejestruje podmenu pod menu WooCommerce (D-6.1.1: „gdzieś pod menu WooCommerce").
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Qutlet — stawka rabatu', 'qutlet-core' ),
			__( 'Qutlet — stawka rabatu', 'qutlet-core' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Rejestruje opcję w Settings API (sanityzacja wspólna z polem produktu).
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			DiscountRate::OPTION_NAME,
			array(
				'type'              => 'string',
				'description'       => 'Globalna stawka rabatu ceny sklepu względem ceny Allegro (procent).',
				'sanitize_callback' => array( DiscountRate::class, 'sanitize_percent' ),
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
	 * Renderuje stronę ustawień: jedno pole procentowe + opis formuły.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$value = get_option( DiscountRate::OPTION_NAME, '' );
		$value = is_string( $value ) ? $value : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Qutlet — stawka rabatu', 'qutlet-core' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Cena sklepu Qutlet jest liczona przy imporcie/synchronizacji z ceny Allegro: _price = cena_allegro × (1 − stawka/100). Wartość globalna odpowiada średnim miesięcznym kosztom prowadzenia działalności na Allegro; można ją nadpisać na pojedynczym produkcie (zakładka General danych produktu).',
					'qutlet-core'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( DiscountRate::OPTION_NAME ); ?>">
								<?php esc_html_e( 'Globalna stawka rabatu (%)', 'qutlet-core' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								min="0"
								max="100"
								step="any"
								id="<?php echo esc_attr( DiscountRate::OPTION_NAME ); ?>"
								name="<?php echo esc_attr( DiscountRate::OPTION_NAME ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'Puste = 0% (cena sklepu równa cenie Allegro do czasu ustawienia stawki).', 'qutlet-core' ); ?>
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
