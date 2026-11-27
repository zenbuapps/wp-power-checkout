---
globs:
  - "js/src/**/*.ts"
  - "js/src/**/*.vue"
---

# Vue 3 Frontend Rules

## Architecture: 4 App Instances from 1 Bundle

Entry point `js/src/index.ts` creates four independent instances:

1. **Settings SPA** -- mounted on `#power-checkout-wc-setting-app` (injected into WC `#mainform`)
   - Full router + Element Plus + TanStack Vue Query
   - Only mounts when the WC settings tab page is active

2. **RefundDialog** -- `MountRefundDialog()` from `external/RefundDialog/`
   - Mounts on order detail pages
   - Standalone mini-app for gateway refund actions

3. **InvoiceApp** -- `MountInvoiceApp()` from `external/InvoiceApp/`
   - Mounts on order detail pages (admin MetaBox) AND checkout page (frontend invoice form)
   - Renders on multiple DOM elements identified by `render_ids` from PHP

4. **EcpgPayment** -- `MountEcpgPayment()` from `external/EcpgPayment/`
   - Mounts on order-received page for ECPay ECPG embedded payment
   - Loads ECPay JS SDK, renders card form (`#ECPayPayment`), sends PayToken to `ecpg/create-payment`, handles 3DS redirect
   - Not a Vue app — plain TypeScript module called from `index.ts`

5. **PayuniUniEmbed** -- `MountPayuniUniEmbed()` from `external/PayuniUniEmbed/`
   - Mounts on order-received page for PAYUNi UNi Embed V3 embedded payment
   - Fetches SDK token, renders PAYUNi iframe, sends buyer credential to `uni-embed/create-payment`, handles 3DS redirect
   - Not a Vue app — plain TypeScript module called from `index.ts`

6. **ShoplineEmbedded** -- `MountShoplineEmbedded()` from `external/ShoplineEmbedded/`
   - Mounts on order-received page for SLP embedded payment (`shopline_payment_embedded`); guards itself on `SLP_EMBEDDED_DATA` (`window.power_checkout_slp_embedded_data`, read via `utils/env.ts`) so it is a no-op elsewhere
   - Loads the official SDK (`window.ShoplinePayments`), POSTs `/pay/{order_id}/dispatch` (init → SDK `createPayment()` → confirm → `payment.pay(nextAction)`); `awaiting_confirmation=yes` means a trade is already in flight → do not render the cashier (double-charge guard)
   - Plain TypeScript module (types in `external/ShoplineEmbedded/types.ts`)

## Composition API Only

```vue
<script setup lang="ts">
// CORRECT: Composition API with script setup
import { ref, computed } from 'vue'
</script>

<!-- NEVER use Options API -->
```

## Import Paths

Always use `@/` alias (resolves to `js/src/`). Never use relative paths:

```typescript
// CORRECT
import { API_URL } from '@/utils/env'
import apiClient from '@/api/index'

// WRONG
import { API_URL } from '../../utils/env'
```

## Environment Access

Access PHP-bridged data through `utils/env.ts` only. Never read `window` directly:

```typescript
// CORRECT
import { NONCE, API_URL, SITE_URL } from '@/utils/env'

// WRONG
const nonce = window.power_checkout_data.env.NONCE
```

Available env values: `SITE_URL`, `API_URL`, `CURRENT_USER_ID`, `CURRENT_POST_ID`, `PERMALINK`, `APP_NAME`, `KEBAB`, `SNAKE`, `NONCE`, `APP1_SELECTOR`

## API Client

Use the centralized `apiClient` from `@/api/index.ts`:

- Base URL: `{API_URL}/power-checkout/v1/`
- Auto-attaches `X-WP-Nonce` header
- Response interceptor auto-shows `ElNotification` for non-GET success responses
- Error interceptor handles 403 (session expired) with reload prompt
- **Do NOT manually trigger `ElNotification`** for API responses -- the interceptor handles it

## TanStack Vue Query Defaults

Configured in `index.ts` QueryClient:

```typescript
queries: {
    staleTime: 15 * 60 * 1000,    // 15 min
    gcTime: 15 * 60 * 1000,       // 15 min cache
    retry: 0,                      // No retry
    refetchOnWindowFocus: false,   // No auto-refetch
}
mutations: { retry: 0 }
```

## Element Plus Only

- UI library: Element Plus (`element-plus`) + `@element-plus/icons-vue`
- Do NOT use Ant Design, Vuetify, or any other UI library in Vue code
- Import Element Plus components as needed (auto-import via `app.use(ElementPlus)`)

## Router

Memory mode router (`createMemoryHistory`):

```
/payments                               → Payments list
/payments/shopline_payment_redirect     → SLP settings
/payments/ecpay_aio                     → ECPay AIO settings
/payments/ecpay_ecpg                    → ECPay ECPG settings
/payments/payuni_upp                    → PAYUNi UPP settings (js/src/pages/Payments/PayuniUpp/index.vue)
/payments/payuni_uni_embed              → PAYUNi UNi Embed settings (js/src/pages/Payments/PayuniUniEmbed/index.vue)
/payments/paynow                        → PayNow settings (js/src/pages/Payments/Paynow/index.vue)
/payments/shopline_payment_embedded     → SLP Embedded settings (js/src/pages/Payments/ShoplineEmbedded/index.vue)
/payments/tcat_pay                      → 黑貓PAY settings (js/src/pages/Payments/TcatPay/index.vue)
/payments/linepay                       → LINE Pay direct settings (js/src/pages/Payments/Linepay/index.vue)
/logistics                              → Logistics list (js/src/pages/Logistics/index.vue)
/logistics/ecpay_logistics              → ECPay Logistics settings (js/src/pages/Logistics/Ecpay/index.vue)
/logistics/paynow_logistics             → PayNow Logistics settings (js/src/pages/Logistics/Paynow/index.vue)
/logistics/tcat_logistics               → 黑貓宅急便 settings (js/src/pages/Logistics/Tcat/index.vue; 測試連線 button)
/logistics/hct_logistics                → 新竹物流 settings (js/src/pages/Logistics/Hct/index.vue; waybill range + remaining count, 測試連線)
/logistics/sf_express                   → 順豐 settings (js/src/pages/Logistics/SfExpress/index.vue; 測試連線, push URL, TW→TW warning)
/logistics/kerrytj_logistics            → 嘉里大榮 settings (js/src/pages/Logistics/Kerrytj/index.vue; ambient/cold ranges, 測試連線)
/invoices                               → Invoice list
/invoices/amego                         → Amego settings
/invoices/ecpay                         → ECPay Invoice settings
/invoices/ezpay                         → ezPay Invoice settings (js/src/pages/Invoices/Ezpay/index.vue)
/invoices/paynow_invoice                → PayNow Invoice settings (js/src/pages/Invoices/Paynow/index.vue)
/checkout                               → 結帳強化設定 (js/src/pages/Checkout/index.vue)
```

`ROUTER_MAPPER` in `router/index.ts` maps provider IDs to routes.

## Error Messages by Domain

`utils/error-code.ts` maps the normalized `error_code` to friendly text. The default table is payment/invoice wording (e.g. AUTH →「金流 / 發票憑證錯誤」); requests whose path is under `logistics/…` use the **logistics** table (`ERROR_DOMAIN.LOGISTICS`: AUTH →「物流憑證錯誤，請檢查帳號密碼」, NETWORK →「連線逾時」; codes not in it fall back to the backend `message`, which is already specific). Add new domain wording by extending `DOMAIN_ERROR_CODE_MESSAGE`, not by hardcoding text in pages. Provider-side failures reach the UI as HTTP **424** with `error_code` / `raw_code` / `message` (never 5xx, so CDN pages cannot swallow the message). The pure logic is covered by `pnpm test:js` (`tests/js/error-code.test.ts`).

## Provider Capability Gating

`GET /settings` returns `data.provider_meta.{payments,logistics}` (capability meta per provider) intended for hiding unsupported buttons (refund / capture / void / print / return) instead of letting the backend answer 501. **Not consumed by any Vue page yet** (no `provider_meta` reference in `js/src`); new pages that expose such buttons should read it from the settings query.

## Type Declarations

- Global types: `js/src/types/global.d.ts` (declares `window.power_checkout_data` etc.)
- Shared types: `js/src/types/index.ts`
- Page-specific types: `js/src/pages/{Domain}/{Provider}/Shared/types.ts`
- Page-specific enums: `js/src/pages/{Domain}/{Provider}/Shared/enums.ts`

## Build

- Vite dev server: port 5182
- Output: `js/dist/index-{hash}.js` + `js/dist/index-{hash}.css` + chunks `js/dist/assets/*-{hash}.js` + `js/dist/manifest.json`
- Single entry point: `js/src/index.ts`
- File names always carry a content hash (hosts may ignore `?ver=` and cache static files for a year); PHP resolves them through `Shared\Utils\ViteAsset` (reads `manifest.json`) — never hardcode `js/dist/index.js`
- `base: './'` for build (chunk preloads relative to the entry, not the site root)
- Settings card gear: route resolution in `utils/settings-route.ts` (pure logic, `pnpm test:js`); missing route →「外掛已更新，請重新整理頁面（Ctrl+Shift+R）」
