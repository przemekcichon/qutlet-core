# Slice `ProductTaxonomies/` — marka (P-1.1)

**Ten slice celowo NIE zawiera kodu.** P-1.1 („Taksonomia marka") jest punktem
**no-op** dla `qutlet-core`: marka to **natywna taksonomia WooCommerce
`product_brand`**, a nie własna taksonomia rejestrowana przez core.

Ten plik istnieje, żeby punkt planu „nie wisiał w próżni" — dokumentuje decyzję i
dowód z realnego kodu, oraz oznacza miejsce, w którym mieszkałyby ewentualne
przyszłe modyfikacje taksonomii marki (gdyby zaszła potrzeba).

## Decyzja (źródło prawdy)

- **D-1.1.1 [ROZSTRZYGNIĘTE]:** marka = natywna `product_brand` (WC_Brands).
  Pełny zapis w `qutlet-meta/docs/kontrakt-danych.md` §3 + log decyzji.
- `qutlet-core` **NIE** rejestruje własnej taksonomii `marka` ani atrybutu
  `pa_marka` — **konsumuje** natywną.

## Dowód z realnego kodu WooCommerce (10.9.4 tej instalacji)

`product_brand` jest dostarczana przez WooCommerce **bez żadnego feature-flага ani
ustawienia** — nie trzeba nic robić w kodzie, by ją włączyć:

- `woocommerce/src/Internal/Brands.php` → `Brands::is_enabled()` zwraca `true`
  bezwarunkowo („As of WooCommerce 9.6, Brands is enabled for all users").
- `woocommerce/includes/class-wc-brands.php` → `WC_Brands::init_taxonomy()`
  rejestruje `product_brand` na hooku `woocommerce_register_taxonomy`
  (taksonomia hierarchiczna na typie `product`, `show_in_rest`, term meta gratis).

Weryfikacja runtime (WP-CLI, przy aktywnym WooCommerce):

```
$ wp taxonomy get product_brand
name          product_brand
label         Brands
object_type   ["product"]
hierarchical  true
public        true
```

## Jedyny warunek dostępności: aktywny WooCommerce

`product_brand` istnieje wtedy i tylko wtedy, gdy WooCommerce jest **aktywny** —
co jest już **twardą zależnością** `qutlet-core` (D-G5: bootstrap robi no-op +
`admin_notice`, gdy Woo/ACF PRO nieaktywne). To troska **konfiguracji środowiska**,
nie kod tego slice'a.

## Odczyt w motywie (konsument)

```php
$terms = get_the_terms( $product_id, 'product_brand' ); // WP_Term[]|false|WP_Error
```

## Kiedy TU dopisać kod

Gdyby zaszła potrzeba modyfikacji natywnej taksonomii marki (etykiety, `rewrite`,
term meta, widoczność), miejscem jest ten slice — przez filtr WooCommerce
`register_taxonomy_product_brand`, nie przez rejestrację własnej taksonomii.
Na dziś takiej potrzeby nie ma.
