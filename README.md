# MageEnv_DynamicCartProgressHyva

Composer package: `mageenv/module-dynamic-cart-progress-hyva`
Magento module: `MageEnv_DynamicCartProgressHyva`
Version: 1.0.0

## Purpose

Hyvä storefront rendering for **MageEnv Dynamic Cart Progress**. It provides
Alpine.js Mini Cart and Cart Page progress components for Hyvä themes, visually
and functionally equivalent to the Luma components shipped in the core module.

This module contains **no business logic**. Campaign resolution, the comparable
cart amount, milestone evaluation, messaging and the storefront payload all live
in `MageEnv_DynamicCartProgress`. This module only renders that payload with
Alpine.js and consumes the same `mageenv-dcp-progress` `customer-data` section
(via Hyvä's `private-content-loaded` flow) and the same shared
`MageEnv\DynamicCartProgress\ViewModel\CartProgress`.

## Relationship to the core module

| | |
|---|---|
| Business core (required) | `mageenv/module-dynamic-cart-progress` `^1.0.0` |
| This module | Hyvä-only presentation layer for that core |

Install this module **in addition to** the core module on any installation with
a Hyvä store view. Its layout uses Hyvä's `hyva_` handles, so in a mixed
installation (some store views on Luma, some on Hyvä) it renders only on the
Hyvä store views. A Luma-only store needs the core module alone.

## Requirements

| | Supported |
|---|---|
| Magento Open Source | 2.4.8 – 2.4.9 |
| PHP | 8.3, 8.4, 8.5 |
| `mageenv/module-dynamic-cart-progress` | ^1.0.0 |
| `hyva-themes/magento2-theme-module` | >=1.1.0 <2 |

Composer constraints: `php: ~8.3.0||~8.4.0||~8.5.0`.

Verified in the Hyvä default theme (theme module 1.5.2) on Magento 2.4.9: Mini
Cart drawer and Cart page, desktop and mobile. The unit contract tests
(CSP-safe external script, `x-data` escaping / XSS safety, Luma parity) pass on
Magento 2.4.8-p1 and 2.4.9 and on PHP 8.3 / 8.4 / 8.5.

The components use the standard Alpine.js build that Hyvä ships by default.
Hyvä's optional Alpine CSP build (strict CSP without `unsafe-eval`) is not
supported yet.

## Installation

Buy and install the core module first: it comes from the
[Adobe Commerce Marketplace](https://commercemarketplace.adobe.com/mageenv-module-dynamic-cart-progress.html)
through `repo.magento.com`, using the access keys of the account that bought it.
Then add this free package:

```bash
composer require mageenv/module-dynamic-cart-progress-hyva
bin/magento module:enable MageEnv_DynamicCartProgress MageEnv_DynamicCartProgressHyva
bin/magento setup:upgrade
bin/magento setup:di:compile      # production mode only
bin/magento cache:flush
```

For local development the module lives in
`app/code/MageEnv/DynamicCartProgressHyva`.

## How it integrates

* `view/frontend/layout/hyva_default.xml` adds an Alpine island to the Hyvä Mini Cart
  drawer, in the `cart-drawer.items.before` container (under the drawer
  heading, above the item list).
* `view/frontend/layout/hyva_checkout_cart_index.xml` adds an Alpine island to the
  Cart Page in the `checkout.cart.form.before` container (above the item list),
  the same slot the Luma component uses. No cart template is overridden.
* Both templates receive the shared `ViewModel\CartProgress` as a block argument,
  server-render the initial payload (HTML-attribute escaped) into `x-data`, then
  keep it current from the `mageenv-dcp-progress` section on
  `private-content-loaded`.
* Component styles are scoped (`.mageenv-dcp-*`) and theme-neutral; no Tailwind
  utility classes and no business calculation run on the client.

## What shoppers see

* **Cart Page:** one sentence ("Add $17.00 more to unlock 20% OFF"), one bar in
  the campaign's progress style, and the reward tiers underneath. An earned
  reward code is shown with a Copy button.
* **Mini Cart:** the Reward Card at a fixed height: the next reward, the
  distance to it, the secured count (or the earned code) and one progress line.
* The look matches the Luma component in the core module: both stylesheets are
  built from the same rules.

## Styling

Every colour, the accent, the Mini Cart display face and the base size are
`--dcp-*` custom properties with zero-specificity defaults, so a theme can
override any of them, for example:

```css
.mageenv-dcp-cart, .mageenv-dcp-minicart { --dcp-accent: #b45309; }
```

The accent follows the theme's `--color-primary` when it is set.

The Hyvä Mini Cart is the theme's `cart-drawer` block, which renders its contents
client-side and exposes named containers for extensions. The progress block uses
`cart-drawer.items.before`, which is always present in the DOM, so it initialises
with the page. Verified in the Hyvä default theme on Magento 2.4.9 (Mini Cart and
Cart Page). On a customised Hyvä theme without this container, add
`<?= $block->getChildHtml('mageenv.dcp.minicart.progress') ?>` to the theme's
cart drawer template above the item list.

## License

Open Software License 3.0 (OSL-3.0). See `LICENSE.txt`.

This add-on is free. It needs the paid core module
`mageenv/module-dynamic-cart-progress`, available on the
[Adobe Commerce Marketplace](https://commercemarketplace.adobe.com/mageenv-module-dynamic-cart-progress.html).
