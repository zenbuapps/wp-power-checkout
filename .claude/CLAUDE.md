# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**Power Checkout** is a WooCommerce checkout integration plugin providing payment gateway (Shopline Payment), logistics (ECPay AllInOne), e-invoice (Amego), and checkout field customization. Built with Domain-Driven Design: PHP backend + Vue 3 frontend.

**Integrated Services:**
- **Shopline Payment (SLP)** — Redirect-based payment (credit card, ATM, Apple Pay, LINE Pay, JKOPay, ZingalaCard)
- **SLP Embedded** (`shopline_payment_embedded`) — Embedded payment (JS SDK + `trade/payment/create`, credit card + installment only); credentials shared with SLP redirect (`RedirectSettingsDTO`); two-phase via `/pay/{order_id}/dispatch` (init → confirm); each attempt uses a unique `referenceOrderId`; results via shared SLP webhook or order-received active query; [GAP: SLP sandbox PCI API returns `1005 No interface access permission` — end-to-end payment/refund unverified until SHOPLINE enables SDK permission on the sandbox merchant]
- **ECPay AIO** (`ecpay_aio`) — Redirect-based payment via ECPay Cashier V5; CheckMacValue SHA256; supports credit card (one-time/installment/period), ATM, WebATM, CVS, BARCODE, ApplePay
- **ECPay ECPG** (`ecpay_ecpg`) — Embedded payment (站內付 2.0); frontend JS SDK, AES-128-CBC, 3DS ThreeDURL; credit card only
- **ECPay Logistics** (`ecpay_logistics`) — ECPay AllInOne Logistics v2; convenience store (FAMI/UNIMART/HILIFE) + home delivery (HOME, with temperature); B2C (2000132) + C2C (2000933) account types; COD (IsCollection) + online payment; two-phase store selection (TempTrade → CreateByTempTrade); AES-JSON callback
- **Amego** — Taiwan e-invoice issuance/void
- **ECPay Invoice** (`ecpay`) — Taiwan e-invoice B2C/B2B via ECPay; AES-128-CBC; parallel with Amego, switchable from admin
- **ezPay Invoice** (`ezpay`) — Taiwan e-invoice B2C/B2B via NewebPay ezPay; AES-256-CBC (PKCS#7 blocksize=32 + ZERO_PADDING + hex lowercase); CheckCode SHA256; issue/void/allowance (open+void)/query; parallel with Amego & ECPay Invoice, switchable from admin
- **PAYUNi UPP** (`payuni_upp`) — Redirect-based payment (UNiPaypage V2.0); AES-256-GCM + `hex(base64(cipher):::base64(tag))` + SHA256 HashInfo; supports credit card (one-time/installment), ATM, CVS, icash Pay, LINE Pay, JKoPay, Apple Pay, Google Pay; single NotifyURL endpoint; credit card API refund/capture/void_auth; query trade
- **PAYUNi UNi Embed** (`payuni_uni_embed`) — Embedded payment (UNi Embed V3, iframe 站內付); AES-256-GCM (reuses PayuniCrypto); two-phase: token_get (`/api/iframe/token_get`) then merchant_trade (`/api/iframe/merchant_trade`); 3DS support; credit card only; single NotifyURL endpoint; credit card API refund/capture/void_auth; buyer token (credit_hash/credit_life) stored but never card number/CVC; parallel with payuni_upp
- **PayNow** (`paynow`) — Embedded payment (立吉富體系 1, Component SDK v2 iframe 站內付); REST PaymentIntent + HMAC-SHA256 Webhook; supports credit card (one-time/installment), ATM, convenience store (ibon/FamiPort), LINE Pay, Apple Pay (excludes ApplePayDeferred); single NotifyURL endpoint; credit card/ATM API refund; query payment intent + refund query admin actions; capture/void_auth no-op (no corresponding endpoint); offline payment two-phase (pending → processing on payment); [GAP: sandbox PublicKey/PrivateKey pending application]
- **Tcat Pay 黑貓PAY** (`tcat_pay`) — Redirect-style payment (統一客樂得 Kolet); sub-methods chosen at checkout (`pc_tcat_pay_method`): credit (COCS short URL redirect) / ibon CVS code / ATM virtual account (CVS-type = get-code, order stays pending); APN callback is trigger-only (checksum has no secret key) → platform query is the source of truth; credit refund via CocsOrderRefund (partial) / CocsOrderCancel (full, uncaptured); CVS/ATM no refund API; [GAP: no test credentials — only the failure path (token error note) was live-verified; capture/void/refund/APN unverified live]
- **LINE Pay Direct** (`linepay`) — Direct Online API v4 (merchant's own Channel ID/Secret; unrelated to LINE Pay proxied by SLP/NewebPay/PAYUNi); api-redirect, auto-capture; **no webhook** → confirmUrl (browser GET) + Action Scheduler reconcile `pc_linepay_reconcile` + admin query action; refund (incl. partial) only, no capture/void; [GAP: real-person payment segment (customer login + confirm) not recorded — only request + redirect to LINE Pay sandbox page verified live]
- **PayNow Logistics** (`paynow_logistics`) — Convenience store pickup (7-11/FAMI/HiLife) + TCAT home delivery (立吉富體系 1); TripleDES DES-EDE3 ECB encryption (JsonOrder base64(3DES(JSON)), apicode ECB+space→plus); PassCode SHA1; status push callback is unsigned → treated as trigger only, status re-fetched via Get_Order_Info (10s throttle); query/print/cancel; amount limits CVS ≤20000 / home ≤100000; idempotent ReNewOrder when existing valid shipment; create_return throws 尚未實作; [GAP: sandbox user_account/apicode pending; prod key/IV to confirm with PayNow]
- **Tcat Logistics 黑貓宅急便直串** (`tcat_logistics`) — Home delivery only (統一速達 標準印單 API): PrintOBT create / DownloadOBT PDF / OBTStatus query (2h transient cache, E087) / ReserveAdd return; no cancel, no CVS map, no status push; [GAP: no test customer token — create/print live-unverified; test-connection uses ParsingAddress which does NOT validate credentials (`credentials_verified:false`)]
- **HCT Logistics 新竹物流** (`hct_logistics`) — Home delivery only (EDI WebServices V15, hand-built SOAP 1.1); `WaybillNumberAllocator` mod-7 number range or HCT-assigned numbers; label image stored at create (print does not re-call API); create/cancel/return/query (searchGoods DES-CBC, 10min cache); [GAP: no HCT test account/range — official doc test credentials are actually prod; only read-only QueryEDELNO live-verified, create/cancel/return unverified]
- **SF Express 順豐** (`sf_express`) — Home delivery only (IUOP; AES + sign + token cache); create/print (server-side PDF fetch)/query track/cancel (before pickup); signed track-push endpoint; no return/CVS; fully live-verified on the public SIT sandbox (connect → create → PDF → query → signed push); [GAP: TW→TW production eligibility/product must be confirmed with SF Taiwan account manager; push needs SF-side enablement + IP allowlist]
- **Kerry TJ 嘉里大榮** (`kerrytj_logistics`) — Home delivery only (EDIEntrance, Basic Auth); ambient/cold waybill ranges via `WaybillNumberAllocator` (pending-BLN reuse); create/print label/query/pickup-return + Kerry-specific admin REST (update shipment, detail PDF); no cancel API, no COD; [GAP: no test account/ranges — create/print/pickup live-unverified; only settings validation + AUTH-failure ping verified]
- **PayNow Invoice** (`paynow_invoice`) — Taiwan e-invoice B2C/B2B via PayNow (立吉富體系 3); Bearer JWT-Token auth (no symmetric encryption); issue/cancel/allowance (open+void)/query; IInvoiceService + ISupportsAllowance + ISupportsQuery; B2C tax_amount=0 / B2B actual tax; carrier/donation mutual exclusion; zero-tax requires reason; allowance_data uses `allowance_number` key (not ezPay's `allowance_no`); parallel with Amego/ECPay/ezPay, switchable from admin; [GAP: sandbox jwt_token pending application; dev env invoiceapi-dev.paynow.com.tw / prod invoiceapi-prod.paynow.com.tw]
- **Checkout Fields** — Classic checkout custom fields (including invoice info fields)
- **Checkout Enhancement** (`checkout_enhancement`) — Virtual provider (no gateway/provider class); 6 independent toggles, all default `no`: TW county/district dropdown (`tw_address`), TW mobile format check (`tw_phone_validation`), last-name-first (`last_name_first`), hide address fields for all-virtual cart (`virtual_hide_address`), auto-complete all-virtual orders (`virtual_auto_complete`), free-shipping hint (`free_shipping_hint`; + text/color settings). Option `woocommerce_checkout_enhancement_settings`; classic + Blocks checkout (support differs per feature, see Checkout Enhancement Flow); coexists with woomp (admin UI warns to enable only one side)

**Unified provider interface (branch `feat/unified-gateway-providers`)** — every payment gateway and logistics provider declares a capability meta (`get_provider_meta()`); see Unified Provider Interface section. Existing gateways/providers were only added to, never migrated (webhooks of the original gateways do not use `WebhookPipeline`).

---

## Build & Development Commands

```bash
# Setup
pnpm bootstrap              # pnpm install + composer install

# Frontend dev (Vue 3 main app)
pnpm dev                    # Vite dev server (port 5182)
pnpm build                  # Build to js/dist/

# Frontend dev (React WC Blocks)
pnpm dev:blocks             # Watch mode build for WC block checkout integration
pnpm build:blocks           # Build blocks to inc/assets/dist/blocks/

# Code quality
pnpm lint                   # ESLint (frontend) + PHPCBF
pnpm lint:fix               # Auto-fix frontend + PHPCBF
composer lint               # PHPCS only
vendor/bin/phpstan analyse  # PHPStan level 9

# PHP tests (requires WP test DB — see phpunit.xml for DB config)
composer test               # PHPUnit with API_MODE=mock
composer test:sandbox       # PHPUnit with API_MODE=sandbox
composer test:prod          # PHPUnit with API_MODE=prod

# Run a single test class or method
vendor/bin/phpunit --filter RedirectGatewayTest
vendor/bin/phpunit --filter "test_method_name"

# Frontend pure-logic tests (Node built-in runner, no browser/WP): tests/js/*.test.ts
pnpm test:js                # node --experimental-strip-types --test tests/js/*.test.ts

# Live acceptance recording (real site + gateway/logistics sandbox; one spec at a time)
cd tests/e2e && LIVE_ENV_FILES="<local env file>" npx playwright test --project=live-acceptance 04-live/<spec>

# Release (requires .env with GITHUB_TOKEN)
pnpm release                # Patch release (builds both Vue + Blocks, zips, GitHub release)
pnpm release:minor          # Minor release
pnpm release:major          # Major release
pnpm zip                    # Create plugin zip only
pnpm sync:version           # Sync package.json version → plugin.php header
pnpm i18n                   # Generate .pot translation template
```

---

## Architecture

### Dual Frontend System (Critical)

This plugin has **two separate frontend build pipelines**:

1. **Vue 3 Main App** (`vite.config.ts` → `js/dist/`)
   - Settings SPA, Refund Dialog, Invoice MetaBox, EcpgPayment — **3 Vue apps + 1 TS module** mounted from one bundle
   - Entry: `js/src/index.ts` → mounts on `#power-checkout-wc-setting-app` (injected into WC settings `#mainform`)
   - `MountRefundDialog()` creates a Vue instance on order detail pages
   - `MountInvoiceApp()` creates Vue instances on order detail pages (admin MetaBox) AND checkout page (frontend invoice form)
   - `MountEcpgPayment()` from `js/src/external/EcpgPayment/` — mounts on order-received page for ECPay ECPG embedded payment (loads SDK, triggers CreatePayment with PayToken, handles 3DS redirect)
   - `MountPayuniUniEmbed()` from `js/src/external/PayuniUniEmbed/` — mounts on order-received page for PAYUNi UNi Embed V3 (fetches SDK token, renders iframe, triggers merchant_trade, handles 3DS redirect)
   - `MountPaynowPayment()` from `js/src/external/PaynowPayment/` — mounts on order-received page for PayNow Component SDK v2 (loads CDN SDK, renders iframe, calls checkout() directly, handles 3DS; no create-payment POST; result determined by Webhook)
   - `MountShoplineEmbedded()` from `js/src/external/ShoplineEmbedded/` — mounts on order-received page for SLP embedded payment (guards itself on `window.power_checkout_slp_embedded_data`; loads official SDK CDN, POST `/pay/{order_id}/dispatch` init → SDK `createPayment()` paySession → dispatch confirm → `payment.pay(nextAction)`; `awaiting_confirmation=yes` skips rendering the cashier to avoid double charge)
   - Stack: Vue 3 + Element Plus + TanStack Vue Query + Vue Router 4 (memory mode, `createMemoryHistory`)
   - Error messages: `utils/error-code.ts` picks the message table by request path domain (`ERROR_DOMAIN`: `default` = payment/invoice wording; `logistics` = paths under `logistics/…`, AUTH → 「物流憑證錯誤」); pure-logic covered by `pnpm test:js`

2. **React WC Blocks** (`vite.config.block.ts` → `inc/assets/dist/blocks/`)
   - WooCommerce Block Checkout payment method registration
   - Entry: each `inc/assets/blocks/*.tsx` is a separate entry point (auto-discovered via glob)
   - Uses `registerPaymentMethod()` from `@woocommerce/blocks-registry`
   - Externals: jQuery, `@woocommerce/*`, `@wordpress/*` resolved from `window.wc`/`window.wp`

### Backend Domain Structure

```
inc/classes/
├── Bootstrap.php                    # Wires all domains, checks Powerhouse compatibility
├── Domains/
│   ├── Payment/
│   │   ├── ProviderRegister.php     # Registers gateways + WC Blocks integration
│   │   ├── ShoplinePayment/         # SLP: redirect gateway, API client, webhook
│   │   │   ├── Managers/StatusManager.php    # Idempotency + terminal/currency/amount guards; StatusSource label
│   │   │   ├── Managers/ReturnSyncManager.php # Return-sync query + settle (never-throw, throttled, order_key gated)
│   │   │   ├── Managers/OrderResolver.php     # Webhook lookup: identity → sessionId → referenceOrderId fallback + gateway check (SLP gateway whitelist: redirect + embedded; attempt-reference path via EmbeddedReference)
│   │   │   ├── Services/EmbeddedGateway.php   # shopline_payment_embedded; implements IEmbeddedPaymentProvider (dispatch init/confirm); credentials from RedirectSettingsDTO, display settings in DTOs/EmbeddedSettingsDTO
│   │   │   ├── Managers/EmbeddedSyncManager.php # order-received active query + settle (never-throw, throttled)
│   │   │   ├── Shared/Helpers/EmbeddedReference.php # per-attempt referenceOrderId `{order_id}-{attempt}-{6 hex}` + history meta ownership check
│   │   │   ├── Shared/Helpers/Requester.php   # masks apiKey/signKey/clientKey (keeps last 4) before writing WC log
│   │   │   └── Shared/Exceptions/SignatureException.php # Distinguishes 401 (retryable) from 200 (do-not-retry)
│   │   ├── EcpayAIO/                # ECPay AIO redirect gateway (ID: ecpay_aio)
│   │   │   ├── Services/AioRedirectGateway.php
│   │   │   ├── Http/AioCallback.php       # ReturnURL + PaymentInfoURL callbacks
│   │   │   ├── Http/DoActionClient.php    # Credit card refund (Action=R, prod-only)
│   │   │   ├── DTOs/                      # AioSettingsDTO, RequestParams
│   │   │   ├── Managers/StatusManager.php
│   │   │   └── Shared/Helpers/            # CheckMacValueService, EcpayMetaKeys, EcpayPaymentType, TradeNo, ItemName, UrlEncoder
│   │   ├── Ecpg/                    # ECPay ECPG embedded gateway (ID: ecpay_ecpg)
│   │   │   ├── Services/EcpgGateway.php
│   │   │   ├── Http/EcpgCallback.php      # ReturnURL callback (AES JSON)
│   │   │   ├── Http/EcpgFrontendApi.php   # ecpg/create-payment (order_key auth)
│   │   │   ├── Http/EcpgApiClient.php     # GetTokenbyTrade + CreatePayment + refund
│   │   │   ├── DTOs/                      # EcpgSettingsDTO, CreatePaymentParams, GetTokenParams
│   │   │   ├── Managers/StatusManager.php
│   │   │   └── Shared/Helpers/AesCrypto.php + EcpgBlocksIntegration.php
│   │   ├── Payuni/                  # PAYUNi UPP V2 redirect gateway (ID: payuni_upp)
│   │   │   ├── Services/PayuniUppGateway.php  # before_process_payment + before_order_received + refund/capture/void_auth/query_trade
│   │   │   ├── Http/PayuniCallback.php         # NotifyURL: power-checkout/payuni upp/notify (AES-256-GCM + HashInfo)
│   │   │   ├── Http/DoActionClient.php         # Credit card close/cancel (/api/trade/close + /api/trade/cancel)
│   │   │   ├── Http/QueryTradeClient.php       # Trade query (/api/trade/query)
│   │   │   ├── DTOs/                           # PayuniSettingsDTO, PayuniRequestParams
│   │   │   ├── Managers/StatusManager.php      # TradeStatus: 0=取號→payment_info, 1=已付款→processing, 2/3/8=pending
│   │   │   └── Shared/                         # Helpers (PayuniCrypto, PayuniMetaKeys, PayuniTradeNo, ItemName), Enums (PayuniPaymentMethod, PayuniTradeStatus)
│   │   ├── PayuniUniEmbed/          # PAYUNi UNi Embed V3 embedded gateway (ID: payuni_uni_embed)
│   │   │   ├── Services/PayuniUniEmbedGateway.php  # before_process_payment=token_get; before_order_received=localize SDK; create-payment REST; refund/capture/void_auth/query_trade
│   │   │   ├── Http/TokenGetClient.php              # /api/iframe/token_get V3.0 (MerID+Timestamp+IFrameDomain only)
│   │   │   ├── Http/MerchantTradeClient.php         # /api/iframe/merchant_trade (幕後授權 + 3DS; TradeAmt 後端算)
│   │   │   ├── Http/PayuniUniEmbedFrontendApi.php   # REST power-checkout/payuni/uni-embed/create-payment (order_key auth)
│   │   │   ├── Http/PayuniUniEmbedCallback.php      # NotifyURL power-checkout/payuni/uni-embed/notify (AES-256-GCM + HashInfo; always 200)
│   │   │   ├── Http/UniDoActionClient.php           # Credit card close/cancel (/api/trade/close + /api/trade/cancel)
│   │   │   ├── Http/UniQueryTradeClient.php         # Trade query (/api/trade/query)
│   │   │   ├── DTOs/PayuniUniEmbedSettingsDTO.php
│   │   │   ├── Managers/StatusManager.php           # TradeStatus: 1=processing (amount+Gateway=9 guard), 2/3/8=pending
│   │   │   └── Shared/                              # Helpers (PayuniUniEmbedMetaKeys, PayuniUniEmbedTradeNo, ItemName), Enums (PayuniUniEmbedTradeStatus, PayuniUniEmbedPaymentMethod); uses Payuni/Shared/Helpers/PayuniCrypto (no third copy)
│   │   ├── Paynow/                  # PayNow Component SDK v2 embedded gateway (ID: paynow)
│   │   │   ├── Services/PaynowGateway.php            # before_process_payment=create_payment_intent; before_order_received=localize SDK; process_refund; query_trade; admin order actions
│   │   │   ├── Http/PaynowRestClient.php              # Four-in-one: create/retrieve payment_intent + refund/retrieve_refund; Bearer PrivateKey
│   │   │   ├── Http/PaynowCallback.php                # NotifyURL power-checkout/paynow/notify (HMAC-SHA256 raw body + always 200)
│   │   │   ├── DTOs/                                  # PaynowSettingsDTO, CreatePaymentIntentParams, RefundParams
│   │   │   ├── Managers/StatusManager.php             # Status=Success/Failed; offline payment two-phase (_pc_paynow_payment_info + pending); amount guard; idempotency
│   │   │   └── Shared/                                # Helpers (WebhookVerifier, PaynowMetaKeys, PaynowTradeNo, ItemName, PaynowBlocksIntegration), Enums (PaynowPaymentMethod, PaynowIntentStatus, PaynowRefundStatus)
│   │   ├── TcatPay/                 # 黑貓PAY (Kolet) gateway (ID: tcat_pay)
│   │   │   ├── Services/TcatPayGateway.php      # AbstractPaymentGateway; sub-method field `pc_tcat_pay_method`; APN via handle_webhook(); refund/capture/void_auth/query_trade; admin action pc_tcat_pay_query_trade
│   │   │   ├── Http/TcatPayApiClient.php        # token auth (cached) + CvsOrderAppend/Query, CocsOrderAppend/Query/Cancel/CashRequest/Refund; sandbox test.4128888card.com.tw/app, prod cocs.4128888card.com.tw
│   │   │   ├── Http/TcatPayCallback.php         # REST power-checkout/tcat-pay/apn (plain-text `OK`/`RETRY` via rest_pre_echo_response)
│   │   │   ├── DTOs/                            # TcatPaySettingsDTO, CvsOrderAppendParams, CocsOrderAppendParams
│   │   │   ├── Managers/StatusManager.php       # settle_paid() only after platform query confirms; amount guard; processed-status idempotency
│   │   │   └── Shared/                          # Helpers (TcatPayChecksum, TcatPayMetaKeys, TcatPayTradeNo, TcatPayAmountLimits, TcatPayBlocksIntegration, ItemName), Enums (TcatPayMethod, TcatPayApnStatus), Exceptions (TcatPayApiException, TcatPayRetryLaterException)
│   │   ├── Linepay/                 # LINE Pay direct Online API v4 (ID: linepay)
│   │   │   ├── Services/LinepayGateway.php      # AbstractPaymentGateway; confirm/cancel via handle_webhook() (browser GET); refund (partial OK); admin action pc_linepay_query_trade
│   │   │   ├── Http/LinepayApiClient.php        # HMAC-SHA256 signed; request/check/confirm/details/refund; sandbox-api-pay.line.me / api-pay.line.me
│   │   │   ├── Http/LinepayReturnController.php # REST power-checkout/linepay/confirm + /cancel (GET; order_id + key + LINE-appended transactionId/orderId; always 302)
│   │   │   ├── Managers/StatusManager.php       # confirm + settle (orderId + amount snapshot guard; only transactionType=PAYMENT & payStatus=CAPTURE settles)
│   │   │   ├── Managers/LinepayReconcileJob.php # Action Scheduler `pc_linepay_reconcile` (check status first, never confirm as a probe)
│   │   │   └── Shared/                          # Helpers (LinepaySignature, LinepayOrderId `PCL{order}-{attempt}-{hex}`, LinepayMetaKeys), Enums (LinepayReturnCode, LinepayConfirmResult), Exceptions (LinepayApiException)
│   │   └── Shared/                  # AbstractPaymentGateway implements IPaymentProvider, PaymentApiService (REST /refund), PaymentDispatchApiService (REST /pay/{id}/dispatch), WebhookPipeline
│   │       ├── Interfaces/IPaymentProvider.php # Payment 領域統一介面（9 methods incl. get_provider_meta + verify_callback，extends IGateway；mirrors ILogisticsProvider）
│   │       ├── Interfaces/IEmbeddedPaymentProvider.php # `dispatch(WC_Order, array): array` — two-phase embedded SDK contract (no pay_session = init; with = confirm)
│   │       ├── DTOs/ProviderMetaDTO.php + PaymentCapabilitiesDTO.php # id / PaymentMode / OrderCreationStrategy / capabilities {refund, partial_refund, capture, void, query, recurring}
│   │       ├── Enums/PaymentMode.php, OrderCreationStrategy.php, WebhookOutcome.php # form-redirect|api-redirect|client-sdk; pre-create-pending|callback-creates-order; PROCESSED/DUPLICATE/SIGNATURE_INVALID/NOT_FOUND/HANDLER_ERROR
│   │       ├── Exceptions/DispatchException.php # business error with ErrorCode + HTTP status (400/409) surfaced by dispatch REST
│   │       └── Services/WebhookPipeline.php # verify → resolve order → idempotency key → handler → audit log → ack (never throws)
│   ├── Logistics/
│   │   ├── ProviderRegister.php     # Registers logistics providers + WC shipping method + checkout meta
│   │   ├── Ecpay/                   # ECPay AllInOne Logistics v2 (ID: ecpay_logistics)
│   │   │   ├── Services/EcpayLogisticsProvider.php  # implements ILogisticsProvider
│   │   │   ├── Services/WC_EcpayLogisticsShipping.php  # extends WC_Shipping_Method (classic checkout)
│   │   │   ├── Http/LogisticsApiClient.php  # AES-128-CBC (reuses Ecpg AesCrypto); RqHeader Revision 1.0.0; 5-min Timestamp
│   │   │   ├── Http/LogisticsCallback.php   # ServerReplyURL (AES-JSON 3-layer) + ClientReplyURL (selection)
│   │   │   └── DTOs/                        # EcpayLogisticsSettingsDTO, StoreSelectionParams, CreateShipmentParams
│   │   ├── Paynow/                  # PayNow Logistics (ID: paynow_logistics)
│   │   │   ├── Services/PaynowLogisticsProvider.php  # implements ILogisticsProvider; SEVEN→01/FAMI→03/HILIFE→05/TCAT→06; CVS ≤20000 / home ≤100000; idempotent ReNewOrder
│   │   │   ├── Services/WC_PaynowLogisticsShipping.php  # extends WC_Shipping_Method (per-service rates)
│   │   │   ├── Http/LogisticsApiClient.php  # Add_Order/ReNewOrder/CancelOrder/Get_Order_Info/print (DELETE for cancel; SEVEN GET /api/Order711; TCAT POST PrintBlackCatLabel)
│   │   │   ├── Http/LogisticsCallback.php   # power-checkout/paynow: logistics/selection-callback + logistics/status-callback (selection: orderid+order_key hash_equals; status: push untrusted → Get_Order_Info query; always HTTP 200)
│   │   │   ├── DTOs/                        # PaynowLogisticsSettingsDTO (test: testlogistic.paynow.com.tw / prod: logistic.paynow.com.tw), CreateShipmentParams
│   │   │   └── Shared/                      # Helpers (TripleDesCrypto [R2: ECB不是CBC], PassCodeService [SHA1], PaynowLogisticsMetaKeys [前綴_pc_paynow_logistics_], ItemName), Enums (PaynowLogisticService, PaynowDeliverMode, PaynowLogisticsStatus)
│   │   ├── Tcat/                    # 黑貓宅急便直串 (ID: tcat_logistics) — HOME only
│   │   │   ├── Services/TcatLogisticsProvider.php   # ILogisticsProvider + ISupportsConnectionTest; PrintOBT/DownloadOBT(PDF)/OBTStatus(2h cache)/ReserveAdd(return)
│   │   │   ├── Services/WC_TcatLogisticsShipping.php
│   │   │   ├── Http/TcatLogisticsApiClient.php      # test egs.suda.com.tw:8443 / prod api.suda.com.tw
│   │   │   └── Shared/                              # Helpers (TcatFieldRules, TcatLogisticsDates, TcatLogisticsMetaKeys, TcatStatusMapper), Enums (TcatSpec, TcatThermosphere), Exceptions (TcatLogisticsException)
│   │   ├── Hct/                     # 新竹物流 EDI SOAP (ID: hct_logistics) — HOME only
│   │   │   ├── Services/HctLogisticsProvider.php    # + static register_hooks(): update_option_woocommerce_hct_logistics_settings → sync waybill range
│   │   │   ├── Http/HctSoapClient.php + HctTrackClient.php  # hand-built SOAP 1.1 (.asmx, `_Json` methods); searchGoods track client
│   │   │   └── Shared/                              # Helpers (HctTrackCrypto, HctStatusMapper, HctLabelImage, HctLogisticsMetaKeys), Enums (HctProductType), Exceptions (HctLogisticsException)
│   │   ├── SfExpress/               # 順豐 IUOP (ID: sf_express) — HOME only
│   │   │   ├── Services/SfExpressProvider.php       # create/print(PDF)/query track/cancel; handle_status_callback = signed track-push
│   │   │   ├── Http/SfApiClient.php + SfTrackPushCallback.php  # push REST power-checkout/sf-express/track-push (registered even when disabled)
│   │   │   ├── Managers/TrackPushManager.php + TrackStatusManager.php  # sign verify → order by event time → per-event ack; trackUniqueId idempotency
│   │   │   └── Shared/                              # Helpers (SfCrypto, SfAccessTokenStore, SfOpcodeMapper, SfRegion, SfExpressMetaKeys), Exceptions (SfExpressException)
│   │   ├── Kerrytj/                 # 嘉里大榮 EDIEntrance (ID: kerrytj_logistics) — HOME only
│   │   │   ├── Services/KerrytjLogisticsProvider.php  # + static register_hooks(): settings save → sync ambient/cold ranges; extra methods update_shipment / print_detail
│   │   │   ├── Http/KerrytjApiClient.php + KerrytjAdminApiService.php  # admin REST logistics/{id}/kerrytj/update|detail (registered only when enabled)
│   │   │   └── Shared/                              # Helpers (KerrytjBln, KerrytjFields, KerrytjStatusMapper, KerrytjLogisticsMetaKeys), Enums (KerrytjBlnKind, KerrytjTemperature), Exceptions (KerrytjLogisticsException)
│   │   └── Shared/
│   │       ├── Interfaces/ILogisticsProvider.php  # 11-method interface incl. get_provider_meta (mirrors IInvoiceService)
│   │       ├── Interfaces/ISupportsConnectionTest.php  # optional `test_connection(): array` (read-only API; not implemented → ping 501)
│   │       ├── Interfaces/IWaybillNumberFormat.php + Helpers/Mod7CheckDigitFormat.php, PlainSerialFormat.php
│   │       ├── DTOs/LogisticsProviderMetaDTO.php + ShipmentCapabilitiesDTO.php  # id / supported_delivery_methods / capabilities {can_create_shipment, can_query_shipment, has_cvs_map, can_print, can_return, can_cancel}
│   │       ├── Enums/                             # LogisticsSubType, LogisticsAccountType, LogisticsTemperature, LogisticsPaymentScenario, LogisticsStatus (ECPay raw), DeliveryMethod (HOME|CVS), ShippingState (11 unified states; new providers return `unified_state`)
│   │       ├── Helpers/LogisticsMetaKeys.php       # Order meta CRUD helper (HPOS-aware)
│   │       └── Services/                          # LogisticsApiService (REST power-checkout/v1; PROVIDER_IDS = 7 ids), LogisticsPingApiService (POST logistics/{provider}/ping), WaybillNumberAllocator (atomic range allocator), CartLogisticsApiService
│   ├── Invoice/
│   │   ├── ProviderRegister.php     # Registers invoice providers + auto-issue hooks
│   │   ├── Amego/                   # AmegoProvider (IInvoiceService), API client, DTOs
│   │   ├── Ecpay/                   # EcpayInvoiceProvider (IInvoiceService, ID: ecpay)
│   │   │   ├── Services/EcpayInvoiceProvider.php
│   │   │   ├── Http/InvoiceApiClient.php  # AES-128-CBC
│   │   │   ├── DTOs/                      # EcpayInvoiceSettingsDTO, IssueParams, CancelParams, IssueResponse
│   │   │   └── Shared/                    # AesCrypto, Enums (EApi, ETaxType, ECarrierType)
│   │   ├── Ezpay/                   # EzpayInvoiceProvider (IInvoiceService + ISupportsAllowance + ISupportsQuery, ID: ezpay)
│   │   │   ├── Services/EzpayInvoiceProvider.php
│   │   │   ├── Http/InvoiceApiClient.php  # AES-256-CBC (PKCS#7 blocksize=32 + ZERO_PADDING + hex); CheckCode SHA256; test: cinv.ezpay.com.tw / prod: inv.ezpay.com.tw
│   │   │   ├── DTOs/                      # EzpaySettingsDTO, IssueParams, CancelParams, IssueResponse, AllowanceParams, AllowanceInvalidParams, AllowanceResponse, QueryParams, QueryResponse
│   │   │   └── Shared/                    # Helpers (AesCrypto, CheckCodeService, UrlEncoder, PiiMasker), Enums (EApi, ETaxType, ECarrierType, ECategory)
│   │   ├── Paynow/                  # PaynowInvoiceProvider (IInvoiceService + ISupportsAllowance + ISupportsQuery, ID: paynow_invoice)
│   │   │   ├── Services/PaynowInvoiceProvider.php  # const ID='paynow_invoice' (R5: 不同於金流 'paynow'); option woocommerce_paynow_invoice_settings
│   │   │   ├── Http/InvoiceApiClient.php  # Bearer JWT-Token (no symmetric encryption); type==='success'; issue/cancel/allowance/cancel-allowance/query; dev: invoiceapi-dev.paynow.com.tw / prod: invoiceapi-prod.paynow.com.tw
│   │   │   ├── DTOs/                      # PaynowInvoiceSettingsDTO, IssueParams (B2C tax_amount=0/B2B actual; carrier/donation mutex; ZeroTax requires reason), IssueResponse, AllowanceParams, AllowanceResponse, QueryParams, QueryResponse
│   │   │   └── Shared/Enums/              # ECarrierType (5 cases), ETaxType (4 cases), EZeroTaxReason (10 cases)
│   │   └── Shared/                  # IInvoiceService interface, InvoiceApiService (REST /invoices)
│   ├── Settings/
│   │   └── Services/                # WC settings tab, REST /settings CRUD (+ `checkout_enhancement` special case), default address format
│   └── Checkout/                    # Checkout Enhancement (virtual provider id `checkout_enhancement`; not in ProviderUtils container)
│       ├── ProviderRegister.php     # Registers every service's hooks unconditionally; toggles are checked at runtime inside callbacks
│       ├── DTOs/
│       │   ├── CheckoutEnhancementSettingsDTO.php  # Static-only (no wp-utils DTO base): DEFAULTS (10 keys), get_all (defaults merged) / get_stored / is_on / merge_valid (per-key validation)
│       │   └── FreeShippingStatusDTO.php           # Free-shipping calc result (achieved / diff)
│       ├── Services/
│       │   ├── NameOrderService.php                # last_name_first: TW locale key, last_name priority before first_name (`woocommerce_get_country_locale` p20)
│       │   ├── TwAddressService.php                # tw_address: `woocommerce_states` TW → 22 counties; enqueues classic-tw-address.js (district + postcode linkage, classic only)
│       │   ├── VirtualCartAddressService.php       # virtual_hide_address: classic unsets billing address fields; Blocks writes TW locale hidden + CSS-hides country (D15); locale cache reset
│       │   ├── PhoneValidationService.php          # tw_phone_validation: `^09\d{8}$` after stripping spaces/hyphens; classic `$errors`, Blocks `RouteException` 400
│       │   ├── VirtualOrderAutoCompleteService.php # virtual_auto_complete: queue on →processing, re-check + complete at `shutdown`; filter `power_checkout_virtual_auto_complete_should_complete`
│       │   └── FreeShippingHintService.php         # free_shipping_hint: classic table row + Store API extension `power-checkout.free_shipping_hint` + Blocks script enqueue
│       └── Shared/Helpers/
│           ├── CartInspector.php                   # is_all_virtual_cart/order (all items is_virtual; mixed = physical), is_classic_checkout / is_block_checkout
│           ├── FreeShippingCalculator.php          # Same algorithm as WC free_shipping method; returns FreeShippingStatusDTO|null
│           └── TwAdminDivision.php                 # Static TW data: 22 counties (內政部用字「臺」) / 371 districts / 3-digit postcode
└── Shared/
    ├── Utils/ProviderUtils.php      # Provider container + WC options CRUD (central to the system)
    ├── Utils/OrderUtils.php         # HPOS-aware order utilities
    └── DTOs/BaseSettingsDTO.php     # Base for all provider settings DTOs
```

### Provider System Lifecycle

All payment/invoice providers flow through `ProviderUtils`:
1. Listed in `ProviderRegister::$xxx_providers` static arrays
2. Enabled state stored in WC option: `woocommerce_{id}_settings` → `enabled`
3. Only enabled providers instantiated into `ProviderUtils::$container`

```php
ProviderUtils::is_enabled('amego');           // Check if active
ProviderUtils::get_provider('amego');         // Get from container
ProviderUtils::toggle('amego');               // Toggle enabled state
ProviderUtils::get_option('amego', 'key');    // Read setting
ProviderUtils::update_option('amego', [...]);  // Write settings
```

### PHP → JS Data Bridge

Three `wp_localize_script` data objects power the frontend:
- `window.power_checkout_data.env` — global env (nonce, URLs, user, order statuses)
- `window.power_checkout_order_data` — order detail page (gateway info, refund amounts)
- `window.power_checkout_invoice_metabox_app_data` — invoice MetaBox (provider list, invoice state)

Frontend access: always use `utils/env.ts`, never read `window` directly.

---

## Coding Standards

### PHP
- `declare(strict_types=1)` in every file
- `final class` by default (PHPCS enforced)
- PHP 8.1+ features: enum, readonly, named args, match expression
- PHPStan level 9 — all issues must be resolved
- Text domain: `'power_checkout'` (underscore, not hyphen)
- Hook callbacks: always static methods `[__CLASS__, 'method']`
- Exception handling: catch `\Throwable`, log via `Plugin::logger()`, never expose internals to frontend
- PSR-4: namespace `J7\PowerCheckout` → `inc/classes/`

### Vue 3 Frontend
- `<script setup lang="ts">` — Composition API only, no Options API
- `@/` alias for all imports (no relative paths)
- Element Plus only — no other UI libraries
- TanStack Vue Query defaults: `staleTime: 15min`, `retry: 0`, `refetchOnWindowFocus: false`
- `ElNotification` handled by API interceptor — don't trigger manually

### React WC Blocks
- TypeScript with JSX
- External WP/WC globals via `vite-plugin-optimizer` shimming
- Type declarations in `inc/assets/blocks/types/types.d.ts`

---

## Testing Infrastructure

- Active test suite: `tests/Integration/` — namespace `Tests\Integration\`, base class `Tests\Integration\TestCase extends WP_UnitTestCase`, bootstrap `tests/bootstrap.php`
- Config: `phpunit.xml.dist` — **group whitelist**: only `smoke` / `happy` / `error` / `edge` / `security` groups are collected; tests must be annotated with at least one group to run
- Additional test categories used: `integration`, `invoice`, `<provider>` (e.g. `ezpay`)
- Checkout Enhancement: `tests/Integration/Checkout/` (+ `tests/Integration/Settings/CheckoutEnhancementSettings*Test.php`); base class `Tests\Integration\Checkout\CheckoutTestCase` (cart session, products, shipping zones, classic validation/order creation, Store API checkout, logger capture); class-level `@group checkout`. **S1**: Store API works under `WP_UnitTestCase` — Blocks cases send real orders via `rest_do_request` (no Reflection workaround). Harness pitfalls: physical carts need `create_free_shipping_zone()` first (otherwise `needs_shipping()` is false); payable orders need `enable_bacs_gateway()`; `WC_Checkout::$fields` / `WC()->countries` singleton caches are cleared by the harness
- API mode: controlled by `API_MODE` env var (`mock` / `sandbox` / `prod`)
- Pure-logic offline verification (no WP bootstrap): `tests/offline/ezpay-pure-harness.php` — used when LocalWP DB constraints prevent `WP_UnitTestCase` from running locally
- Legacy directory `inc/tests/` exists but is **not** referenced by `phpunit.xml.dist`; treat as inactive
- E2E tests: `tests/e2e/` (Playwright) with separate `package.json` — admin, frontend, integration suites (project `api-tests`; `04-live/**` is ignored by it)
- **Live acceptance** (`tests/e2e/04-live/*.spec.ts`, Playwright project `live-acceptance`): real site + gateway/logistics sandbox with recorded video/trace; specs `slp-embedded` / `linepay` / `tcat-pay` / `tcat-logistics` / `hct` / `sf-express` / `kerrytj-settings`. Run one spec at a time: `cd tests/e2e && LIVE_ENV_FILES="<local env file(s), ';'-separated>" npx playwright test --project=live-acceptance 04-live/<spec>`. Headed Chrome (`LIVE_BROWSER_CHANNEL`, default `chrome`; headless is often blocked by WAF / cashier anti-automation), no admin storageState (guest checkout; admin steps log in inside the spec), no extra `X-WP-Nonce` header (an empty nonce makes WP answer 403 `rest_cookie_invalid_nonce` to guest `order_key` REST such as dispatch). Credentials come **only** from env vars / a local untracked env file parsed by `helpers/live-env.ts` (`.env.*` and `.tmp/` are gitignored); a spec skips with the list of missing key names; nothing credential-like is committed. Videos land in `tests/e2e/acceptance-videos/` (gitignored). `E2E_BASE_URL` overrides the site; `PC_LIVE_ACCEPTANCE=1` marks a live run (global setup/teardown skipped). SF Express uses the official public SIT credentials parsed at run time; `LIVE_LINEPAY_MANUAL=1` pauses at the LINE Pay page for a real person
- Baseline at the last full mock run (3055 tests): 4 pre-existing failures (`EzpayAesCrypto` edge + `LinePayTest` ×3, the SLP-proxied LINE Pay tests); PHPStan baseline only `RedirectSettingsDTO.php:148 isset.property`
- **Frontend pure-logic tests**: `tests/js/*.test.ts`, run with `pnpm test:js` (Node built-in `node:test` + `--experimental-strip-types`; no browser, no WP) — currently `utils/error-code.ts` domain-aware message tables
- Provider test filters (mock mode): `SlpEmbedded|SlpOrderResolver|SlpWebhookResponse|SlpReturnSync|SlpSessionIdentity`, `LinepayDirect`, `TcatPay`, `TcatLogistics|WC_TcatLogisticsShipping`, `Hct`, `Sf(Crypto|AccessTokenStore|Express|TrackPush|Region)`, `Kerrytj`, `LogisticsPingStatusCode` (see `specs/plan/2026-11-20-acceptance-report.md` §10 for the last run)

---

## REST API

| Namespace | Method | Endpoint | Auth |
|---|---|---|---|
| `power-checkout/v1` | GET | `/settings` | Nonce |
| `power-checkout/v1` | GET/POST | `/settings/{id}` | Nonce |
| `power-checkout/v1` | POST | `/settings/{id}/toggle` | Nonce |
| `power-checkout/v1` | GET/POST | `/settings/checkout_enhancement` | Nonce — special case inside `/settings/{id}` (see Checkout Enhancement Flow): GET fills defaults, POST validates per key |
| `power-checkout/v1` | POST | `/refund` | Nonce |
| `power-checkout/v1` | POST | `/refund/manual` | Nonce |
| `power-checkout/v1/invoices` | POST | `/issue/{order_id}` | Nonce |
| `power-checkout/v1/invoices` | POST | `/cancel/{order_id}` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/store-selection` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/create-shipment` | Nonce |
| `power-checkout/v1` | GET | `/logistics/{order_id}` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/print` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/cancel` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/return` | Nonce |
| `power-checkout/v1` | GET | `/logistics/providers/meta` | Nonce — enabled providers' `LogisticsProviderMetaDTO::to_array()` keyed by id |
| `power-checkout/v1` | POST | `/logistics/{provider}/ping` | Nonce — connection test (`ISupportsConnectionTest`); always registered (no provider needs to be enabled); unknown provider 404, not implemented 501, VALIDATION 422, other failures **424** (never 5xx) |
| `power-checkout/v1` | POST | `/logistics/{order_id}/kerrytj/update` | Nonce — Kerry TJ update shipment (registered only when `kerrytj_logistics` enabled) |
| `power-checkout/v1` | POST | `/logistics/{order_id}/kerrytj/detail` | Nonce — Kerry TJ detail PDF (same condition) |
| `power-checkout/v1` | POST | `/pay/{order_id}/dispatch` | `__return_true`; body `order_key` `hash_equals` (anti-IDOR) — embedded provider two-phase dispatch |
| `power-checkout/slp` | POST | `/webhook` | HMAC-SHA256 (signature mismatch → 401; everything else → 200, never 500) |
| `power-checkout/ecpay` | POST | `/aio/return` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/aio/payment-info` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/ecpg/return` | AES-128-CBC (TransCode + RtnCode) |
| `power-checkout/ecpay` | POST | `/ecpg/create-payment` | order_key (in body) |
| `power-checkout/ecpay` | POST | `/logistics/status-callback` | MerchantID verified inside |
| `power-checkout/ecpay` | POST | `/logistics/selection-callback` | open (ClientReplyURL) |
| `power-checkout/payuni` | POST | `/upp/notify` | AES-256-GCM + HashInfo SHA256 (verified inside; always HTTP 200) |
| `power-checkout/payuni` | POST | `/uni-embed/create-payment` | order_key hash_equals (in body) |
| `power-checkout/payuni` | POST | `/uni-embed/notify` | AES-256-GCM + HashInfo SHA256 (verified inside; always HTTP 200) |
| `power-checkout/paynow` | POST | `/notify` | HMAC-SHA256 raw body (`X-Payment-Center-Hmac-Sha256`, verified inside; always HTTP 200) |
| `power-checkout/paynow` | POST | `/logistics/selection-callback` | returnUrl carries `orderid` + `cid`=order_key; both required + `hash_equals` (anti-IDOR) |
| `power-checkout/paynow` | POST | `/logistics/status-callback` | open; push untrusted → server-side Get_Order_Info query is source of truth (10s throttle; always HTTP 200) |
| `power-checkout/tcat-pay` | POST | `/apn` | checksum (no secret → trigger only) + platform query confirms; plain-text `OK` (200) / `RETRY` (503 → platform resends in 15 min) |
| `power-checkout/linepay` | GET | `/confirm` | browser return: `order_id` + `key` (order_key `hash_equals`) + `transactionId` + `orderId` must all match order meta; always 302 |
| `power-checkout/linepay` | GET | `/cancel` | same verification (transactionId/orderId optional on cancel); always 302 |
| `power-checkout/sf-express` | POST | `/track-push` | header `sign` = Base64(MD5(key+body+key)) over raw body; no push key configured → whole batch rejected; HTTP always 200 + SF-format ack |

`GET /settings` additionally returns `data.provider_meta.{payments,logistics}` (id → meta array) (intended for Vue to hide unsupported buttons; not consumed by any page yet).

Nonce auth requires `X-WP-Nonce` header (`wp_create_nonce('wp_rest')`).
ECPay callbacks use `permission_callback: __return_true`; auth is verified inside callback.

---

## Unified Provider Interface

### Payment

- `IPaymentProvider::get_provider_meta(): ProviderMetaDTO` — `AbstractPaymentGateway` supplies a conservative default (`form-redirect` / `pre-create-pending`, capabilities derived by reflection: only a gateway that **overrides** `process_refund` / `capture` / `void_auth` / `query_trade` can declare the capability true — method-level, admin-order-action-only abilities do not count). Gateways declare their real `PaymentMode` by overriding. All gateways are `PRE_CREATE_PENDING` (WC creates the pending order before `process_payment`)
- `GET /settings` exposes the metas under `data.provider_meta.payments`
- **Unified webhook frame** (`WebhookPipeline`, used via `AbstractPaymentGateway::handle_webhook()` — final): `verify_callback()` → `resolve_callback_order()` → `get_callback_idempotency_key()` → `process_callback()` → audit log (provider / outcome / key / elapsed; **never the body**) → `callback_ack_response()`. Always acks, never throws; idempotency key goes to order meta `_pc_callback_processed` (latest 50) **only after the handler succeeds**, so a handler exception lets the provider resend. `verify_callback()` defaults to **fail-closed** (`false`). `WebhookOutcome` tells the ack hook what happened. Used only by `tcat_pay` and `linepay`; every other gateway (including SLP embedded, which shares the SLP webhook) keeps its own callback. Gateways whose key would let a forged request pre-poison idempotency (tcat_pay, linepay) override the key to `''` and keep idempotency in their own `StatusManager`
- **Embedded dispatch** (`IEmbeddedPaymentProvider`, REST `POST /pay/{order_id}/dispatch`): check order → 404; `order_key` `hash_equals` → 403; gateway must implement `IEmbeddedPaymentProvider` → 400 `UNSUPPORTED`; `needs_payment()` → 409; then `dispatch()` (payload = body minus `order_id`/`order_key`; no `pay_session` = init, with = confirm). `DispatchException` → its own ErrorCode/HTTP (400/409, message is user-safe); any other `\Throwable` → 500 generic message (detail only in log). Currently implemented by `shopline_payment_embedded` only

### Logistics

- `ILogisticsProvider::get_provider_meta(): LogisticsProviderMetaDTO` — `supported_delivery_methods` (`DeliveryMethod::HOME|CVS`) + `ShipmentCapabilitiesDTO`. `LogisticsApiService` checks capability **before** calling the provider: declared `false` → HTTP **501** + `error_code UNSUPPORTED`. **Exempt (keep legacy status codes)**: `ecpay_logistics`, `payuni_logistics`, `paynow_logistics` (`CAPABILITY_CHECK_EXEMPT_IDS`). All 4 new providers are checked
- Provider id resolution list `LogisticsApiService::PROVIDER_IDS` (7 ids; new ones last so they never win when the request carries no provider param) is extensible via filter `power_checkout_logistics_provider_ids`
- Error → HTTP mapping in `LogisticsApiService` (`exception_response()`; commit `7e6e7ca`): exceptions carrying a normalized `ErrorCode` (`error_code` / `raw_code`; the 4 new providers) map via `http_status_for()` = `ErrorCode::to_http_status()` with provider-side `502` rewritten to **424 Failed Dependency** — `NETWORK` / `PROVIDER` (connection / provider failure) → **424**, `UNSUPPORTED` → **501** (same as the capability-check 501), `AUTH` 401, `VALIDATION` 422, `NOT_FOUND` 404, `CONFLICT` / `NUMBER_EXHAUSTED` 409, `SIGNATURE` 400, `UNKNOWN` (unexpected) → **500**. Cloudflare etc. replace origin 5xx bodies, hiding the diagnostic message, hence 424. Legacy providers' plain `\Exception` (no `error_code`: ecpay / payuni / paynow) keep the message-keyword map (404 order not found / 403 state preconditions / 400 otherwise) and never produce 502. No path in `LogisticsApiService` returns 502 any more
- `ShippingState` (11 values: pending / accepted / in_transit / arrived / ready_for_pickup / picked_up / delivered / returned / failed / expired_unclaimed / cancelled; `is_terminal()`): new providers return `unified_state` from `query_shipment()` alongside their raw status; `LogisticsStatus` (ECPay raw) is untouched
- `ISupportsConnectionTest::test_connection()`: read-only API ping, **never writes data**, returns a credential-free summary; used by `POST /logistics/{provider}/ping`. Implemented by tcat_logistics / hct_logistics / sf_express / kerrytj_logistics
- `WaybillNumberAllocator` (HCT, Kerry TJ): number ranges kept in options `pc_waybill_range_{key}` / `pc_waybill_current_{key}` (autoload no). Allocation is **one atomic SQL** (`UPDATE … SET option_value = LAST_INSERT_ID(value + 1) WHERE value < end`, then `SELECT LAST_INSERT_ID()`), no read-then-write; a number once issued is never reclaimed (also after API timeout, avoiding duplicate waybills); `sync_range()` keeps the current counter if still inside the new range; number format injected via `IWaybillNumberFormat` (`Mod7CheckDigitFormat`, `PlainSerialFormat`)

---

## SLP Embedded Payment Flow (shopline_payment_embedded)

1. `before_process_payment()` only writes `_pc_slp_embedded_pending` and returns the order-received URL (no API call)
2. `before_order_received()` (order_key verified + `needs_payment()`): `EmbeddedSyncManager::sync()` active query first (30s throttle, never-throw; tradeOrderId only from server-side `_pc_payment_identity`, **never `$_GET`**); then, if still unpaid and credentials present, localizes `power_checkout_slp_embedded_data` (dispatch URL, order_key, SDK URL, `awaiting_confirmation`)
3. Frontend → dispatch **init**: returns SDK params (clientKey/merchantId/amount in cents computed server-side/env/installmentCounts); dispatch **confirm** (`pay_session` ≤ 10240 bytes, non-empty string) → `trade/payment/create` → returns `next_action` verbatim; no `nextAction` in response = creation failed (note + 500, front end must not call `pay()`)
4. Guards: confirm lock transient `pc_slp_emb_lock_{order_id}` (30s, 409 on double click); `needs_payment()` re-check (stale tab); SLP `1001`/`1006` duplicate-order → 409
5. **Per-attempt `referenceOrderId`** (`EmbeddedReference`): `{order_id}-{attempt}-{6 hex}` (≤ 32 chars). SLP rejects reusing a `referenceOrderId` (`1001`), so each confirm generates a new one and **writes it to `_pc_slp_reference_history` before calling the API** (SLP may create the trade even if we time out). Ownership rule: a new-format reference belongs to an order only if the ID embedded equals the order ID **and** it exists in that order's history meta (server-written only; attempt numbers being guessable is harmless); legacy reference rules stay for pre-upgrade trades. `OrderResolver` and `EmbeddedSyncManager` both apply it
6. Result: shared `/power-checkout/slp/webhook` (SLP webhook rules unchanged) or the order-received active query → shared `StatusManager` (four guards). `OrderResolver` gateway check is now a whitelist (redirect + embedded)
7. Currency must be TWD; credentials = `RedirectSettingsDTO` (merchantId / apiKey / clientKey / signKey / mode) — storefront `is_available()` is false unless currency is TWD and merchantId / apiKey / clientKey are all set. `Requester` masks `apiKey` / `signKey` / `clientKey` (last 4 chars kept, recursive, case-insensitive keys) before writing WC logs
8. Refund: gateway overrides `process_refund` / `process_gateway_refund`, failure path via `fail_gateway_refund()`

[GAP: SLP sandbox PCI API (`bin/query`, `token/generate`) answers `1005 No interface access permission` even in a minimal HTML with the official SDK → card form renders and confirm succeeds, but `pay()` cannot complete; processing/refund path unverified live until SHOPLINE grants SDK permission to the sandbox merchant; mock tests cover it]

---

## Shopline Payment Flow

1. `process_payment()` → `ApiClient::create_session()` → writes `_pc_identity` (sessionId, before the EXPIRED check) → redirect to SLP hosted page
2. **Return sync (issue #18)**: customer redirected back to `order-received?key=…&tradeOrderId=…` → `before_order_received()` delegates to `Managers/ReturnSyncManager::sync()`, which queries `/trade/payment/get` **synchronously** and settles payment in the same request (the `wp` action runs before `the_content`, so the thankyou template re-reads the corrected status)
   - Gates in order: `tradeOrderId` regex whitelist `[A-Za-z0-9_-]{1,64}` → `order_key` `hash_equals` → status must be pending/failed → 30s transient throttle `pc_slp_return_sync_{order_id}` → API query (10s timeout, filter `power_checkout_slp_return_query_timeout`) → order-ownership check (issue #19): `sessionId === _pc_identity` **or** `referenceOrderId === (string) $order->get_id()`; a numeric `referenceOrderId` pointing at another order is always rejected (Session/SDK flows — installments / BNPL / bank transfer — return an `RL…` referenceOrderId, so `sessionId` is the only usable key there) → write `_pc_payment_identity` → `StatusManager`
   - **never-throw**: `sync()` returns a `ReturnSyncResult` enum instead of throwing; `before_order_received()` wraps it in a second try/catch. The thankyou page must never 500 nor skip `empty_cart()`
3. SLP sends webhook POST to `/wp-json/power-checkout/slp/webhook`
4. Webhook signature: `hash_hmac('sha256', "{timestamp}.{body}", $signKey)`
   - Response codes: signature mismatch → **401** (merchant may have mis-typed signKey; keep SLP's retry); everything else (stale timestamp / DTO parse failure / order not found / business exception / success) → **200**. Never 500 — SLP retries every 60 min forever
5. Webhook order lookup goes through `Managers/OrderResolver::resolve($tradeOrderId, $referenceOrderId, $sessionId)`: `_pc_payment_identity` first → `sessionId` ↔ `_pc_identity` (unique match; issue #19) → numeric `referenceOrderId` fallback (both fallbacks immune to "customer never returned") → gateway check (`payment_method === shopline_payment_redirect`) → consistency re-check (numeric `referenceOrderId` must equal the order ID; non-numeric reference + `sessionId` must equal `_pc_identity`) → back-fills identity on fallback hit
6. `StatusManager::update_order_status($source)`: SUCCEEDED→processing (+ `set_transaction_id`), EXPIRED→cancelled, others→pending. Shared by both paths, with four guards:
   - Idempotency: `_pc_payment_processed_status` holds `"{tradeOrderId}:{status}"`
   - Terminal-state guard (SUCCEEDED only): refunded / cancelled / completed cannot be "revived"
   - Currency guard: notified currency must equal `$order->get_currency()` (store default may be USD)
   - Amount guard: `abs(notified_cents - round(total * 100)) <= 1` (±1 cent float tolerance)
   - `StatusSource::RETURN_SYNC` / `WEBHOOK` prefixes the order note title so support can see which path settled it
7. Refund support by payment method:

| Payment Method | Partial Refund | Full Refund |
|---|---|---|
| Credit Card | Yes | Yes |
| Apple Pay | No | Yes |
| LINE Pay | Yes | Yes |
| ZingalaCard (zero-card installment) | No | Yes |
| ATM Virtual Account | No | No |

---

## ECPay Payment Flow

### AIO Redirect (ecpay_aio)

1. `before_process_payment()` validates the checkout installment field, writes installment meta, marks `_pc_ecpay_redirect_pending`, returns order-received URL (no API call at this stage)
2. `before_order_received()` (order_key verified + `needs_payment()`) — only when redirect is pending (or no MerchantTradeNo was ever sent): generates a **new** MerchantTradeNo via `TradeNo::encode_unique()` (ECPay MerchantTradeNo is permanently unique — resend → `10300028`), appends it to `_pc_ecpay_trade_no_history`, assembles `RequestParams` (CheckMacValue SHA256) and renders auto-submit form → ECPay Cashier V5. Refresh / back / ClientBackURL do **not** auto-redirect; thank-you page shows a「前往付款」button → order-pay
3. ECPay sends server-to-server POST to `/wp-json/power-checkout/ecpay/aio/return` (ReturnURL) — CheckMacValue verified; order lookup matches current trade no **and** history (old attempts' ATM/CVS codes still settle)
4. ATM/CVS/BARCODE additionally receive `/aio/payment-info` with virtual account / store code / barcode (dedup per MerchantTradeNo)
5. `StatusManager::update_order_status()` maps RtnCode: `1` → processing (writes the **settled** MerchantTradeNo back to `_pc_ecpay_trade_no` so DoAction refund/capture use the paid attempt), others → pending; already-paid/refunded orders are not re-settled, a second paid attempt only adds a「疑似重複付款」note
6. Installment (`enableInstallment`, default off): ECPay hides one-time payment when `CreditInstallment` is sent, so it is **customer-selected at checkout** (classic `payment_fields()` / blocks `AioBlocksIntegration` + `onPaymentSetup`), not site-wide. Period `30` is sent as `30N` (永豐圓夢彈性分期). Choosing「不分期」clears prior installment meta; `period` variant is a developer/subscription extension point and is never touched
7. Refund support by payment method:

| Payment Method | API Refund |
|---|---|
| Credit Card (one-time/installment/period) | Yes — DoAction Action=R (prod-only); response is URL-encoded (`parse_str`), not `RtnCode\|RtnMsg`; on failure: `fail_gateway_refund()` (see Refund Failure Handling) |
| ATM / WebATM / CVS / BARCODE / ApplePay | No — manual via ECPay admin |

### ECPG Embedded (ecpay_ecpg)

1. `before_process_payment()` → `EcpgApiClient::get_token()` (GetTokenbyTrade) → token stored in `_pc_ecpay_ecpg_token`, returns order-received URL. **Every payment attempt gets a new MerchantTradeNo** (`TradeNo::encode_unique()` + `_pc_ecpay_trade_no_history`, shared with AIO): spec says「特店訂單編號均為唯一值，不可重複使用」— stage accepts re-sending GetTokenbyTrade for a number never used in CreatePayment, but a number that went through CreatePayment (card declined / 3DS abandoned) cannot be reused on retry (order-pay / block checkout reuse the same order)
2. Frontend `MountEcpgPayment()` loads ECPay JS SDK, renders embedded card form (container `#ECPayPayment`), customer enters card details → SDK returns PayToken
3. Frontend POSTs PayToken to `/wp-json/power-checkout/ecpay/ecpg/create-payment` (auth: order_key); orders that no longer `needs_payment()` → 400 (no second charge from a stale tab)
4. Backend calls `EcpgApiClient::create_payment()` → if `ThreeDInfo.ThreeDURL` non-empty, returns `three_d_url` → frontend redirects to 3DS; otherwise waits for ReturnURL
5. ECPay sends JSON POST to `/wp-json/power-checkout/ecpay/ecpg/return` — AES-128-CBC decrypted, TransCode + RtnCode double-checked; order lookup matches current trade no **and** history; already-paid/refunded orders are not re-settled (a different paid number only adds a「疑似重複付款」note); success writes the **settled** MerchantTradeNo back to `_pc_ecpay_trade_no` (refund DoAction pairs it with the detail's TradeNo)
6. Refund: credit card only → DoAction via ecpayment domain; non-credit returns `WP_Error('refund_unsupported')`; on failure: `fail_gateway_refund()`

---

## NewebPay MPG Payment Flow (newebpay_mpg)

1. `before_process_payment()` marks `_pc_newebpay_redirect_pending` and returns the order-received URL (no API call)
2. `before_order_received()` auto-submits the MPG form **only** when the redirect is pending (or no MerchantOrderNo was ever sent); each attempt gets a new number via `MpgOrderNo::encode_unique()` (NewebPay rejects resubmitting a used number with `MPG03008`, even for an unpaid ATM code) and appends it to `_pc_newebpay_order_no_history`. Plain revisits (ATM/CVS code issued, payment failed, MPG03008 bounce, refresh) never auto-submit — issue #25 infinite loop
3. thank-you page (`woocommerce_thankyou_newebpay_mpg`): shows ATM account / CVS code / barcode + expiry from `_pc_newebpay_payment_info` (CustomerURL points to our ReturnURL, so NewebPay never shows the code to the customer); pending without a code → 「前往付款」 (order-pay)
4. `TradeInfoCrypto::decrypt()` strips 1–32 PKCS#7 padding manually — NewebPay pads with a **32-byte** block (pad > 16 breaks OpenSSL's default unpad, issue #24)
5. `StatusManager`: paid = `RespondCode=00` **or** (offline type + `PayTime`); code-issued = offline type + not paid → write payment info, keep pending; failure notices on paid / code-issued orders only add a note (never overwrite detail/info or revert status); paid writes the settled MerchantOrderNo back to `_pc_newebpay_order_no` (QueryTradeInfo); callback lookup matches current number **and** history

## Refund Failure Handling (all gateways)

Gateway refunds are sent in `process_gateway_refund()` on `woocommerce_order_refunded` — **after** `wc_create_refund()` has already flipped a full refund to `refunded`. Every gateway's catch block (SLP / AIO / ECPG / NewebPay MPG / PAYUNi UPP / UNi Embed / PayNow) calls `AbstractPaymentGateway::fail_gateway_refund()`:

1. order note `❌ {gateway} 退款失敗：{reason}`
2. delete the WC refund (no money moved → no refund record)
3. full refund: roll the order back to its **pre-refund status** (remembered by `remember_status_before_refund` on `woocommerce_order_status_changed`; fallback `processing`)
4. `record_refund_error()` → `_pc_refund_error` meta (read-once) → REST `/refund` returns it via `consume_refund_error()` (error_code + raw_code + message)

PayNow ATM refund with missing bank fields is treated as a failure (`ErrorCode::VALIDATION`) so the admin can fill them in and retry.

---

## PAYUNi UPP Payment Flow (payuni_upp)

1. `before_process_payment()` writes idempotency key `_pc_payuni_trade_no` (format `PCU{order_id}`) and returns order-received URL — no API call at this stage
2. `before_order_received()` assembles `PayuniRequestParams` (AES-256-GCM `EncryptInfo` + SHA256 `HashInfo`, Version 2.0) and renders auto-submit form → browser POSTs to PAYUNi `/api/upp`
3. PAYUNi sends server-to-server POST to `/wp-json/power-checkout/payuni/upp/notify` (NotifyURL)
4. Callback verification chain: outer `Status=SUCCESS` → `MerID` timing-safe compare → `EncryptInfo`/`HashInfo` present → `HashInfo` `hash_equals` verify → AES-256-GCM decrypt → lookup order by `_pc_payuni_trade_no` → idempotency → `StatusManager`
5. `StatusManager::update_order_status()` maps `TradeStatus`: `1`(paid) → amount guard → `payment_complete()` → processing; `0`(get code) → write `_pc_payuni_payment_info` + pending; `2`/`3`/`8` → pending + order note
6. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support by payment method (determined by PAYUNi `PaymentType` in `_pc_payuni_payment_detail`, not frontend):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=1) | Yes — Close CloseType=2 (`/api/trade/close`); wpdb TRANSACTION + ROLLBACK + `fail_gateway_refund()` on failure |
| ATM / CVS / icash / LINE Pay / JKoPay / etc. | No — `WP_Error('refund_unsupported')`, manual via PAYUNi admin |

Admin order actions (credit card only):

| Action | API |
|---|---|
| 查詢補單 (`pc_payuni_query_trade`) | `/api/trade/query` — if TradeStatus=1 + DataSource=A + not processing → `StatusManager` |
| 請款 (`pc_payuni_capture`) | Close CloseType=1; writes `_pc_payuni_capture_status='captured'` |
| 取消授權 (`pc_payuni_cancel_auth`) | `/api/trade/cancel`; writes `_pc_payuni_capture_status='voided'` |

Encryption: **AES-256-GCM** `hex(base64(cipher):::base64(tag))`; `HashInfo = strtoupper(sha256(HashKey+EncryptInfo+HashIV))`; distinct from ECPay AES-128-CBC and ezPay AES-256-CBC. `PayuniCrypto` (Payment namespace) is a same-source copy of Logistics `PayuniCrypto`; both must stay in sync.

Environment: sandbox `https://sandbox-api.payuni.com.tw/api/upp` / prod `https://api.payuni.com.tw/api/upp`; test mode uses official public test vector keys.

---

## PAYUNi UNi Embed Payment Flow (payuni_uni_embed)

1. `before_process_payment()` writes idempotency key `_pc_payuni_uni_trade_no` (format `PCE{order_id}`) → calls `TokenGetClient::get_token()` (`/api/iframe/token_get` V3.0, inner payload: MerID + Timestamp + IFrameDomain only) → stores SDK token in `_pc_payuni_uni_sdk_token` → returns order-received URL
2. `before_order_received()` localizes SDK token + REST endpoint to page; frontend `MountPayuniUniEmbed()` loads PAYUNi iframe SDK, renders embedded card form, customer submits card details → SDK returns buyer credential
3. Frontend POSTs buyer credential to `/wp-json/power-checkout/payuni/uni-embed/create-payment` (auth: `order_key` `hash_equals`)
4. Backend calls `MerchantTradeClient::merchant_trade()` (`/api/iframe/merchant_trade` V1.0, TradeAmt computed server-side) → if `ThreeDURL` non-empty, returns `three_d_url` → frontend redirects to 3DS; stores `credit_hash` + `credit_life` (buyer token, never card number/CVC)
5. PAYUNi sends server-to-server POST to `/wp-json/power-checkout/payuni/uni-embed/notify` (NotifyURL)
6. Callback verification chain: outer `Status=SUCCESS` → `MerID` timing-safe compare → `EncryptInfo`/`HashInfo` present → `HashInfo` `hash_equals` verify → AES-256-GCM decrypt → lookup order by `_pc_payuni_uni_trade_no` → idempotency → `StatusManager`
7. `StatusManager::update_order_status()` maps `TradeStatus`: `1`(paid) → amount guard + Gateway=9 guard → `payment_complete()` → processing; `2`/`3`/`8` → pending + order note
8. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support (determined by `PaymentType` in `_pc_payuni_uni_payment_detail`):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=1) | Yes — Close CloseType=2 (`/api/trade/close`); wpdb TRANSACTION + ROLLBACK + `fail_gateway_refund()` on failure |
| Others | No — `WP_Error('refund_unsupported')`, manual via PAYUNi admin |

Admin order actions (credit card only):

| Action | API |
|---|---|
| 查詢補單 (`pc_payuni_uni_query_trade`) | `/api/trade/query` — if TradeStatus=1 + DataSource=A + not processing → `StatusManager` |
| 請款 (`pc_payuni_uni_capture`) | Close CloseType=1; writes `_pc_payuni_uni_capture_status='captured'` |
| 取消授權 (`pc_payuni_uni_cancel_auth`) | `/api/trade/cancel`; writes `_pc_payuni_uni_capture_status='voided'` |

Encryption: same **AES-256-GCM** as UPP (`PayuniCrypto` shared, no third copy); environment: sandbox `https://sandbox-api.payuni.com.tw` / prod `https://api.payuni.com.tw`.

---

## PayNow Payment Flow (paynow)

1. `before_process_payment()` writes idempotency keys `_pc_paynow_trade_no` (format `PCN{order_id}`) + calls `PaynowRestClient::create_payment_intent()` (Bearer PrivateKey, POST `/payment-intents`) → stores `_pc_paynow_payment_intent_id` (`pp_xxx`) and `_pc_paynow_secret` (SDK secret `pp_xxx_st_xxx`) → returns order-received URL; idempotency: if `_pc_paynow_payment_intent_id` already exists, skips API call and returns URL directly
2. `before_order_received()` localizes `public_key`/`secret`/`env`/`order_received_url` to page; frontend `MountPaynowPayment()` loads PayNow Component SDK v2 (CDN `https://js.paynow.com.tw/sdk/v2/index.js`), renders embedded iframe, customer submits payment → SDK `checkout()` directly completes authorization + 3DS with PayNow servers — **no `create-payment` POST to backend**; on SDK success, frontend redirects to order-received; payment result is determined by Webhook
3. PayNow sends server-to-server POST to `/wp-json/power-checkout/paynow/notify` (NotifyURL)
4. Callback verification chain: take `$request->get_body()` raw + `X-Payment-Center-Hmac-Sha256` header → `WebhookVerifier::verify()` (HMAC-SHA256, key=PrivateKey, `strtoupper` + `hash_equals`, against raw body — never re-encode) → decode JSON → lookup order by `_pc_paynow_payment_intent_id` (`get_order_by_payment_intent_id`) → amount guard → idempotency → `StatusManager`
5. `StatusManager::update_order_status()` maps `Status`: `Success` → amount guard (`ctype_digit` + `ceil` compare) → idempotency (already processing → skip) → by `PaymentType`: instant (Credit/Installment/LINEPay/ApplePay) → `payment_complete()` → processing + write `_pc_paynow_payment_detail`; offline pending phase (ATM/ConvenienceStore) → write `_pc_paynow_payment_info` + maintain pending; offline `Success` → `payment_complete()` → processing; `Failed` → maintain pending + order note
6. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support (determined by `PaymentType` in `_pc_paynow_payment_detail`, not frontend):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=Credit/CreditCardInstallment) | Yes — REST `POST /payment-intents/:id/refunds`; wpdb TRANSACTION + ROLLBACK + `fail_gateway_refund()` on failure; writes `_pc_paynow_refund_detail` |
| ATM | Yes — REST `POST /payment-intents/:id/refunds` (requires bankCode/bankBranchCode/bankAccount); missing bank fields rejected before API call |
| ConvenienceStore / LINE Pay / Apple Pay | No — `WP_Error('refund_unsupported')`, manual via PayNow admin |

Admin order actions:

| Action | API |
|---|---|
| 補查付款意圖 (`pc_paynow_query_trade`) | `GET /payment-intents/:id` — if status=success + not processing → `StatusManager`補單 (amount guard + idempotency) |
| 退款查詢 (`pc_paynow_refund_query`) | `GET /payment-intents/:id/refunds/:uuid` — writes back `_pc_paynow_refund_detail` + order note |

Encryption/auth: **HMAC-SHA256** only; `WebhookVerifier` is standalone (does not reuse `PayuniCrypto` — PayNow 體系 1 has no symmetric encryption); API auth is **Bearer PrivateKey** (not AES envelope). Environment: sandbox `https://sandboxapi.paynow.com.tw` / prod `https://api.paynow.com.tw`.

[GAP: sandbox PublicKey/PrivateKey not yet applied for (contact PayNow with subject「申請 PayNow 串接私鑰 (PrivateKey)」); sandbox end-to-end pending]

---

## Tcat Pay Payment Flow (tcat_pay)

1. Checkout sub-method (`pc_tcat_pay_method`: `credit` / `cvs_ibon` / `atm`; classic `payment_fields()` + Blocks `TcatPayBlocksIntegration` `onPaymentSetup`) validated against enabled list (whitelist) and per-method amount limit (`TcatPayAmountLimits`: defaults credit 100000 / ibon 20000 / ATM 49999, per-site override) in `validate_fields()` and again in `before_process_payment()`. Sub-method fixes the CVS acquirer combination (ibon = payment_type 0 + acquirer 2; ATM = 1 + 3) so platform-rejected combos cannot be built
2. `before_process_payment()`: new `cust_order_no` per attempt (`PCT{order_id}-{5 chars}`, ≤25; platform keeps it unique forever → retry must use a new number; history kept in `_pc_tcat_pay_trade_no_history` for APN lookup) + charge-amount snapshot → `CocsOrderAppend` → redirect to returned card URL; or `CvsOrderAppend` → store `_pc_tcat_pay_payment_info` → order-received shows code/account, order stays pending
3. **Payment result = platform query only (R1)**: APN has a checksum but no secret, so it is a *trigger*. `settle_paid()` runs only when `CocsOrderQuery` / `CvsOrderQuery` reports paid `process_code` (explicit lists: CVS 4/7/8, COCS 15/20/21/22 — never `>=`) **and** platform amount (CVS `pay_amount`, COCS `order_amount`) equals the snapshot (and notified amount if present); mismatch → note only
4. Three confirmation paths: (a) APN (`handle_webhook` frame): checksum → order by `order_no` (current + history) → only paid-trigger statuses (CVS B/E; COCS B/O/E) query the platform (10s per-order throttle); non-paid statuses only leave an order note → ack plain-text `OK`; query failure / throttled → `TcatPayRetryLaterException` → 503 `RETRY` so the platform resends in 15 min; (b) COCS browser return (`chk` hash_base + `cust_order_no` owned by order → query); (c) admin action `pc_tcat_pay_query_trade`
5. Idempotency: framework key disabled (`''`); `_pc_tcat_pay_processed_status` (`"{trade_no}:{status}"`) written only when actually processed, so a forged APN cannot pre-poison it. Overdue CVS is never auto-cancelled
6. Refund (credit only; CVS/ATM → `UNSUPPORTED`, no API): by platform `process_code` — `>=20` (captured) → `CocsOrderRefund` (partial OK); `15` (authorized, uncaptured) → `CocsOrderCancel` (**full amount only**); other → `UNSUPPORTED`. Failure → `fail_gateway_refund()`. `capture()` = `CocsCashRequest`, `void_auth()` = `CocsOrderCancel` (methods exist for capability declaration; only the query admin action is wired to the order screen). No continuous/recurring collection
7. Environments: sandbox `https://test.4128888card.com.tw/app` / prod `https://cocs.4128888card.com.tw`; test mode `mode=test`

[GAP: no 黑貓PAY test credentials. Live-verified only the failure path (placeholder credentials → token error note, checkout error shown, no leak to customer); credit/CVS/ATM create, APN, refund, capture, void unverified live — covered by mock tests]

---

## LINE Pay Direct Payment Flow (linepay)

1. `process_payment` → `POST /v4/payments/request` (amount from the order on the backend; `orderId` = `PCL{order_id}-{attempt}-{6 hex}`; `confirmUrl` / `cancelUrl` = REST `power-checkout/linepay/{confirm|cancel}?order_id=&key=`; param names deliberately avoid `orderId` / `transactionId` because LINE Pay appends those) → store `_pc_linepay_transaction_id` (19-digit **string**), amount snapshot, request time → schedule AS `pc_linepay_reconcile` → redirect `info.paymentUrl.web`
2. Customer authenticates → browser GET confirmUrl → `handle_webhook()` frame: `verify_callback` (order exists + `order_key` `hash_equals` + `transactionId` and `orderId` both `hash_equals` to meta; cancel path: those two optional but must match when present) → `StatusManager::confirm()` → 302 to order-received (always 302, also on failure)
3. Settle rules: confirm `0000` → `settle()` guards `orderId` + `payInfo` amount equal to snapshot, then `payment_complete(transactionId)`; `1172` / `1198` (already confirmed) → query payment details and settle only when `transactionType=PAYMENT` **and** `payStatus=CAPTURE`; `1180` → expired note; others → failure note; already paid / refunded / cancelled orders are never re-settled and trigger no API call. Framework idempotency key disabled (GET has no body; a refresh after a timeout must still be able to confirm)
4. **No webhook**: if the customer authenticates then closes the browser, confirm is never called → `LinepayReconcileJob` (AS hook `pc_linepay_reconcile`, group `power_checkout`, delay default 300s, filter `power_checkout_linepay_reconcile_delay`, floor 60s). It **checks request status first** (never use confirm as a probe: confirming an unauthenticated request returns `1169` and voids the whole request): `0110` authenticated → confirm; `0123` completed → details → settle; `0000` not yet authenticated → re-schedule (`pending_round`+1, up to `MAX_PENDING_ROUNDS` 10 or the 1200s request expiry); `0121`/`0122` → note only, no status change, no reschedule; query failure → one retry at +600s, then give up with a note. No-op unless order is LINE Pay, still pending/failed/on-hold, and has a transactionId
5. Refund: `/v4/payments/{id}/refund` with explicit `refundAmount` (partial OK); no capture/void (auto-capture). Admin action `pc_linepay_query_trade` runs the same reconcile logic manually (non-settling states leave a note)
6. Environments: sandbox `https://sandbox-api-pay.line.me` / prod `https://api-pay.line.me`; HMAC-SHA256 request signing with Channel Secret

[GAP: real-person payment segment (customer login, authentication, confirm → processing → refund) not recorded live — requires a real LINE sandbox account (spec supports `LIVE_LINEPAY_MANUAL=1` pause); live-verified only: request success → redirect to the sandbox pay page. Mock tests cover confirm/reconcile/refund]

---

## ECPay Logistics Flow (ecpay_logistics)

### Three-phase store selection (convenience store)

1. Frontend calls `POST /logistics/{order_id}/store-selection` with `sub_type` + `payment_scenario` → `get_store_selection()` builds `RedirectToLogisticsSelection` (AES-encrypted), returns `redirect_target` HTML → browser renders RWD store picker
2. Customer selects store → ECPay POSTs `ResultData` to `/wp-json/power-checkout/ecpay/logistics/selection-callback` (ClientReplyURL) → `parse_store_selection()` decodes `ResultData`, writes `_pc_logistics_temp_id` + store meta to order
3. Admin calls `POST /logistics/{order_id}/create-shipment` → `create_shipment()` calls `CreateByTempTrade` using `TempLogisticsID` → writes `_pc_logistics_ref` (LogisticsID), returns `logistics_id`

### Home delivery (HOME)

Same store-selection step is skipped; `create_shipment()` directly calls `CreateByTempTrade` with address data.

### Status callback (ServerReplyURL — AES-JSON 3-layer)

ECPay POSTs JSON to `/wp-json/power-checkout/ecpay/logistics/status-callback`:
- Response **must** be HTTP 200 + AES-JSON `{ MerchantID, RqHeader{ Timestamp, Revision:"1.0.0" }, TransCode, Data }` where `Data = AES({"RtnCode":1|0,"RtnMsg":...})` — returning plain `1|OK` causes ECPay to retry every 60 min
- Safety: verify MerchantID → lookup order by `_pc_logistics_ref` → idempotency check via `_pc_logistics_processed_status`
- COD: `LogisticsStatus` pickup-complete sets `_pc_logistics_collection_paid = yes`
- Any `\Throwable` is caught; still returns AES-JSON with `RtnCode=0`

### Account types

| Account Type | MerchantID | Supported sub-types |
|---|---|---|
| B2C | 2000132 | FAMI, UNIMART, HILIFE, HOME |
| C2C | 2000933 | FAMI, UNIMART, HILIFE |

C2C only: `cancel_shipment()` (C2C cancel), `_pc_logistics_cvs_payment_no` / `_pc_logistics_cvs_validation_no`.

### Returns / reverse logistics (P2-B — `create_return`)

`ILogisticsProvider::create_return()` builds a reverse-logistics order from an already-shipped order. Preconditions: provider enabled → order exists → has `_pc_logistics_ref` (forward shipment created) → `server_reply_url` is public. Dispatches by the original `_pc_logistics_sub_type`:

| Original sub-type | Return endpoint | Key Data fields |
|---|---|---|
| FAMI | `/Express/v2/ReturnCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| UNIMART | `/Express/v2/ReturnUniMartCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| HILIFE | `/Express/v2/ReturnHilifeCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| HOME | `/Express/v2/ReturnHome` | `Temperature`, `Distance`, `Specification` |

All carry `LogisticsID` (original), `GoodsAmount`, `ServerReplyURL` (→ status-callback). On success writes `_pc_logistics_return_ref` (ReturnLogisticsID) + order note. Reverse-logistics status notifications reuse the existing AES-JSON status-callback; `get_order_by_ref()` looks up by both `_pc_logistics_ref` and `_pc_logistics_return_ref`. REST: `POST /logistics/{id}/return`.

PAYUNi logistics and block checkout are deferred.

---

## PayNow Logistics Flow (paynow_logistics)

### Store selection (convenience store — SEVEN/FAMI/HILIFE)

1. Frontend calls `POST /logistics/{order_id}/store-selection` with `sub_type` + `payment_scenario` → `get_store_selection()` encrypts `apicode` (TripleDES ECB + space→plus), builds form-POST to `{api_url}/Member/Order/Choselogistics`, returns `redirect_target` HTML
2. Customer selects store → PayNow POSTs store data to `/wp-json/power-checkout/paynow/logistics/selection-callback` (returnUrl, carries `orderid` + `cid`=order_key) → `parse_store_selection()` requires both and `hash_equals(order_key, cid)`, then writes `_pc_paynow_logistics_store_*` + `_pc_paynow_logistics_order_no` meta
3. Admin calls `POST /logistics/{order_id}/create-shipment` → validates amount limit (CVS ≤20000 / home ≤100000) → if existing valid shipment (status≠1) → ReNewOrder; otherwise Add_Order (`JsonOrder=base64(TripleDES(JSON))`) → writes `_pc_paynow_logistics_ref` (LogisticNumber) + paymentno + validationno

### Home delivery (TCAT)

Store-selection step skipped; `create_shipment()` calls Add_Order directly with delivery address.

### Encryption (R2 — DES-EDE3 ECB, not CBC)

Two distinct modes; **not interchangeable**:
- `encrypt_order_json()` — `DES-EDE3` without `-CBC` suffix (OpenSSL defaults to ECB when no IV given), `OPENSSL_NO_PADDING` + manual `\0` zero-pad to 8-byte boundary + base64
- `encrypt_apicode()` — `DES-EDE3-ECB`, `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING` + manual `\0` pad + base64 + `str_replace(' ', '+', ...)`
- Fixed key=`123456789070828783123456` / iv=`12345678`; [GAP: prod換鑰待 PayNow 官方確認]

### Status callback (貨態推送 — always HTTP 200)

PayNow POSTs to `/wp-json/power-checkout/paynow/logistics/status-callback` (Form POST, `permission_callback: __return_true`):
- **Push is a trigger only — its content is never trusted** (PayNow logistics push has no signature; anyone can forge it). Only `orderno` is read
- Lookup order by `_pc_paynow_logistics_order_no` (OrderNo = `$order->get_order_number()`); no `_pc_paynow_logistics_ref` (shipment not created) → ignore
- Throttle: transient `pc_paynow_logistics_sync_{order_id}`, one query per order per 10s (forged pushes can't flood PayNow API)
- Source of truth: `query_shipment()` (GET /api/Orderapi/Get_Order_Info, authenticated with merchant apicode) writes status / delivery_status / logistic_code; COD + **queried** pickup-complete (8000 / 取貨完成) → `_pc_paynow_logistics_collection_paid=yes`
- Idempotency (order note dedup only): `"{OrderNo}:{queried LogisticCode}"` in `_pc_paynow_logistics_processed_status` — keyed on the queried code, never the pushed one (a forged push must not be able to pre-mark a code)
- All paths (including `\Throwable`) always return HTTP 200

### Operations

| Operation | API | Notes |
|---|---|---|
| 建單 (`create_shipment`) | POST Add_Order | idempotent ReNewOrder if valid shipment exists |
| 查詢 (`query_shipment`) | GET Get_Order_Info | supplemental reconciliation |
| 列印 (`print_document`) | SEVEN: GET /api/Order711; TCAT: POST PrintBlackCatLabel | RenewOrderNo preferred |
| 取消 (`cancel_shipment`) | DELETE CancelOrder | not C2C-only (unlike ECPay) |
| 逆物流 (`create_return`) | throw `\Exception('尚未實作')` | [GAP: no woomp evidence] |

[GAP: sandbox user_account/apicode not yet applied; prod key/IV to confirm with PayNow; official logistics API document pending verification]

---

## Direct Logistics Providers (tcat_logistics / hct_logistics / sf_express / kerrytj_logistics)

Shared traits of the 4 new providers: HOME delivery only (`has_cvs_map=false`; `get_store_selection` / `parse_store_selection` → `UNSUPPORTED`, REST layer returns 501 from the capability check); each has its own meta prefix `_pc_{provider_id}_*` (never `_pc_logistics_*`); each registers a `WC_*Shipping` classic shipping method (`METHOD_ID` = provider id) and writes checkout meta only when its method is the chosen one; every failure `throw`s a provider exception carrying a normalized `ErrorCode` + raw provider code (create/cancel/return failures also leave an order note); `query_shipment()` returns raw status + `unified_state` (`ShippingState`); no background polling (ASM-7); `test_connection()` is read-only. Capability matrix:

| Provider | create | query | print | cancel | return | status push |
|---|---|---|---|---|---|---|
| `tcat_logistics` | PrintOBT | OBTStatus | PDF (DownloadOBT) | no | ReserveAdd | none (callback = 200 no-op) |
| `hct_logistics` | TransData_Json | searchGoods | stored label image → HTML | yes | R_TransData_Json | none |
| `sf_express` | IUOP_CREATE_ORDER | IUOP_QUERY_TRACK | PDF (server-side fetch) | yes (pre-pickup) | no | signed `track-push` |
| `kerrytj_logistics` | ShipmentDelivery | BLNListTracing / ColdBLNListTracing | label PDF | no | PickupV2 | none |

### tcat_logistics (統一速達 黑貓宅急便標準印單 API)
- `create_shipment`: system allocates the waybill; existing `_pc_tcat_logistics_ref` → idempotent return (no re-allocation); stores FileNo (valid 24h → `print_document` DownloadOBT, `application/pdf` via REST `%PDF` detection)
- `query_shipment`: same waybill may be queried only once per 2h (`E087`) → 2h transient cache (`pc_tcat_logistics_status_*`, response `cached=true`); `E077` (not collected yet) → pending; raw `StatusId` → `ShippingState`
- `create_return` = ReserveAdd: recipient = store (sender settings), sender = customer; TakeDate D+1..D+7, 16:30 cutoff. Fields validated by `TcatFieldRules`; no 7-11 B2S / map (needs 黑貓PAY token)
- `test_connection` uses ParsingAddress (read-only) which **does not validate customer id / token**; response carries `credentials_verified:false` and the settings page tells the admin so
- Environments: test `https://egs.suda.com.tw:8443/api/Egs` / prod `https://api.suda.com.tw/api/Egs`
- [GAP: no test customer token → create / print / return live-unverified (mock tests only)]

### hct_logistics (新竹物流 EDI WebServices V15, SOAP)
- `HctSoapClient` hand-builds SOAP 1.1 (`.asmx`; JSON string in `<json>`, params company / password / json; SOAPAction without trailing slash); do not use `\SoapClient`
- `create_shipment`: if waybill range configured → `WaybillNumberAllocator` (key `hct_logistics`, `Mod7CheckDigitFormat`) allocates `edelno` atomically, else HCT assigns; ref/arrival station/label image (hex → base64) stored; existing ref → idempotent; numbers never reclaimed. Range options synced on settings save (`update_option_woocommerce_hct_logistics_settings`, registered even when disabled)
- `print_document` returns the **stored** label (re-calling TransData would be an "amend" write); `query_shipment` degrades to `pending` (no API call) when track parameters are not set, else searchGoods (DES-CBC via `HctTrackCrypto`), 10-min cache (HCT maintenance window 03:40–04:30 daily)
- Environments: test `https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx` / prod `.../edi_webservice2/service1.asmx`; `test_connection` = QueryEDELNO_Json on a non-existent order number
- [GAP: no HCT test account / test range. The credentials in HCT's official document are actually **production** ones (test endpoint rejects them with "請勿使用正式公司名稱或密碼"). Live-verified only read-only QueryEDELNO on prod; create / cancel / return / track unverified]

### sf_express (順豐 IUOP)
- Auth: token via `SfAccessTokenStore` (cached); dispatch payload AES-256-CBC with self-implemented PKCS7 **block=32** (key from `encoding_aes_key`, IV = key[0:16], ciphertext tail must equal appKey else treated as tampering) + sha256 signature; push `sign` is a separate scheme (`SfCrypto`); environments SIT `https://api-ifsp-sit.sf.global` / prod `https://api-ifsp.sf.global`
- `create_shipment`: `ref` = `sfWaybillNo` (push lookup key); existing ref → idempotent; error `123052` (duplicate customer order no, possibly a timed-out earlier success) → `IUOP_QUERY_ORDER`, adopt only if the **recipient mobile matches** (fail-closed). `print_document` fetches the PDF server-side (label URL is unauthenticated and contains PII → never stored, never sent to the browser)
- `cancel_shipment`: IUOP_CANCEL_ORDER only before SF accepts; locally known accepted-or-later state → `CONFLICT` without an API call; success clears ref (SF renames the cancelled order `{no}_CANCEL_{ts}`, so re-create is possible); kept in `_pc_sf_express_cancelled_ref`
- **Track push** `POST power-checkout/sf-express/track-push` (registered unconditionally so in-flight parcels keep updating after the provider is switched off): no push key set → whole batch rejected (fail-closed); verify `sign` on the **raw body** before parsing anything; events applied in event-time order (older "in transit" must not overwrite newer "signed"); per-event dedupe by `trackUniqueId` (fallback `{sfWaybillNo}:{opCode}:{localTm}`, 50 kept); unknown order/opCode/missing waybill → per-event `0` (resending cannot change it); our transient `\Throwable` → per-event `1` (SF resends); HTTP always 200 with SF-format ack
- `test_connection`: token + encryption + signature + `IUOP_QUERY_TRACK` on a non-existent waybill (read-only), returns `customer_code_verified:false`, `push_key_configured`
- Settings page warns **TW→TW production eligibility / product code must be confirmed with SF Taiwan account manager** (R12) and shows the push URL to hand to SF
- Live status: fully verified on the public SIT sandbox (credentials are the official public test set, parsed at run time, never copied into the repo). [GAP: production TW→TW eligibility; push needs SF-side enablement + SF push IP allowlist; SIT test waybills cannot be cancelled once pushed]

### kerrytj_logistics (嘉里大榮 EDIEntrance)
- Auth: HTTP Basic (`username` / `password` / `customer_no`); test `https://api.kerrytj.com/EDIEntranceTest` / prod `.../EDIEntrance`; tracing `https://KerryTracingEDI.kerrytj.com/api/Tracing`
- Waybill (BLN) ranges: ambient 11-digit / cold 9-digit serial or 10-digit with check digit (`KerrytjBln`; validated on save with specific messages); allocator key `kerrytj_logistics_{mode}_{ambient|cold}` (test machine progress does not eat the prod range); ranges synced on settings save (registered even when disabled)
- `create_shipment`: temperature (checkout meta → setting default) selects the range; the allocated BLN is **stored on the order as pending BEFORE the API call** so a retry reuses it; a "duplicate waybill" reply for a reused pending BLN is treated as success (the earlier, probably timed-out call had succeeded); stores ref, 34-field snapshot (basis for label/update), station
- Kerry-specific: `update_shipment` (UpdateShipmentDelivery; same BLN/date/temperature rebuilt from current order address; refused with `CONFLICT` once status is no longer pending) and `print_detail` (GetDetailPdf) via admin REST `logistics/{id}/kerrytj/update|detail`; return = PickupV2 (ambient range, same pending-BLN reuse)
- No cancel API (spec and Help Page have none), **no COD** (wrong collection amount = over/under-charging the customer). `test_connection` = UpdateShipmentDelivery with an empty array (validates Basic Auth, writes nothing)
- [GAP: no test account / BLN ranges from Kerry → create / label / pickup / track live-unverified; live-verified only range validation, range remaining-count display, and AUTH-failure ping (424 `AUTH` / raw `401`)]

---

## ezPay Invoice Flow (ezpay)

1. `issue()` → `InvoiceApiClient::issue()` → POST `invoice_issue` v1.5 (AES-256-CBC encrypted + CheckCode verified) → `Status=1` means immediate issuance → writes `pc_issued_data` (includes `invoice_trans_no` + `random_num`)
2. `cancel()` → POST `invoice_invalid` v1.0 → writes `pc_cancelled_data`
3. **Allowance (open)**: triggered by WC refund hook → `allowance()` → POST `allowance_issue` v1.3 → writes `allowance_data` (includes `allowance_no`) to `pc_issued_data`
4. **Allowance (void)**: `allowance_invalid()` → POST `allowanceInvalid` v1.0
5. **Query**: `query()` → POST `invoice_search` v1.3 → `UploadStatus` indicates upload to Ministry of Finance
6. Encryption differs from ECPay: **AES-256-CBC** with PKCS#7 blocksize=32 + `OPENSSL_ZERO_PADDING` + `bin2hex` lowercase; **not** interchangeable with ECPay's AES-128-CBC + base64
7. CheckCode: SHA256 of 5 fields ksort + HashIV/HashKey wrap → uppercase → `hash_equals` comparison

---

## PayNow Invoice Flow (paynow_invoice)

Provider ID `paynow_invoice` (R5: distinct from payment gateway `paynow`); WC option `woocommerce_paynow_invoice_settings` (no collision with `woocommerce_paynow_settings`). Parallel with Amego / `ecpay` / `ezpay`, switchable from admin.

1. `issue()` → `InvoiceApiClient::issue()` → POST `/api/invoices/issue` (Bearer JWT-Token, JSON body) → `type==='success'` → client writes `pc_issued_data` (`invoice_number`, `invoice_date`, `order_no`, `total_amount`) + `pc_provider_id='paynow_invoice'` meta
2. `cancel()` → POST `/api/invoices/cancel` (带 `invoice_number`) → success → provider writes `pc_cancelled_data` + clears `pc_issued_data`; **client does not write meta for cancel** (meta responsibility split differs from ezPay)
3. **Allowance (open)**: triggered by WC refund hook → `issue_allowance()` → POST `/api/invoices/allowance` → writes `allowance_data` (`allowance_number`, `allowance_amount`, `invoice_number`, `remain_amount`) to order meta; key `allowance_number` (not ezPay's `allowance_no`)
4. **Allowance (void)**: `invalid_allowance()` → POST `/api/invoices/cancel-allowance` (带 `allowance_number`) → success → clears `allowance_data`; guard: existing allowance required before cancel
5. **Query**: `query_invoice()` → GET `/api/invoices?InvoiceNumber=...` (Bearer) → read-only, no meta/status changes
6. Auth: **Bearer JWT-Token** only — no AES envelope, no CheckCode; `Authorization: Bearer {jwt_token}` + `Content-Type: application/json`
7. Full refund → `cancel()` (void invoice, not allowance); partial refund → `issue_allowance()` (triggered by `woocommerce_order_refunded` hook via provider-agnostic layer)

Tax rules (IssueParams):
- B2C: `tax_amount=0` (government calculates); B2B (has buyer identifier): `tax_amount` = actual tax
- `tax_type=ZeroTax` requires `is_pass_customs` + `zero_tax_rate_reason` (e.g. `ExportGoods`)
- Carrier and donation are mutually exclusive (throw on conflict)

Environment: dev `https://invoiceapi-dev.paynow.com.tw` / prod `https://invoiceapi-prod.paynow.com.tw`

[GAP: sandbox jwt_token not yet applied; sandbox end-to-end pending]

---

## Checkout Enhancement Flow (checkout_enhancement)

Domain `Domains/Checkout/`; virtual provider id `checkout_enhancement` (no gateway class, not in `ProviderUtils::$container`); option `woocommerce_checkout_enhancement_settings`; admin page = Vue route `/checkout` (sidebar「結帳」). All hooks are registered unconditionally by `Checkout\ProviderRegister`; each callback checks `CheckoutEnhancementSettingsDTO::is_on($key)` at runtime and is never-throw (`\Throwable` → `Plugin::logger()`, return original value).

### Settings REST (special case in `SettingApiService`)

- Reuses `GET/POST /power-checkout/v1/settings/{provider_id}`; branch detected by `is_checkout_enhancement_route()`. **All** `/settings/{provider_id}` callbacks (GET / POST / toggle) resolve the target via `route_provider_id()` = URL params only (`get_url_params()`), never `$request['provider_id']` (JSON body / query string outrank URL params and could redirect writes to another `woocommerce_{x}_settings`); POST also drops `provider_id` from the saved params
- GET: `get_all()` = `DEFAULTS` merged with stored values (corrupt/missing option → all defaults)
- POST: `merge_valid()` validates per key — toggles `yes`/`no`, colors `#RRGGBB`, texts non-empty after sanitize (`free_shipping_text_left` must contain `{price}`); an invalid key keeps its stored value, valid keys are still saved; unknown keys and `provider_id` are never written
- DTO deliberately does not extend wp-utils `DTO`/`BaseSettingsDTO` (required_properties typo issue)

### Classic vs Blocks support

| Feature (toggle) | Classic | Blocks |
|---|---|---|
| County dropdown (`tw_address`) | Yes (`woocommerce_states` TW) | Yes (same filter) |
| District dropdown + postcode auto-fill (`tw_address`) | Yes (`classic-tw-address.js`, jQuery) | **No** |
| Last name first (`last_name_first`) | Yes | Yes (TW locale priority) |
| Mobile format check (`tw_phone_validation`) | Yes | Yes (server-side) |
| Hide address for all-virtual cart (`virtual_hide_address`) | Yes | Yes (evaluated at page load; removing physical items mid-page does not re-hide) |
| Auto-complete virtual orders (`virtual_auto_complete`) | Order status hook, checkout-agnostic | Same |
| Free-shipping hint (`free_shipping_hint`) | Yes (cart totals + review-order table row) | Yes (Store API extension + slot fill) |

Existing TW display format (`DefaultSetting::modify_tw_address_formats`) stays always-on; there is no toggle for it. Name order / hidden fields apply only when country = TW (D3); "virtual" = **all** items `is_virtual()` (mixed cart = physical).

### Virtual cart address hiding (S2 / D15)

- Classic: `woocommerce_checkout_fields` (p1000) unsets billing `country/state/city/postcode/address_1/address_2`
- Blocks: `woocommerce_get_country_locale` (p1000) sets `hidden=true, required=false` on `state/city/postcode/address_1/address_2` **under the explicit `TW` key** — WC's default locale array has no TW (looping existing keys does nothing) and the `default` key is overwritten by WC after the filter
- Blocks **country must not be `hidden` in locale**: it empties the country value, un-hides the other fields and blocks the order. Hidden by CSS instead (`print_block_country_css` on `wp_head`: only on block checkout + toggle on + all-virtual cart); country stays in the DOM with value TW
- Locale cache: `WC()->countries->locale` is cached per request, so `reset_country_locale()` runs at `woocommerce_cart_loaded_from_session` (p1) and before Store API order validation (`woocommerce_store_api_checkout_update_order_from_request` p1) so the cart-dependent filter is re-evaluated

### Phone validation

- Rule: strip spaces/hyphens, then `^09\d{8}$`; input value is never rewritten; empty values skipped (required is WC's job); only when country (shipping, else billing, else store base country) = TW
- Classic: `woocommerce_after_checkout_validation` adds to `$errors` (all invalid fields reported); shipping phone read from `$_POST['shipping_phone']` **only when `ship_to_different_address`** is checked (WC classic has no shipping_phone in posted data)
- Blocks: `woocommerce_store_api_checkout_update_order_from_request` throws `RouteException` (HTTP 400, code `power_checkout_invalid_phone`) — only the **first** invalid field is reported

### Virtual order auto-complete (R1)

- `woocommerce_order_status_changed` (p20): `to === processing` + toggle on → order id queued (de-duplicated by key), `shutdown` hook added once; any path to processing triggers (webhook, admin manual change)
- `flush_queue()` at `shutdown`: re-reads the order and completes only if **still processing** and all items virtual (and filter `power_checkout_virtual_auto_complete_should_complete` allows) → `completed`
- Why deferred: PAYUNi / PayNow / NewebPay call `payment_complete()` then `update_status('processing')`, firing the hook twice; completing inline would send two emails. The 4 invoice providers already skip when an invoice exists, so only one invoice is issued

### Free-shipping hint

- `FreeShippingCalculator` follows WC `free_shipping` method semantics: reads the enabled `free_shipping` methods of the first package's shipping zone; `min_amount` read from the raw option (empty/non-numeric → not a candidate); base = displayed subtotal minus discount (+ discount tax when prices include tax) **only if `ignore_discounts` ≠ yes**, rounded to store decimals; `requires=coupon` skipped, `both` only with a free-shipping coupon, `either` with coupon = achieved; multiple candidates → smallest positive diff; null when cart empty / no shipping / no candidate
- Classic: `woocommerce_cart_totals_before_shipping` + `woocommerce_review_order_before_shipping` print a `<tr class="pc-free-shipping-hint">` (colors from settings, `{price}` replaced)
- Blocks: Store API cart extension, namespace `power-checkout`, key `free_shipping_hint` (`null` or `{achieved, diff, text, bg_color, text_color}`), registered on `woocommerce_blocks_loaded`; frontend `inc/assets/blocks/pc_free_shipping_hint.tsx` renders via `registerPlugin` + `ExperimentalOrderMeta` slot fill (covers both Cart and Checkout blocks; text is `decodeEntities`-ed, no `dangerouslySetInnerHTML`)
- Block script enqueued on `wp_enqueue_scripts` p20 only when toggle on + page has cart/checkout block + build file exists; must be registered as an ES module via `Plugin::instance()->add_module_handle($handle)`

---

## Order Meta Keys

| Key | Purpose |
|---|---|
| `_pc_identity` | Third-party session identifier — SLP `sessionId`; written by `before_process_payment()` before the EXPIRED check; also the order-ownership key for Session/SDK payments in return sync + webhook (issue #19) |
| `_pc_payment_identity` | tradeOrderId (idempotency guard) — SLP; written **only after** `order_key` + `sessionId`/`referenceOrderId` verification (return sync) or by `OrderResolver` back-fill (webhook) |
| `_pc_payment_detail` | Payment details (admin display) — SLP |
| `_pc_refund_detail` | Refund details — SLP |
| `_pc_payment_processed_status` | Idempotency guard array — elements: `"{tradeOrderId}:{status}"`; shared by return sync and webhook — SLP |
| `_pc_slp_reference_history` | SLP embedded: every `referenceOrderId` sent to `trade/payment/create` (format `{order_id}-{attempt}-{6 hex}`, written **before** the API call; SLP rejects duplicates with 1001). A new-format reference belongs to an order **only** if it is in that order's history (webhook `OrderResolver`, `EmbeddedSyncManager`, redirect return sync). Previous-attempt notices: SUCCEEDED settles only while the order still needs payment (identity re-pointed to the settled trade), otherwise note only (疑似重複付款 / 非目前交易) |
| `pc_issued_data` | Invoice issuance response (ezPay: includes `invoice_trans_no` + `random_num`; allowance_data includes `allowance_no`) |
| `pc_cancelled_data` | Invoice void response |
| `pc_provider_id` | Which invoice provider was used |
| `pc_issue_params` | Checkout-submitted invoice info |
| `_pc_tax_type` | Product tax type (for invoicing) |
| `_pc_ecpay_trade_no` | ECPay MerchantTradeNo — AIO / ECPG: latest attempt, overwritten with the settled attempt on payment success |
| `_pc_ecpay_trade_no_history` | AIO / ECPG: every MerchantTradeNo ever sent for the order (callback lookup fallback; retry payments generate new numbers) |
| `_pc_ecpay_redirect_pending` | AIO: `process_payment` ran and the order-received page should auto-redirect to ECPay once |
| `_pc_ecpay_payment_detail` | ECPay payment result detail (ReturnURL / CreatePayment response) |
| `_pc_ecpay_payment_info` | ATM/CVS/BARCODE payment info (BankCode, vAccount, PaymentNo, Barcode, ExpireDate) |
| `_pc_ecpay_ecpg_token` | ECPG GetTokenbyTrade token (stored for frontend SDK) |
| `_pc_ecpay_credit_variant` | Credit card variant: `''` / `'installment'` / `'period'` |
| `_pc_ecpay_installment` | Credit card installment count (e.g. `'6'`) |
| `_pc_logistics_provider_id` | Which logistics provider was used (e.g. `ecpay_logistics`) |
| `_pc_logistics_sub_type` | Logistics sub-type chosen at checkout (FAMI/UNIMART/HILIFE/HOME) |
| `_pc_logistics_payment_scenario` | Payment scenario at checkout (`online` / `cod`) |
| `_pc_logistics_temp_id` | TempLogisticsID from store selection callback (required for CreateByTempTrade) |
| `_pc_logistics_ref` | Unified logistics ID (ECPay LogisticsID); primary key for callback order lookup |
| `_pc_logistics_store_id` | Selected CVS store code |
| `_pc_logistics_store_name` | Selected CVS store name |
| `_pc_logistics_store_addr` | Selected CVS store address |
| `_pc_logistics_status` | Logistics status (raw ECPay LogisticsStatus string) |
| `_pc_logistics_cvs_payment_no` | C2C CVSPaymentNo (required for cancel shipment) |
| `_pc_logistics_cvs_validation_no` | C2C CVSValidationNo (required for cancel shipment) |
| `_pc_logistics_collection_paid` | COD collection completion flag (`yes`) |
| `_pc_logistics_processed_status` | Idempotency guard array — elements: `"{LogisticsID}:{LogisticsStatus}"` |
| `_pc_logistics_return_ref` | Reverse-logistics (return) ID (ECPay ReturnLogisticsID); written by `create_return`; also indexed by `get_order_by_ref` for reverse-logistics status callbacks |
| `_pc_payuni_trade_no` | MerTradeNo idempotency key (format `PCU{order_id}`); written by `before_process_payment`; primary key for NotifyURL order lookup |
| `_pc_payuni_payment_detail` | PAYUNi payment result detail from NotifyURL decrypted inner payload (includes `TradeNo`, `PaymentType` — used for refund routing) |
| `_pc_payuni_payment_info` | ATM/CVS get-code info (BankType, PayNo, Store, ExpireDate etc.); written on TradeStatus=0 |
| `_pc_payuni_capture_status` | Credit card capture/void status (`''` / `'captured'` / `'voided'`) |
| `_pc_payuni_uni_trade_no` | MerTradeNo idempotency key (format `PCE{order_id}`); written by `before_process_payment`; primary key for NotifyURL order lookup — UNi Embed |
| `_pc_payuni_uni_sdk_token` | SDK iframe token from token_get; stored for frontend use |
| `_pc_payuni_uni_payment_detail` | Payment result detail from NotifyURL decrypted inner payload (includes `TradeNo`, `PaymentType` — used for refund routing) — UNi Embed |
| `_pc_payuni_uni_capture_status` | Credit card capture/void status (`''` / `'captured'` / `'voided'`) — UNi Embed |
| `_pc_payuni_uni_credit_hash` | Buyer token (buyer credit card hash); **not** card number or CVC |
| `_pc_payuni_uni_credit_life` | Buyer credit card expiry (MMYY format) |
| `_pc_paynow_trade_no` | MerTradeNo idempotency key (format `PCN{order_id}`); written by `before_process_payment`; auxiliary for reconciliation (not Webhook lookup key) |
| `_pc_paynow_payment_intent_id` | PaymentIntentId (`pp_xxx`); written by `before_process_payment`; **primary key for Webhook order lookup** (`get_order_by_payment_intent_id`) |
| `_pc_paynow_secret` | Component SDK secret (`pp_xxx_st_xxx`); stored for frontend SDK rendering; never card number or CVC |
| `_pc_paynow_payment_detail` | Payment result detail from Webhook decrypted payload (includes `PaymentType` — used for refund routing) |
| `_pc_paynow_payment_info` | Offline payment pending info (ATM vAccount / convenience store payment code / ExpireDate etc.); written on offline pending phase |
| `_pc_paynow_refund_detail` | Refund result detail; written on successful refund or refund query |
| `_pc_paynow_logistics_provider_id` | `paynow_logistics`; written by create_shipment |
| `_pc_paynow_logistics_service_id` | Logistic_serviceID (01=SEVEN/03=FAMI/05=HILIFE/06=TCAT); written at checkout |
| `_pc_paynow_logistics_order_no` | PayNow OrderNo (=`$order->get_order_number()`); **primary key for status-callback order lookup** |
| `_pc_paynow_logistics_store_id` | Selected CVS store code (selection-callback) |
| `_pc_paynow_logistics_store_name` | Selected CVS store name |
| `_pc_paynow_logistics_store_addr` | Selected CVS store address |
| `_pc_paynow_logistics_ref` | LogisticNumber (PayNow shipment ID; auxiliary lookup key) |
| `_pc_paynow_logistics_sno` | Shipment sequence number (default "1") |
| `_pc_paynow_logistics_payment_no` | Carrier paymentno (物流商託運單號) |
| `_pc_paynow_logistics_validation_no` | Carrier validationno |
| `_pc_paynow_logistics_renew_order_no` | OrderNo after ReNewOrder (used for print) |
| `_pc_paynow_logistics_status` | Shipment status (0=成立中 / 1=無效) |
| `_pc_paynow_logistics_delivery_status` | Delivery status description (Detail_Status_Description) |
| `_pc_paynow_logistics_logistic_code` | Delivery status code (PayNowLogisticCode, e.g. 5000/8000) |
| `_pc_paynow_logistics_delivery_type` | TCAT temperature tier (DeliveryType) |
| `_pc_paynow_logistics_collection_paid` | COD pickup-complete flag (`yes`) |
| `_pc_paynow_logistics_processed_status` | Idempotency guard array — elements: `"{OrderNo}:{LogisticCode}"` |
| `_pc_callback_processed` | Unified webhook frame idempotency keys (array, latest 50; written only after handler success) — `tcat_pay` / `linepay` path |
| `_pc_slp_embedded_pending` | SLP embedded: `process_payment` ran, order-received should mount the SDK |
| `_pc_slp_reference_history` | SLP embedded: every per-attempt `referenceOrderId` sent (`{order_id}-{attempt}-{6 hex}`), written **before** the API call; ownership key for webhook / sync (server-written only) |
| `_pc_tcat_pay_trade_no` | 黑貓PAY `cust_order_no` of the latest attempt (`PCT{order_id}-{5 chars}`) |
| `_pc_tcat_pay_trade_no_history` | Every `cust_order_no` ever sent (APN / return lookup; platform numbers are permanently unique) |
| `_pc_tcat_pay_method` | Sub-method: `credit` / `cvs_ibon` / `atm` (refund routing: CVS-type has no refund API) |
| `_pc_tcat_pay_charge_amount` | Amount snapshot sent to the platform (amount guard baseline; refund/capture amounts) |
| `_pc_tcat_pay_payment_url` | COCS card page short URL |
| `_pc_tcat_pay_payment_info` | CVS/ATM get-code info for the thank-you page |
| `_pc_tcat_pay_payment_detail` | Platform-confirmed payment detail |
| `_pc_tcat_pay_processed_status` | Idempotency array — `"{trade_no}:{status}"`, written only when actually processed |
| `_pc_linepay_transaction_id` | LINE Pay transactionId (19-digit string — never cast to int) |
| `_pc_linepay_order_id` | LINE Pay orderId of the latest attempt (`PCL{order_id}-{attempt}-{6 hex}`) |
| `_pc_linepay_attempt` | Attempt counter |
| `_pc_linepay_charge_amount` | Amount snapshot used by confirm and the amount guard |
| `_pc_linepay_payment_url` | `paymentUrl.web` |
| `_pc_linepay_requested_at` | Payment request time (reconcile stops at the 1200s request expiry) |
| `_pc_linepay_payment_detail` | Payment detail after settle |
| `_pc_linepay_refund_detail` | Refund detail |
| `_pc_tcat_logistics_provider_id` / `_thermosphere` / `_ref` / `_file_no` / `_file_no_created_at` / `_status` / `_status_name` / `_unified_state` / `_return_ref` | 黑貓宅急便 (waybill ref, 24h FileNo for DownloadOBT, raw + unified state, ReserveAdd return ref); prefix `_pc_tcat_logistics_` |
| `_pc_hct_logistics_provider_id` / `_product_type` / `_ref` (edelno) / `_epino` / `_esdate` / `_arrival_station` / `_label` / `_status` / `_status_name` / `_unified_state` / `_status_time` / `_return_ref` | 新竹物流; prefix `_pc_hct_logistics_`; `_label` = stored label image used by print |
| `_pc_sf_express_provider_id` / `_ref` (sfWaybillNo) / `_customer_order_no` / `_cancelled_ref` / `_status` / `_status_name` / `_unified_state` / `_status_time` / `_status_ts` / `_processed_tracks` | 順豐; prefix `_pc_sf_express_`; `_status_ts` = latest applied event time (out-of-order guard); `_processed_tracks` = `trackUniqueId` dedupe (latest 50) |
| `_pc_kerrytj_logistics_provider_id` / `_temperature` / `_pending_bln` / `_ref` (BLN) / `_mode` / `_shipment` / `_station` / `_status` / `_status_name` / `_unified_state` / `_status_time` / `_return_pending_bln` / `_return_ref` | 嘉里大榮; prefix `_pc_kerrytj_logistics_`; `_pending_bln` = BLN allocated and stored before the API call (retry reuses it); `_shipment` = 34-field create snapshot |

---

## Key WordPress Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_payment_gateways` | Inject SLP (redirect + embedded) / ECPay AIO / ECPay ECPG / NewebPay MPG / PAYUNi UPP / PAYUNi UNi Embed / PayNow / 黑貓PAY / LINE Pay gateways |
| `before_woocommerce_init` | Declare HPOS + Blocks compatibility |
| `wc_payment_gateways_initialized` | Populate ProviderUtils::$container |
| `woocommerce_order_status_{status}` | Auto issue/void invoices |
| `woocommerce_checkout_fields` | Classic checkout invoice fields; (p1000) strip billing address fields for all-virtual cart (`virtual_hide_address`) |
| `woocommerce_get_country_locale` | Checkout Enhancement: `NameOrderService` (p20, TW last/first name order) + `VirtualCartAddressService` (p1000, TW hidden address fields); always write the explicit `TW` key |
| `woocommerce_states` | Checkout Enhancement: TW → 22 counties dropdown (`tw_address`) |
| `woocommerce_cart_loaded_from_session` / `woocommerce_store_api_checkout_update_order_from_request` (p1) | Reset `WC()->countries->locale` cache so cart-dependent locale filters re-run |
| `woocommerce_after_checkout_validation` | Classic TW mobile format check |
| `woocommerce_store_api_checkout_update_order_from_request` (p10) | Blocks TW mobile format check (throws `RouteException` 400) |
| `woocommerce_order_status_changed` (p20) + `shutdown` | Queue →processing orders; flush at `shutdown` and auto-complete all-virtual orders |
| `woocommerce_cart_totals_before_shipping` / `woocommerce_review_order_before_shipping` | Classic free-shipping hint row |
| `woocommerce_blocks_loaded` | Register Store API cart extension `power-checkout.free_shipping_hint` |
| `wp_head` (p1000) | Print CSS hiding the Blocks country field for all-virtual carts |
| `woocommerce_shipping_methods` | Register all logistics `WC_*Shipping` classes (ECPay / PAYUNi / PayNow / Tcat / HCT / SF Express / Kerry TJ; classic checkout shipping methods) |
| `woocommerce_checkout_create_order` | Write logistics sub_type + payment_scenario meta from checkout |
| `rest_api_init` | Register logistics status-callback + selection-callback endpoints; `PaymentDispatchApiService` (`/pay/{id}/dispatch`); `LogisticsPingApiService` (`/logistics/{provider}/ping`); unconditional `tcat-pay/apn`, `linepay/confirm` + `linepay/cancel`, `sf-express/track-push`; Kerry admin REST when enabled |
| `pc_linepay_reconcile` (Action Scheduler action, group `power_checkout`) | `LinepayReconcileJob::run( order_id, attempt, pending_round )` — LINE Pay reconcile (no webhook); scheduled at payment request, re-scheduled while customer not yet authenticated |
| filter `power_checkout_linepay_reconcile_delay` | Reconcile delay seconds `( int $default 300, int $order_id )`; floor 60 |
| filter `power_checkout_logistics_provider_ids` | Extend `LogisticsApiService::PROVIDER_IDS` (new providers / test doubles) |
| `update_option_woocommerce_hct_logistics_settings` / `…kerrytj_logistics_settings` (+ `add_option_…`) | Sync waybill ranges into `WaybillNumberAllocator` on settings save (registered without requiring the provider to be enabled) |
| `woocommerce_order_actions` + `woocommerce_order_action_{pc_tcat_pay_query_trade\|pc_linepay_query_trade}` | Admin「重新查詢付款狀態（補單）」 for 黑貓PAY / LINE Pay |
| `admin_enqueue_scripts` | Load Vue app bundle (admin pages) |
| `wp_enqueue_scripts` | Load Vue app bundle (frontend checkout for invoice form); `classic-tw-address.js` (classic checkout, `tw_address`); `pc_free_shipping_hint` block script (p20, ES module) |

---

## HPOS Compatibility

- `OrderUtils::is_order_detail($hook)` supports both HPOS and legacy order screens
- MetaBox registered on both `shop_order` and `woocommerce_page_wc-orders`
- `custom_order_tables` compatibility declared in `before_woocommerce_init`

---

## Release Pipeline

Release (`pnpm release`) runs: build Vue → build blocks → bump version → sync version to plugin.php → composer install --no-dev → create zip → GitHub release with zip asset. Requires `.env` file with `GITHUB_TOKEN`.
