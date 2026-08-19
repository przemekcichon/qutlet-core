<?php
/**
 * Slice ProductCondition — strona ustawień „Treści sklepu" (P-22.4b, przemianowana P-22.5b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductCondition;

/**
 * Strona ustawień pod menu WooCommerce (wzorzec `Pricing\DiscountRateSettingsPage`,
 * §11 kontraktu) — jedenaście pól tekstowych: tekst zastępczy „co w zestawie"
 * dla widgetu „Inne sztuki tego modelu" (P-22.4b, D-22.4.1/D-22.4.2) oraz
 * dziesięć tekstów polityk zwrotu/wysyłki dla strony produktu (P-22.5,
 * D-22.5.4, kontrakt §19.2).
 *
 * **REWIZJA D-22.5.4 (sesja 2026-08-19):** klasa powstała w P-22.4b jako
 * `ConditionManagementSettingsPage` („Zarządzanie stanami") z JEDNYM polem.
 * P-22.5 zaimplementował pierwotnie te 10 tekstów jako pola per-klasa na
 * {@see ClassDefinitionsTaxonomy} — po zobaczeniu efektu użytkownik ocenił je
 * jako treść SKLEPOWĄ/KANAŁOWĄ, niezwiązaną z fizycznym stanem egzemplarza
 * (dokładnie granica, którą wykonawca proponował na starcie sesji P-22.5 i
 * którą użytkownik wtedy odrzucił jako zbyt daleko idące uogólnienie) —
 * zdecydował przenieść je tutaj i przy okazji przemianować klasę/stronę na
 * bardziej ogólną, bo nie są to już „ustawienia klas stanu". Nazwa i klasa
 * celowo ogólne — miejsce na kolejne globalne teksty sklepu w przyszłości.
 * `PAGE_SLUG`/`OPTION_GROUP` zmieniają się razem z nazwą; `FALLBACK_OPTION`
 * (klucz opcji „co w zestawie") zostaje BEZ ZMIAN — zero migracji danych.
 *
 * Konsumowane z `qutlet-theme` (`ProductPage::contents_sentence()`/
 * `ProductPage::store_text()`) przez
 * `use Qutlet\Core\ProductCondition\StoreContentSettingsPage;` + stałe
 * `::FALLBACK_OPTION`/`::OPTION_*` — ten sam wzorzec cross-repo, jakim
 * `ProductPage` już dziś czyta `ClassDefinitionsTaxonomy`.
 *
 * **Odrzucona alternatywa (D-22.4.2, nadal aktualna dla P-22.5):** dopisanie
 * pól do istniejącej `qutlet-allegro\OfferSync\ConditionMapPage` („Mapowanie
 * stanów") — TAMTA strona jest Allegro-specyficzna (mapowanie wartości
 * „Stan" oferty Allegro → nasza klasa) i jawnie read-only (D-12.1c.2); te
 * teksty nie dotyczą synchronizacji Allegro, więc dopisanie złamałoby
 * granicę repo (`CLAUDE.md` → „ruszasz dane między qutlet a Allegro →
 * allegro"). `ConditionMapPage` zostaje bez zmian, ta strona jest osobnym
 * bytem.
 */
final class StoreContentSettingsPage {

	/**
	 * Slug strony (podmenu WooCommerce).
	 */
	private const PAGE_SLUG = 'qutlet-tresci-sklepu';

	/**
	 * Grupa opcji Settings API (`settings_fields()` / `register_setting()`).
	 */
	private const OPTION_GROUP = 'qutlet_tresci_sklepu';

	/**
	 * Opcja: tekst zastępczy „co w zestawie" w widgecie „Inne sztuki tego
	 * modelu" (`docs/kontrakt-danych.md` §19.1) — używany, gdy repeater
	 * `zawartosc_zestawu_pozycje` produktu jest pusty ALBO żaden wiersz nie ma
	 * `w_zestawie=true`. Puste ustawienie → `qutlet-theme` pomija cały wiersz
	 * „co w zestawie" (D-22.4.1) — celowo BEZ wymyślonej treści domyślnej.
	 */
	public const FALLBACK_OPTION = 'qutlet_zawartosc_zestawu_domyslny_tekst';

	/**
	 * Opcje: teksty polityk zwrotu/wysyłki strony produktu (P-22.5, D-22.5.4,
	 * kontrakt §19.2) — w odróżnieniu od {@see self::FALLBACK_OPTION} KAŻDA
	 * niesie niepusty domyślny seed (dzisiejszy literał z
	 * `content-single-product.php`) przez `register_setting()` → `default`,
	 * więc `get_option()` zwraca go automatycznie zanim admin cokolwiek
	 * zapisze — bez osobnej komendy backfill (w odróżnieniu od pól per-klasa
	 * w §2.2, gdzie term meta wymaga jawnego zapisu per term).
	 */
	public const OPTION_ZWROT_NAGLOWEK              = 'qutlet_zwrot_naglowek';
	public const OPTION_ZWROT_TAG_QUTLET            = 'qutlet_zwrot_tag_qutlet';
	public const OPTION_ZWROT_TAG_ALLEGRO           = 'qutlet_zwrot_tag_allegro';
	public const OPTION_WYSYLKA_NAGLOWEK            = 'qutlet_wysylka_naglowek';
	public const OPTION_ZWROT_OPIS_QUTLET           = 'qutlet_zwrot_opis_qutlet';
	public const OPTION_ZWROT_OPIS_ALLEGRO          = 'qutlet_zwrot_opis_allegro';
	public const OPTION_WYSYLKA_OPIS                = 'qutlet_wysylka_opis';
	public const OPTION_ZWROT_AKORDEON_OPIS_QUTLET  = 'qutlet_zwrot_akordeon_opis_qutlet';
	public const OPTION_ZWROT_AKORDEON_OPIS_ALLEGRO = 'qutlet_zwrot_akordeon_opis_allegro';
	public const OPTION_STAN_UZYWANY_OPIS           = 'qutlet_stan_uzywany_opis';

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
			__( 'Treści sklepu', 'qutlet-core' ),
			__( 'Treści sklepu', 'qutlet-core' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Definicje pól renderowanych na stronie (etykieta, opis, typ pola,
	 * domyślna treść) — jedno źródło prawdy dla `register_setting()` i
	 * `render_page()`, żeby nie powielać 11 wpisów w dwóch miejscach.
	 *
	 * @return array<string, array{label: string, description: string, type: 'text'|'textarea', default: string}>
	 */
	private static function fields(): array {
		return array(
			self::FALLBACK_OPTION                     => array(
				'label'       => __( 'Tekst zastępczy „co w zestawie"', 'qutlet-core' ),
				'description' => __( 'Widget "Inne sztuki tego modelu" na stronie produktu pokazuje przy każdej sztuce jedno zdanie "co w zestawie", złożone z pozycji zestawu oznaczonych jako dołączone. Gdy produkt nie ma żadnej takiej pozycji, widget pokazuje ten tekst zamiast pustego wiersza. Pozostaw puste, żeby wiersz "co w zestawie" był wtedy pomijany.', 'qutlet-core' ),
				'type'        => 'text',
				'default'     => '',
			),
			self::OPTION_ZWROT_NAGLOWEK                => array(
				'label'       => __( 'Zwrot — nagłówek', 'qutlet-core' ),
				'description' => __( 'Krótki nagłówek w panelu zakupu (oba kanały) i na liście korzyści.', 'qutlet-core' ),
				'type'        => 'text',
				'default'     => __( '14 dni na zwrot', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_TAG_QUTLET              => array(
				'label'       => __( 'Zwrot — tag kanału Qutlet', 'qutlet-core' ),
				'description' => __( 'Krótki tag obok nagłówka zwrotu, wyłącznie panel kanału Qutlet.', 'qutlet-core' ),
				'type'        => 'text',
				'default'     => __( 'Koszt po Twojej stronie', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_TAG_ALLEGRO             => array(
				'label'       => __( 'Zwrot — tag kanału Allegro', 'qutlet-core' ),
				'description' => __( 'Krótki tag obok nagłówka zwrotu, wyłącznie panel kanału Allegro.', 'qutlet-core' ),
				'type'        => 'text',
				'default'     => __( 'Możliwy bezpłatny', 'qutlet-core' ),
			),
			self::OPTION_WYSYLKA_NAGLOWEK              => array(
				'label'       => __( 'Wysyłka — nagłówek', 'qutlet-core' ),
				'description' => __( 'Krótki nagłówek w panelu zakupu (oba kanały) i na liście korzyści.', 'qutlet-core' ),
				'type'        => 'text',
				'default'     => __( 'Wysyłka w 1 dzień roboczy', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_OPIS_QUTLET             => array(
				'label'       => __( 'Zwrot — opis kanału Qutlet', 'qutlet-core' ),
				'description' => __( 'Zdanie pod etykietą „Polityka zwrotów:" w panelu kanału Qutlet.', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( 'W razie zwrotu produktu kupionego w naszym sklepie, koszty przesyłki zwrotnej pokrywasz sam.', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_OPIS_ALLEGRO            => array(
				'label'       => __( 'Zwrot — opis kanału Allegro', 'qutlet-core' ),
				'description' => __( 'Zdanie pod etykietą „Polityka zwrotów:" w panelu kanału Allegro.', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( 'Zwrot całkowicie bezpłatny przy wyborze Allegro Delivery oraz dla Allegrowiczów Smart.', 'qutlet-core' ),
			),
			self::OPTION_WYSYLKA_OPIS                  => array(
				'label'       => __( 'Wysyłka — opis (akordeon)', 'qutlet-core' ),
				'description' => __( 'Akordeon „Dostawa i zwroty" na stronie produktu, karta „Szybka wysyłka".', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( 'Wysyłamy w najbliższy dzień roboczy (sesja rano/popołudnie).', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_AKORDEON_OPIS_QUTLET    => array(
				'label'       => __( 'Zwrot — opis kanału Qutlet (akordeon)', 'qutlet-core' ),
				'description' => __( 'Akordeon „Dostawa i zwroty" na stronie produktu, karta zwrotu kanału Qutlet.', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( '14 dni na zmianę zdania. Koszt przesyłki zwrotnej po stronie kupującego.', 'qutlet-core' ),
			),
			self::OPTION_ZWROT_AKORDEON_OPIS_ALLEGRO   => array(
				'label'       => __( 'Zwrot — opis kanału Allegro (akordeon)', 'qutlet-core' ),
				'description' => __( 'Akordeon „Dostawa i zwroty" na stronie produktu, karta „Zwrot — Allegro".', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( '14 dni na zmianę zdania. Zwrot bezpłatny — przy wyborze Allegro Delivery lub abonamentu Smart.', 'qutlet-core' ),
			),
			self::OPTION_STAN_UZYWANY_OPIS             => array(
				'label'       => __( 'Stan używany — zapewnienie', 'qutlet-core' ),
				'description' => __( 'Drugie zdanie w akordeonie „Gwarancja i reklamacje" (pierwsze, „Wszystkie produkty w Qutlet sprzedawane są jako używane.", zostaje statyczne w motywie).', 'qutlet-core' ),
				'type'        => 'textarea',
				'default'     => __( 'Gwarancja i prawo do reklamacji są identyczne dla każdego egzemplarza.', 'qutlet-core' ),
			),
		);
	}

	/**
	 * Rejestruje wszystkie opcje w Settings API.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		foreach ( self::fields() as $option_name => $field ) {
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'string',
					'description'       => $field['description'],
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => $field['default'],
					'show_in_rest'      => false,
				)
			);
		}
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
	 * Renderuje stronę ustawień: jedenaście pól tekstowych.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Treści sklepu', 'qutlet-core' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Teksty globalne pokazywane na stronie produktu (polityka zwrotu/wysyłki, tekst zastępczy widgetu "Inne sztuki tego modelu"). Mapowanie wartości "Stan" z oferty Allegro na naszą klasę znajdziesz na osobnej stronie "Mapowanie stanów" (WooCommerce → Mapowanie stanów).',
					'qutlet-core'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( self::fields() as $option_name => $field ) : ?>
						<?php
						$value = get_option( $option_name, $field['default'] );
						$value = is_string( $value ) ? $value : '';
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $option_name ); ?>">
									<?php echo esc_html( $field['label'] ); ?>
								</label>
							</th>
							<td>
								<?php if ( 'textarea' === $field['type'] ) : ?>
									<textarea
										class="large-text"
										rows="2"
										id="<?php echo esc_attr( $option_name ); ?>"
										name="<?php echo esc_attr( $option_name ); ?>"
									><?php echo esc_textarea( $value ); ?></textarea>
								<?php else : ?>
									<input
										type="text"
										class="regular-text"
										id="<?php echo esc_attr( $option_name ); ?>"
										name="<?php echo esc_attr( $option_name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
									/>
								<?php endif; ?>
								<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
