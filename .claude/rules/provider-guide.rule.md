---
globs:
  - "inc/classes/Domains/**/*.php"
  - "js/src/pages/**/*.vue"
  - "js/src/router/**/*.ts"
  - "inc/assets/blocks/**/*.tsx"
---

# Adding New Providers Guide

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
7. Add route in `js/src/router/index.ts` and entry in `ROUTER_MAPPER`
