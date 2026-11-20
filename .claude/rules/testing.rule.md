---
globs:
  - "tests/Integration/**/*.php"
  - "tests/e2e/**/*.ts"
---

# Testing Rules

## PHP Unit Tests

### Infrastructure

- Active test directory: `tests/Integration/` — this is the authoritative suite used by `composer test`
- Base class: `Tests\Integration\TestCase` (extends `WP_UnitTestCase`)
- Namespace: `Tests\Integration\`
- Bootstrap: `tests/bootstrap.php`; DB config: `tests/wp-tests-config.php`
- Config file: `phpunit.xml.dist` (note: **not** `phpunit.xml`)
- **Group whitelist** (phpunit.xml.dist `<groups><include>`): only `smoke` / `happy` / `error` / `edge` / `security` are collected — every test must carry at least one of these annotations to be executed
- Additional classification groups used alongside: `integration`, `invoice`, provider name (e.g. `ezpay`)
- New-provider mock suites are selected by class-name filter, e.g. `--filter 'SlpEmbedded|SlpOrderResolver|SlpWebhookResponse|SlpReturnSync|SlpSessionIdentity'`, `LinepayDirect`, `TcatPay`, `TcatLogistics|WC_TcatLogisticsShipping`, `Hct`, `Sf(Crypto|AccessTokenStore|Express|TrackPush|Region)`, `Kerrytj`, `LogisticsPingStatusCode` (tests live under `tests/Integration/Payment/` and `tests/Integration/Logistics/`)
- Required security test themes for callback / push endpoints: forged callback does not settle (platform query / signature is the source of truth), idempotency not pre-poisonable, amount guard, order-ownership checks (history meta), always-200/302 acknowledgement paths, secrets masked in logs
- Legacy directory `inc/tests/` is **not** referenced by the active config; do not add new tests there

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
  04-live/           # Live acceptance recordings (project `live-acceptance` only; excluded from `api-tests`)
  fixtures/          # Test data (test-data.ts)
  helpers/           # Shared helpers
    live-env.ts / live-setup.ts / live-logistics.ts  # live-acceptance env loading, product/gateway setup, logistics helpers
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

Config: `tests/e2e/playwright.config.ts` (projects: `api-tests` = everything except `04-live/**`; `live-acceptance` = `04-live/**` only)

### Live Acceptance (`--project=live-acceptance`)

Recorded end-to-end checks against the real site and each gateway / logistics **sandbox** (video + trace + screenshots on). Specs: `slp-embedded`, `linepay`, `tcat-pay`, `tcat-logistics`, `hct`, `sf-express`, `kerrytj-settings`.

```bash
cd tests/e2e
LIVE_ENV_FILES="<local untracked env file(s), ';'-separated>" npx playwright test --project=live-acceptance 04-live/<spec>
```

Rules:
- **One spec per run** (WordPress shares one DB session; the project is single-worker)
- **Credentials only from env vars or a local untracked env file** (`LIVE_ENV_FILES`; repo-root `.env` is also read; already-set env wins). `helpers/live-env.ts` parses `KEY=VALUE` itself (no shell `source`, so `$` / `!` in passwords survive). Never write credential values into specs, docs, commits or logs; a spec with missing keys calls `test.skip` listing only the key **names**. `.env.*`, `.tmp/` and `tests/e2e/acceptance-videos/` are gitignored
- Headed Chrome by default (`LIVE_BROWSER_CHANNEL` overrides the `chrome` channel); headless is often blocked by WAF / cashier anti-automation. Card fields in PCI iframes need real key events (`keyboard.type`), not `fill`
- Guest checkout: no admin `storageState` and **no** `X-WP-Nonce` header (an empty nonce is rejected by WP as `rest_cookie_invalid_nonce` 403 on guest `order_key` REST such as `/pay/{id}/dispatch`); admin steps log in inside the spec
- `E2E_BASE_URL` overrides the site; `PC_LIVE_ACCEPTANCE=1` marks a live run (global setup/teardown skip); `LIVE_LINEPAY_MANUAL=1` pauses at the LINE Pay page for a real person
- Provider levels: **full** (SF Express on the public SIT sandbox: connect → create → PDF → query → signed push) vs **degraded** (no test credentials: only what is verifiable — e.g. settings validation, failure-path messages, read-only ping). Restore any gateway settings / enabled flags changed during a run afterwards
- Known external blockers are recorded as `test.skip` with the reason (SLP embedded: sandbox PCI `1005` until SHOPLINE grants SDK permission)

## Frontend Pure-Logic Tests (`pnpm test:js`)

`tests/js/*.test.ts`, run by `node --experimental-strip-types --test tests/js/*.test.ts` (Node 22+ built-in test runner; no Vite, browser or WordPress). Use it for framework-free TypeScript logic (currently `js/src/utils/error-code.ts`). Files under test must import with plain relative/explicit paths that Node can resolve (no `@/` alias, no Vue / Element Plus imports). Keep Vue component behaviour out of this suite.

### Webhook Test Pattern

Webhook tests generate valid HMAC signatures using `helpers/webhook-hmac.ts`:

```typescript
// Generate signature for webhook payload verification
import { generateHmacSignature } from '../helpers/webhook-hmac'
```
