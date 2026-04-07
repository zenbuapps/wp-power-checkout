---
globs:
  - "inc/tests/**/*.php"
  - "tests/e2e/**/*.ts"
---

# Testing Rules

## PHP Unit Tests

### Infrastructure

- Base class: `J7\PowerCheckoutTests\Shared\WC_UnitTestCase` (extends `WP_UnitTestCase`)
- Test directory: `inc/tests/` mirrors `inc/classes/` structure
- Namespace: `J7\PowerCheckoutTests\`
- Test DB config: `phpunit.xml` (`WP_DB_HOST`, `WP_ABSPATH`)

### API Mode

Tests run in one of three modes controlled by `API_MODE` env var:

| Mode | Behavior |
|---|---|
| `mock` | No external API calls, returns mocked responses |
| `sandbox` | Calls sandbox/test API endpoints |
| `prod` | Calls production API endpoints |

```bash
composer test              # API_MODE=mock (default, safe for CI)
composer test:sandbox      # API_MODE=sandbox
composer test:prod         # API_MODE=prod (use with caution)
```

### @Create Attribute

Test classes use `@Create` PHP attribute to auto-instantiate fixture helpers:

```php
#[Create(Order::class)]
#[Create(Product::class)]
#[Create(User::class)]
#[Create(Requester::class)]
final class SomeTest extends WC_UnitTestCase {
    public function test_something(): void {
        $order = $this->get_container(Order::class);
        // $order is pre-created fixture
    }
}
```

### Running Single Tests

```bash
vendor/bin/phpunit --filter ClassName
vendor/bin/phpunit --filter "test_method_name"
vendor/bin/phpunit --filter "ClassName::test_method_name"
```

## E2E Tests (Playwright)

### Structure

```
tests/e2e/
  01-admin/          # Admin-side tests (settings, invoices, refunds, webhook)
  02-frontend/       # Frontend tests (checkout page, payment selection, invoice form)
  03-integration/    # Cross-cutting tests (data boundary, edge cases, security)
  fixtures/          # Test data (test-data.ts)
  helpers/           # Shared helpers
    api-client.ts    # REST API client for test setup
    webhook-hmac.ts  # HMAC signature generation for webhook tests
    admin-setup.ts   # Admin page navigation helpers
    lc-bypass.ts     # License check bypass
  global-setup.ts    # Test environment initialization
  global-teardown.ts # Cleanup
```

### Running E2E

E2E tests have their own `package.json` in `tests/e2e/`:

```bash
cd tests/e2e
npm install
npx playwright test
npx playwright test --grep "settings"
```

Config: `tests/e2e/playwright.config.ts`

### Webhook Test Pattern

Webhook tests generate valid HMAC signatures using `helpers/webhook-hmac.ts`:

```typescript
// Generate signature for webhook payload verification
import { generateHmacSignature } from '../helpers/webhook-hmac'
```
