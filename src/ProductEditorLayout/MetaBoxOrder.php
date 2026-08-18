<?php
/**
 * Slice ProductEditorLayout — docelowa kolejność metaboxów edycji produktu (P-21.1b, D-21.1.1).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\ProductEditorLayout;

use Qutlet\Core\AllegroChannel\AllegroChannelFields;
use Qutlet\Core\ProductCondition\ProductConditionFields;
use Qutlet\Core\ProductCondition\ShipmentContentsFields;
use Qutlet\Core\ProductInfo\RawLayerMetaBox;
use Qutlet\Core\ProductReviewWizard\CategoryPreviewMetaBox;

/**
 * Wymusza docelową kolejność metaboxów na ekranie edycji produktu (D-21.1.1)
 * przez jednorazowy seed `meta-box-order_product` — TEN SAM mechanizm, którego
 * WooCommerce już używa dla SWOJEGO domyślnego układu
 * ({@see \WC_Admin_Meta_Boxes::add_product_boxes_sort_order()},
 * `includes/admin/class-wc-admin-meta-boxes.php`, hook `add_meta_boxes`
 * priorytet 40).
 *
 * Ground-truth (P-21.1, 2026-08-18) pokazał, że priorytety `add_meta_box()`
 * (rdzeń WP, `do_meta_boxes()`, bucket'y `high, sorted, core, default, low`)
 * NIE wystarczą dla D-21.1.1:
 * - Trzy z czterech boxów kolumny `normal` (Kanał Allegro, Stan produktu,
 *   Zawartość przesyłki) to grupy ACF rejestrowane W JEDNYM przebiegu pętli
 *   `Acf_Form_Post::add_meta_boxes()` (hook `add_meta_boxes`, priorytet 10,
 *   `includes/forms/form-post.php` w ACF PRO) — ich wzajemna kolejność jest
 *   nierozdzielna przez priorytet hooka, więc metaboxa `qutlet-ai` „Opis
 *   produktu (AI)" ({@see self::AI_GENERATION_METABOX_ID}, osobny hook, TAKŻE
 *   priorytet 10) nie da się wstawić MIĘDZY nie samym priorytetem.
 * - W kolumnie `side` WooCommerce przypina WŁASNY domyślny porządek
 *   (`submitdiv,postimagediv,woocommerce-product-images,product_catdiv,
 *   tagsdiv-product_tag`) do priorytetu `sorted` rdzenia WP, który renderuje
 *   się PRZED zwykłymi priorytetami (`core`/`default`/`low`) — więc box
 *   „Mapowanie kategorii" ({@see CategoryPreviewMetaBox}, priorytet `low`, nie
 *   w tamtej liście) nie da się wstawić między „Kategorie produktów" a
 *   „Znaczniki produktu" samym priorytetem.
 *
 * Rozwiązanie: seedujemy WŁASNY, PEŁNY porządek (nasze boxy + natywne Woo/WP)
 * do TEGO SAMEGO usermeta, TYLKO gdy bieżący user go jeszcze nie ma
 * (identyczny warunek co WooCommerce) — priorytet hooka 35, czyli PRZED
 * seedem WooCommerce (40), żeby nasz zapis wygrał wyścig o "czy usermeta jest
 * już ustawione". Zwykłe przeciąganie metaboxów myszką
 * (`wp-admin/js/postbox.js`) działa bez zmian — nadpisuje to samo usermeta
 * bieżącym układem ekranu, więc user nadal może ręcznie przełożyć boxy; ten
 * seed dotyczy WYŁĄCZNIE użytkownika bez wcześniej zapisanej preferencji
 * (nowe konto, świeża instalacja, produkcja).
 *
 * UWAGA (recenzja PR#34, potwierdzone ground-truthem i runtime): callback
 * NIE MOŻE ograniczać się do ekranu produktu. `add_product_boxes_sort_order()`
 * jest wpięty na generycznym `add_meta_boxes` BEZ sprawdzania `$post_type` —
 * odpala się na KAŻDYM ekranie edycji (strona, wpis, zamówienie, komentarz),
 * zawsze czytając/zapisując TEN SAM klucz `meta-box-order_product`. Jedno
 * wejście na inny ekran PRZED pierwszym wejściem na produkt seeduje ten klucz
 * domyślnym (samym-Woo) układem — i wygrywa na stałe, bo warunek "jeszcze
 * nie ma wartości" jest już fałszywy. Nasz seed musi więc być RÓWNIE
 * generyczny (bez guardu po `$post_type`), żeby wygrać wyścig niezależnie od
 * tego, który ekran user otworzy jako pierwszy.
 *
 * UWAGA 2 (recenzja PR#34): D-21.1.1 wymienia dla kolumny `normal` 8 pozycji,
 * ale WooCommerce rejestruje jeszcze jeden WIDOCZNY, nieukryty box —
 * „Krótki opis produktu" (`postexcerpt`, `WC_Admin_Meta_Boxes::add_meta_boxes()`),
 * którego plan nie uwzględniał. D-21.1.2 (decyzja użytkownika, dopisana do
 * `docs/plan.md` w `qutlet-meta`): trafia na koniec, po „Podgląd opisu z
 * Allegro" (pozycja #9) — najmniej inwazyjne miejsce, zgodne z tym, gdzie i
 * tak ląduje bez seeda (priorytet `default`, po buckecie `sorted`).
 *
 * Identyfikatory metaboxów `qutlet-ai` ({@see self::AI_TITLE_METABOX_ID},
 * {@see self::AI_GENERATION_METABOX_ID}) są zduplikowane świadomie — core NIE
 * importuje klas z `qutlet-ai` (granica repo, `CLAUDE.md` § Struktura), wzorem
 * {@see \Qutlet\Core\ProductReviewWizard\ProductReviewWizard}. Literały
 * potwierdzone ground-truthem z `qutlet-ai\src\AiRewrite\
 * TitleGenerationMetaBox::META_BOX_ID` / `GenerationMetaBox::META_BOX_ID`.
 */
final class MetaBoxOrder {

	/**
	 * Metabox ID `TitleGenerationMetaBox::META_BOX_ID` (`qutlet-ai`).
	 */
	private const AI_TITLE_METABOX_ID = 'qutlet_ai_title_generator';

	/**
	 * Metabox ID `GenerationMetaBox::META_BOX_ID` (`qutlet-ai`).
	 */
	private const AI_GENERATION_METABOX_ID = 'qutlet_ai_generation';

	/**
	 * Wpina seed na `add_meta_boxes`, priorytet 35 — PRZED
	 * `WC_Admin_Meta_Boxes::add_product_boxes_sort_order()` (priorytet 40),
	 * żeby nasz zapis usermeta wygrał wyścig o "czy jest już ustawione".
	 * CELOWO generyczny hook (bez `add_meta_boxes_product`/guardu ekranu) —
	 * patrz UWAGA w docbloku klasy, czemu ograniczenie do ekranu produktu
	 * przegrywało z Woo, gdy user otworzył inny ekran edycji jako pierwszy.
	 * Wołane z bootstrapu core (na `plugins_loaded`, po sprawdzeniu twardych
	 * zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'seed_default_order' ), 35 );
	}

	/**
	 * Zapisuje docelowy porządek (D-21.1.1) jako domyślne
	 * `meta-box-order_product` bieżącego usera — WYŁĄCZNIE gdy jeszcze nie ma
	 * zapisanej preferencji (identyczny warunek co
	 * {@see \WC_Admin_Meta_Boxes::add_product_boxes_sort_order()}). Zapisuje
	 * WYŁĄCZNIE klucz `meta-box-order_product` (dane statyczne, ten sam
	 * niezależnie od ekranu) — bezpieczne wołanie na dowolnym typie posta.
	 *
	 * @return void
	 */
	public static function seed_default_order(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id || get_user_meta( $user_id, 'meta-box-order_product', true ) ) {
			return;
		}

		update_user_meta(
			$user_id,
			'meta-box-order_product',
			array(
				// Jedyny box w tym kontekście (P-20.4b) — kolejność bez znaczenia.
				'acf_after_title' => self::AI_TITLE_METABOX_ID,
				'normal'          => implode(
					',',
					array(
						AllegroChannelFields::metabox_id(),      // 3. Kanał Allegro.
						self::AI_GENERATION_METABOX_ID,          // 4. Opis produktu (AI).
						ProductConditionFields::metabox_id(),    // 5. Stan produktu.
						ShipmentContentsFields::metabox_id(),    // 6. Zawartość przesyłki.
						'woocommerce-product-data',              // 7. Dane produktu (natywny Woo).
						RawLayerMetaBox::metabox_id(),           // 8. Podgląd opisu z Allegro.
						'postexcerpt',                           // 9. Krótki opis produktu (natywny Woo, D-21.1.2).
					)
				),
				'side'            => implode(
					',',
					array(
						'submitdiv',                                  // 1. Opublikuj (natywny WP).
						'postimagediv',                                // 2. Obrazek produktu (natywny WP).
						'woocommerce-product-images',                  // 3. Galeria produktu (natywny Woo).
						'product_catdiv',                              // 4. Kategorie produktów (natywny WP).
						CategoryPreviewMetaBox::META_BOX_ID,           // 5. Mapowanie kategorii.
						'tagsdiv-product_tag',                         // 6. Znaczniki produktu (natywny WP).
						'product_branddiv',                            // 7. Marki (natywny Woo, taksonomia product_brand).
					)
				),
				'advanced'        => '',
			)
		);
	}
}
