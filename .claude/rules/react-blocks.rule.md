---
globs:
  - "inc/assets/blocks/**/*.ts"
  - "inc/assets/blocks/**/*.tsx"
---

# React WC Blocks Payment Integration Rules

## Purpose

React code in this project is ONLY for WooCommerce Block Checkout payment method registration. The main app uses Vue 3 -- React is used solely because WC Blocks requires it.

## Architecture

Each `.tsx` file in `inc/assets/blocks/` is a separate Vite entry point that registers one payment method:

```
inc/assets/blocks/
  shopline_payment_redirect.tsx    # Active -- SLP redirect gateway
  ecpay_aio.tsx                    # Active -- ECPay AIO redirect gateway
  ecpay_ecpg.tsx                   # Active -- ECPay ECPG embedded gateway
  payuni_upp.tsx                   # Active -- PAYUNi UPP V2 redirect gateway
  payuni_uni_embed.tsx             # Active -- PAYUNi UNi Embed V3 embedded gateway
  paynow.tsx                       # Active -- PayNow Component SDK v2 embedded gateway
  newebpay_mpg.tsx                 # Active -- NewebPay MPG redirect gateway
  ecpay_logistics.tsx / pc_invoice.tsx # NOT payment methods -- ECPay logistics store picker / invoice form block integration (see ES-module note below)
  shopline_payment_embedded.tsx    # Active -- SLP embedded gateway (description only; the SDK mounts later on order-received via the Vue bundle `MountShoplineEmbedded()`, not in the block)
  tcat_pay.tsx                     # Active -- 黑貓PAY; sub-method select (credit / cvs_ibon / atm) via hooks from `@wordpress/element`; disables methods above the per-method limit (UX only, backend re-validates); value returned in onPaymentSetup paymentMethodData `pc_tcat_pay_method` (data from `tcat_pay_data.methods`)
  linepay.tsx                      # Active -- LINE Pay direct (api-redirect; description only, plain registerPaymentMethod pattern)
  pc_logistics.tsx                 # Active -- NOT a payment method: shared logistics store picker for providers implementing ISupportsCheckoutStoreSelection (payuni_logistics); ExperimentalOrderShippingPackages slot fill; settings from window.pcLogistics (wp_localize_script), selected store from cart.extensions['pc_logistics']
  pc_free_shipping_hint.tsx        # Active -- NOT a payment method: free-shipping hint (registerPlugin + ExperimentalOrderMeta slot fill; data from Store API extension power-checkout.free_shipping_hint)
  types/types.d.ts                 # Shared type declarations
```

Build output: `inc/assets/dist/blocks/{name}.js`

## WP/WC External Globals

All WordPress and WooCommerce packages are resolved from window globals via `vite-plugin-optimizer`:

```typescript
// These import from window.wc / window.wp at runtime, not bundled
import { registerPaymentMethod } from '@woocommerce/blocks-registry'  // window.wc.wcBlocksRegistry
import { getSetting } from '@woocommerce/settings'                    // window.wc.wcSettings
import { createElement } from '@wordpress/element'                    // window.wp.element
import { decodeEntities } from '@wordpress/html-entities'             // window.wp.htmlEntities
import { __ } from '@wordpress/i18n'                                 // window.wp.i18n
```

Do NOT add these as npm dependencies -- they are shimmed.

Components that use hooks must create elements with `createElement` / `useState` / `useEffect` from `@wordpress/element` (the same React instance as the WC Blocks renderer; see `ecpay_aio.tsx`, `tcat_pay.tsx`), not a separately bundled React.

## Enqueuing Block Scripts (ES Module)

Vite emits ESM output (contains `import`). When PHP enqueues a block script with `wp_enqueue_script()`, it **must** also call `Plugin::instance()->add_module_handle($handle)` so it loads as `type="module"`; otherwise the browser reports `Cannot use import statement outside a module`. (Payment methods get this via `BlocksIntegration`; standalone scripts call it in their own `enqueue_block_assets()`: `pc_free_shipping_hint` (`FreeShippingHintService`), `pc_invoice` (`BlocksInvoiceIntegration`), `ecpay_logistics` (`BlocksLogisticsIntegration`), `pc_logistics` (`CheckoutLogisticsIntegration`).)

## Payment Method Registration Pattern

```tsx
const id = 'gateway_id'
const settings = getSetting(`${id}_data`, {})

// Data comes from BlocksIntegration::get_payment_method_data() on PHP side
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const Content = () => decodeEntities(settings.description || '')
const Label = (props: any) => {
    const { PaymentMethodLabel } = props.components
    return <PaymentMethodLabel text={label} />
}

registerPaymentMethod({
    name,
    label: <Label />,
    ariaLabel: label,
    placeOrderButtonLabel: order_button_text,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    paymentMethodId: id,
    supports: { features, showSavedCards: true, showSaveOption: false },
})
```

## PHP Side Integration

The `BlocksIntegration` class (extends WC `AbstractPaymentMethodType`) handles:

- Script registration with WP dependencies: react, wc-settings, wp-block-editor, wp-blocks, wp-components, wp-element, wp-i18n, wp-primitives
- `get_payment_method_data()` returns: name, title, description, supports, order_button_text, icons
- Registered via `woocommerce_blocks_payment_method_type_registration` action

## Build Config

- Vite config: `vite.config.block.ts`
- Dev server port: 5181
- Entry discovery: `glob('inc/assets/blocks/*.tsx')`
- Terser for minification with `$` reserved
- `lodash-es` aliased to `lodash`
- Dev watch: `pnpm dev:blocks`
- Production build: `pnpm build:blocks`
