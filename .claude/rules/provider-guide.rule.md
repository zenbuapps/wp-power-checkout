# Adding New Providers Guide

## Adding a New Payment Provider

Payment 領域有顯式統一介面 `IPaymentProvider`（`Domains/Payment/Shared/Interfaces/IPaymentProvider.php`），extends `IGateway`，與 Logistics `ILogisticsProvider` / Invoice `IInvoiceService` 的設計哲學一致。

`AbstractPaymentGateway implements IPaymentProvider`，提供 `query_trade` / `capture` / `void_auth` / `get_supported_payment_methods` / `get_provider_meta` / `verify_callback` 的安全預設；不支援該能力的金流無需覆寫，支援者自行覆寫。

1. Create domain folder: `Domains/Payment/NewProvider/`
2. Create gateway class extending `Shared\Abstracts\AbstractPaymentGateway` (already implements `IPaymentProvider`)
3. Define `const ID` and implement `get_settings()`, `before_process_payment()`
4. Override capability methods as needed: `process_refund()`, `query_trade()`, `capture()`, `void_auth()`, `get_supported_payment_methods()`
5. **Override `get_provider_meta()`** to declare the real `PaymentMode` (`form-redirect` / `api-redirect` / `client-sdk`) and capabilities (`PaymentCapabilitiesDTO`: `refund` / `partial_refund` / `capture` / `void` / `query` / `recurring`, all six required). Capabilities are **method-level**: declare `true` only when the gateway class actually overrides the matching method (`process_refund` → refund / partial_refund, `capture`, `void_auth` → void, `query_trade`); an ability reachable only through an admin order action does not count. The base default (form-redirect, flags derived by reflection) is a fallback, not a substitute. `OrderCreationStrategy` is `PRE_CREATE_PENDING` for every gateway (WC creates the pending order first)
6. Callbacks (webhook / notify):
   - New gateways should use the unified frame: override `verify_callback()` (default is **fail-closed**), `resolve_callback_order()`, `get_callback_idempotency_key()`, `process_callback()` and optionally `callback_ack_response()`; the REST route simply calls `handle_webhook()` (final; see `WebhookPipeline`). The frame always acks and never throws; handler exceptions leave the idempotency key unwritten so the provider can resend
   - If the callback authenticity is weak (checksum without a secret, GET return with no body), return `''` from `get_callback_idempotency_key()` and keep idempotency in your own `StatusManager`, written only when something was actually processed; and treat the callback as a trigger, confirming via a platform query (see 黑貓PAY / LINE Pay)
   - Server-to-server endpoints must be registered unconditionally in `Payment\ProviderRegister::register_hooks()` (not only when the gateway is enabled)
7. Embedded (站內付) providers: implement `IEmbeddedPaymentProvider::dispatch()` — the shared REST `POST /pay/{order_id}/dispatch` already verifies order / `order_key` / gateway / `needs_payment()`. Throw `DispatchException` for user-safe business errors (400 / 409); any other `\Throwable` becomes a generic 500. Do not add a per-provider create-payment endpoint
8. Per-attempt identifiers: if the platform treats merchant order numbers as permanently unique, generate a new number every attempt and write it (history meta) **before** calling the API; ownership checks must require the history entry (never trust a number from the URL / webhook alone)
9. Refund failure path must call `AbstractPaymentGateway::fail_gateway_refund()`; `process_refund()` never throws, returns normalized `\WP_Error` (`NormalizedError::from(ErrorCode::…)`)
10. Create `DTOs/NewProviderSettingsDTO.php` (extend `BaseSettingsDTO` or `DTO` depending on mode handling needs)
11. Register in `Payment\ProviderRegister::$gateway_services`
12. Create block checkout entry: `inc/assets/blocks/new_provider_id.tsx` (and a `BlocksIntegration` registered via `register_checkout_blocks()`, guarded by `GatewayUtils` so it is a no-op when disabled)
13. Add Vue settings page: `js/src/pages/Payments/NewProvider/index.vue`
14. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`
15. Tests: group-annotate (`smoke` / `happy` / `error` / `edge` / `security`), cover provider meta + capabilities, callback forgery, idempotency, amount guard, refund failure rollback

## Adding a New Invoice Provider

1. Create domain folder: `Domains/Invoice/NewProvider/`
2. Create provider class extending `BaseService` and implementing `IInvoiceService`
3. Define `const ID` and implement `issue()`, `cancel()`, `get_invoice_number()`, `get_settings()`
4. Create settings DTO extending `BaseSettingsDTO`
5. Register in `Invoice\ProviderRegister::$invoice_providers`
6. Add Vue settings page: `js/src/pages/Invoices/NewProvider/index.vue`
7. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`

## Adding a New Logistics Provider

`ILogisticsProvider` is the unified abstraction (mirrors `IInvoiceService`). The 4 direct providers (`tcat_logistics` / `hct_logistics` / `sf_express` / `kerrytj_logistics`) are the reference implementations.

1. Create domain folder: `Domains/Logistics/NewProvider/`
2. Create provider class (`BaseService` + `SingletonTrait`) implementing `ILogisticsProvider` (11 methods):
   - `get_settings(static)` — return settings array
   - `get_store_selection()` — phase A: build store picker redirect (RWD HTML); providers without a CVS map throw `UNSUPPORTED`
   - `parse_store_selection()` — decode ClientReplyURL POST, write store meta to order
   - `create_shipment()` — phase B: create shipment, return `logistics_id`; must be **idempotent** (existing ref → return it, never create twice)
   - `query_shipment()`, `print_document()`, `cancel_shipment()` — query / print / cancel (`print_document` may return PDF bytes starting with `%PDF`; `LogisticsApiService` serves them as `application/pdf`)
   - `create_return()` — reverse logistics; throw when unsupported
   - `handle_status_callback()` — parse status push; ECPay must return AES-JSON 3-layer response; providers without push return HTTP 200 no-op; signed pushes (SF Express) verify the signature on the raw body first and reject the whole batch when no key is configured
   - `get_supported_methods()` — return enabled sub-type list
   - **`get_provider_meta(): LogisticsProviderMetaDTO`** — `supported_delivery_methods` (`DeliveryMethod::HOME` / `CVS`) + `ShipmentCapabilitiesDTO` (`can_create_shipment` / `can_query_shipment` / `has_cvs_map` / `can_print` / `can_return` / `can_cancel`, all six required). Declare `false` for every operation the carrier/API does not offer
3. **Capability check (HTTP 501)**: `LogisticsApiService` reads the meta before calling the provider; a `false` capability returns **501 + `error_code UNSUPPORTED`** without calling the provider. **Exception list** (`CAPABILITY_CHECK_EXEMPT_IDS`, legacy behaviour preserved — their unsupported operations throw inside the provider and map to the old codes): `ecpay_logistics`, `payuni_logistics`, `paynow_logistics`. New providers are never added to this list
4. Optional: implement `ISupportsConnectionTest::test_connection()` for the settings-page「測試連線」(`POST /logistics/{provider}/ping`, works before the provider is enabled). It must call a **read-only** API, never write data, return a credential-free summary, and throw on failure. If the probe cannot validate credentials (e.g. address parsing), say so in the response (e.g. `credentials_verified:false`) so the UI does not imply they are correct
5. Failures: always `throw` an exception carrying a normalized `ErrorCode` (`error_code` property) and optionally `raw_code`; the REST layer maps via `ErrorCode::to_http_status()`, with provider-side 502 rewritten to **424 Failed Dependency** (CDNs replace origin 5xx bodies). Never leak credentials in messages
6. Status mapping: return `unified_state` (`ShippingState`, 11 values) from `query_shipment()` next to the raw status; keep raw status in your own meta. No background polling
7. Meta keys: own prefix `_pc_{provider_id}_*` via a `{Provider}LogisticsMetaKeys` helper (HPOS-aware `$order->update_meta_data()`); status push lookups key on the carrier waybill, idempotency via a processed-events meta (capped)
8. Pre-issued waybill number ranges (carrier hands out ranges): use `WaybillNumberAllocator` with an `IWaybillNumberFormat` (`Mod7CheckDigitFormat` / `PlainSerialFormat`); never hand-roll read-then-write counters. Store a pending number on the order **before** the API call so retries reuse it; sync ranges on settings save via `update_option_woocommerce_{id}_settings` registered in `Logistics\ProviderRegister::register_hooks()` (without requiring enable)
9. Status callbacks: all paths (including exceptions) must return the carrier's expected ack — never throw HTTP 500
10. Create settings DTO extending `BaseSettingsDTO`
11. Create WC_Shipping_Method subclass if needed (classic checkout; `METHOD_ID` = provider id; `save_checkout_meta()` only writes when the method is chosen) and add it to `add_shipping_method()` / `save_checkout_meta()` in `Logistics\ProviderRegister`
12. Register in `Logistics\ProviderRegister::$logistics_providers` and add the id to `LogisticsApiService::PROVIDER_IDS` (after existing ids; or extend via filter `power_checkout_logistics_provider_ids`)
13. Add Vue settings page: `js/src/pages/Logistics/NewProvider/index.vue` (ping button when `ISupportsConnectionTest`)
14. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`
