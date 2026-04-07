---
name: power-checkout
description: |
  Deep knowledge of the Power Checkout WooCommerce plugin -- payment gateway integration (Shopline Payment), e-invoice (Amego), and checkout field customization. Use this skill whenever working on Power Checkout plugin code, understanding its DDD architecture, adding new payment/invoice providers, modifying REST APIs, or debugging the Vue 3 / React WC Blocks dual frontend system.
---

# Power Checkout Plugin Knowledge

## Quick Reference

| Item | Value |
|---|---|
| Plugin entry | `plugin.php` (Singleton `Plugin` class) |
| Bootstrap | `Bootstrap::__construct()` wires all domains |
| PHP namespace | `J7\PowerCheckout\` → `inc/classes/` |
| Vue entry | `js/src/index.ts` → 3 Vue apps |
| Blocks entry | `inc/assets/blocks/*.tsx` (glob auto-discovery) |
| REST base | `power-checkout/v1` |
| WC option pattern | `woocommerce_{provider_id}_settings` |
| Text domain | `power_checkout` |
| Min WC version | 8.3.0 |
| Min PHP version | 8.1 |
| Powerhouse compat | 3.3.38+ (optional) |

## Domain Map

### Payment Domain

**Active provider:** `shopline_payment_redirect` (Shopline Payment redirect-based gateway)

Key classes:
- `Payment\ProviderRegister` -- registers gateways, handles refund script enqueue
- `ShoplinePayment\Services\RedirectGateway` -- the WC_Payment_Gateway implementation
  - `before_process_payment()` calls `ApiClient::create_session()` and returns redirect URL
  - `process_refund()` checks payment method's refund capability
  - `handle_payment_gateway_refund()` does actual API refund call with DB transaction
  - `init()` instantiates WebHook and registers WC Blocks
- `ShoplinePayment\Http\ApiClient` -- factory for SLP API calls (create_session, get_session, get_payment, create_refund)
- `ShoplinePayment\Http\WebHook` -- REST endpoint at `power-checkout/slp/webhook`
  - HMAC-SHA256 signature verification (skipped in local env)
  - Handles payment completion and refund webhook events
  - Always returns 200 to prevent SLP retries
- `ShoplinePayment\Managers\StatusManager` -- maps SLP status to WC order status
  - SUCCEEDED -> processing, EXPIRED -> cancelled, default -> pending
- `ShoplinePayment\Managers\EventTypeManager` -- maps webhook event types

**Commented out:** `EcpayAIO` (ECPay AIO payment gateway -- code exists but not registered)

Refund capability by payment method:
- Credit card: partial + full
- Apple Pay: full only
- ZingalaCard (zero-card installment): full only
- ATM virtual account: NO refund support

### Invoice Domain

**Active provider:** `amego` (Amego/Guangmao e-invoice)

Key classes:
- `Invoice\ProviderRegister` -- registers providers, auto-issue/cancel hooks based on order status settings
- `Amego\Services\AmegoProvider` -- implements `IInvoiceService` (issue, cancel, get_invoice_number)
- `Amego\Http\ApiClient` -- Amego API communication
- `Invoice\Shared\Services\InvoiceApiService` -- REST endpoints for issue/cancel
- `Invoice\Shared\Services\InvoiceMetaBoxService` -- admin MetaBox rendering

Auto invoice hooks: configurable per provider via `auto_issue_order_statuses` and `auto_cancel_order_statuses` settings arrays. Each status in the array gets a `woocommerce_order_status_{status}` hook registered.

### Settings Domain

- `SettingApiService` -- CRUD REST API for all provider settings
- `SettingTabService` -- WC settings tab, Vue app script enqueue
  - Enqueues on: WC settings tab, order detail page, order list page, and frontend (for invoice form)
  - `$handle`: `power-checkout-wc-setting-tab`
- `DefaultSetting` -- Taiwan address format corrections for WC checkout

## Adding a New Payment Provider

1. Create domain folder: `Domains/Payment/NewProvider/`
2. Create gateway class extending `ShoplinePayment\Shared\Abstracts\PaymentGateway` (or `Shared\Abstracts\AbstractPaymentGateway` directly)
3. Define `const ID` and implement `get_settings()`, `before_process_payment()`
4. Create `DTOs/NewProviderSettingsDTO.php` extending `BaseSettingsDTO`
5. Register in `Payment\ProviderRegister::$gateway_services`
6. Create block checkout entry: `inc/assets/blocks/new_provider_id.tsx`
7. Add Vue settings page: `js/src/pages/Payments/NewProvider/index.vue`
8. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`

## Adding a New Invoice Provider

1. Create domain folder: `Domains/Invoice/NewProvider/`
2. Create provider class extending `BaseService` and implementing `IInvoiceService`
3. Define `const ID` and implement `issue()`, `cancel()`, `get_invoice_number()`, `get_settings()`
4. Create settings DTO extending `BaseSettingsDTO`
5. Register in `Invoice\ProviderRegister::$invoice_providers`
6. Add Vue settings page: `js/src/pages/Invoices/NewProvider/index.vue`
7. Add route in router

## PHP -> JS Data Flow

Three `wp_localize_script` calls create window globals:

1. `window.power_checkout_data.env` (SettingTabService) -- always loaded on relevant pages
   - SITE_URL, API_URL, NONCE, CURRENT_USER_ID, CURRENT_POST_ID, APP_NAME, KEBAB, SNAKE, APP1_SELECTOR, IS_LOCAL, ORDER_STATUSES

2. `window.power_checkout_order_data` (Payment\ProviderRegister) -- order detail only
   - gateway: { id, method_title }
   - order: { id, total, remaining_refund_amount }

3. `window.power_checkout_invoice_metabox_app_data` (Invoice\ProviderRegister) -- order detail + checkout
   - render_ids, is_admin, is_issued, invoice_number, invoice_providers, order

## Order Meta Keys

All prefixed with `pc_`:
- `pc_payment_identity` -- tradeOrderId (idempotency guard against duplicate webhook processing)
- `pc_payment_detail` -- payment response data (displayed in admin)
- `pc_refund_detail` -- refund response data
- `pc_issued_data` -- invoice issuance response
- `pc_cancelled_data` -- invoice cancellation response
- `pc_provider_id` -- which invoice provider handled this order
- `pc_issue_params` -- invoice info submitted at checkout
- `_pc_tax_type` -- per-item tax type for invoicing (ETaxType enum)

## Webhook Security

SLP webhook verification at `power-checkout/slp/webhook`:

```
payload = "{timestamp}.{body}"
signature = hash_hmac('sha256', payload, signKey)
```

- `timestamp` header compared against server time (5 min tolerance)
- `apiVersion` header checked (expected: V1)
- Signature from `sign` header compared via `hash_equals()`
- Local environment (`Plugin::$env === 'local'`) skips verification

## Checkout Fields System

`CheckoutFields` utility handles field registration for both classic and block checkout:

1. `CheckoutFieldDTO` registered via `CheckoutFields::register_field()`
2. Fields rendered via `woocommerce_checkout_fields` filter (classic) or `woocommerce_register_additional_checkout_field()` (blocks -- TODO)
3. Field values saved to order meta via `woocommerce_checkout_update_order_meta`

Currently registered field: invoice params (`pc_issue_params` key)

## Known TODOs in Codebase

- Block checkout custom fields (`render_invoice_field_block` has stub)
- Logistics domain (frontend route exists, no backend)
- Global settings page (frontend route exists, placeholder component)
- ECPay AIO integration (code exists but commented out in registration)
