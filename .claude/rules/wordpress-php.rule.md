---
globs: "**/*.php"
---

# WordPress PHP Development Rules

## Strict Typing & Class Design

- Every PHP file: `declare(strict_types=1);`
- Default to `final class` -- only omit `final` when inheritance is required
- Namespace: `J7\PowerCheckout\` maps to `inc/classes/` (PSR-4)
- Test namespace: `J7\PowerCheckoutTests\` maps to `inc/tests/`

## Domain-Driven Design Structure

```
inc/classes/Domains/{Domain}/
  ProviderRegister.php       # Static register_hooks() entry point
  {Provider}/
    Services/                # Business logic (Singletons or Gateway instances)
    Http/                    # External API clients (ApiClient, WebHook)
    DTOs/                    # Data Transfer Objects (immutable, from arrays via ::create())
    Managers/                # Orchestration logic (StatusManager, EventTypeManager)
    Shared/Enums/            # Backed enums for domain constants
    Shared/Helpers/          # Domain-specific utilities (Requester, MetaKeys)
    Shared/Traits/           # Reusable property traits for DTOs
```

Three domains: Payment, Invoice, Settings. Each domain has a `ProviderRegister` with a static `register_hooks()` method called from `Bootstrap`.

## Hook Registration Pattern

All hook registration follows this pattern -- never use closures for hook callbacks:

```php
// CORRECT: static method reference
\add_action('hook_name', [__CLASS__, 'method_name']);
\add_filter('filter_name', [__CLASS__, 'method_name']);

// WRONG: closure or instance method
\add_action('hook_name', function() { ... });  // Never
\add_action('hook_name', [$this, 'method']);    // Avoid
```

Exception: `woocommerce_blocks_payment_method_type_registration` in `RedirectGateway::register_checkout_blocks()` uses a closure because it needs access to `self::ID`.

## Provider System

Central concept -- understand before modifying Payment or Invoice code:

- `ProviderUtils::$container` holds all enabled provider instances
- Payment providers are `WC_Payment_Gateway` subclasses, populated via `wc_payment_gateways_initialized`
- Invoice providers are `BaseService` subclasses implementing `IInvoiceService`, populated in `ProviderRegister::register_hooks()`
- Settings stored in WC options: `woocommerce_{provider_id}_settings`
- Check enabled state: `ProviderUtils::is_enabled($id)` reads `enabled` key from that option

## WC_Payment_Gateway Integration

Payment gateways extend `AbstractPaymentGateway`:

- `process_payment()` is `final` -- override `before_process_payment()` instead
- `before_page_render()` is `final` -- override `before_order_received()` instead
- `display_errors()` is `final` -- uses `WC_Admin_Settings::add_error()`
- `logger()` is `final` -- logs to WC Logger and optionally to order notes
- `get_settings()` is `abstract static` -- must return settings array
- Gateway `$supports` includes `checkout-blocks` and `block_checkout` by default

## DTO Pattern

DTOs use a `::create(array $data)` factory method (from `j7-dev/wp-utils` `DTO` class):

```php
// Creating from array
$dto = SomeDTO::create($array_data);

// Converting back
$dto->to_array();

// Settings DTOs use singleton with WC option merging
$settings = RedirectSettingsDTO::instance();
```

DTO properties use camelCase (matching SLP API), not snake_case.

## REST API Service Pattern

All API services extend `ApiBase` (from wp-utils):

```php
final class SomeApiService extends ApiBase {
    use SingletonTrait;

    protected $namespace = 'power-checkout/v1';
    protected $apis = [
        ['endpoint' => 'some-endpoint', 'method' => 'post'],
    ];

    // Callback naming: {method}_{endpoint_with_underscores}_callback
    // With path params: {method}_{endpoint}_with_id_callback
    public function post_some_endpoint_callback(\WP_REST_Request $request): \WP_REST_Response {}
}
```

Response format is always: `['code' => string, 'message' => string, 'data' => mixed]`

## HPOS Compatibility

Always use `OrderUtils` for order-related checks:

- `OrderUtils::is_order_detail($hook)` -- works for both HPOS and legacy
- `OrderUtils::get_order_id($hook)` -- extracts order ID from `$_GET['post']` or `$_GET['id']`
- MetaBox registration: include both `shop_order` and `woocommerce_page_wc-orders`
- Never use `get_post_meta()` for orders -- use `$order->get_meta()` / `$order->update_meta_data()`

## Error Handling

```php
try {
    // business logic
} catch (\Throwable $e) {
    Plugin::logger($e->getMessage(), 'error', [], 5);
    // Never expose internal errors to frontend
    \wc_add_notice('Generic user-facing message', 'error');
}
```

For gateway-specific logging, use `$this->logger()` which also writes to order notes.

## Text Domain

Always `'power_checkout'` (underscore). Never `'power-checkout'` (hyphen).

## Static Analysis

- PHPStan level 9 with bootstrap stubs for WP and WC
- Known ignored patterns in `phpstan.neon`: function not found, mixed casts, nullsafe on non-nullable
- Run: `vendor/bin/phpstan analyse`

## PHPCS

- WordPress coding standards with notable relaxations: Yoda conditions disabled, short array syntax required, camelCase properties allowed
- Final class enforcement active (with exclusions for external sniff classes)
- Final methods in traits enforced
- Tabs for indentation (4-space equivalent)
- Run: `composer lint`
