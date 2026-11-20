---
name: stripe-node-v22
description: >
  Stripe Node.js SDK (stripe ^22.x) complete technical reference for server-side payment processing.
  Use this skill whenever the task involves: import from 'stripe', new Stripe(), stripe.paymentIntents,
  stripe.customers, stripe.checkout.sessions, stripe.subscriptions, stripe.refunds, stripe.products,
  stripe.prices, stripe.webhooks.constructEvent, StripeError handling, Stripe webhook endpoints,
  payment integration, checkout flows, subscription billing, or any Stripe API usage in Node.js/TypeScript.
  Also use when debugging Stripe webhook signature verification, idempotency keys, test card numbers,
  or Stripe-specific error types (StripeCardError, StripeInvalidRequestError, etc.).
---

# Stripe Node.js SDK v22 -- Quick Reference

Covers `stripe` npm package ^22.x for Node.js 18+.

All amounts are in the **smallest currency unit**. **TWD is NOT zero-decimal — TWD amounts are ×100, exactly like USD** (`99900` = NT$999). Only the 16 currencies in the zero-decimal list (JPY, KRW, VND, …) are ×1. Getting this wrong silently refunds 1/100 of the intended amount — Stripe accepts it as a legal partial refund and reports success. See `references/api-reference.md` §9 for the full table, the shared `toStripeAmount()` / `fromStripeAmount()` converters, and the post-mortem.

**Deep dives**: `references/api-reference.md` (full parameter tables, object schemas, search API, currency handling).
**Webhooks & NestJS**: `references/webhooks-and-patterns.md` (event types, lifecycle, NestJS patterns, testing).

---

## 1. Initialization & Config

```typescript
import Stripe from 'stripe';

const stripe = new Stripe('sk_test_...', {
  apiVersion: '2025-03-31.basil',  // pin API version
  maxNetworkRetries: 2,            // default 1
  timeout: 80_000,                 // ms, default 80000
  telemetry: true,                 // default true
  appInfo: { name: 'ZenbuCart', version: '1.0.0' },
});

// NestJS: lazy init from settings DB
private async getStripe(): Promise<Stripe> {
  const key = await this.settings.get('payment.stripe_secret_key') as string | null;
  if (!key) throw new ConflictException('Stripe not configured');
  return new Stripe(key);
}

// Per-request options (2nd arg on any method)
await stripe.customers.create(
  { email: 'user@example.com' },
  { idempotencyKey: 'key-123', timeout: 5000, stripeAccount: 'acct_xxx' },
);
```

## 2. TypeScript Types

```typescript
import Stripe from 'stripe';

// Param types: Stripe.<Resource><Action>Params
const p: Stripe.PaymentIntentCreateParams = { amount: 2000, currency: 'usd' };

// Response types: Stripe.<Resource>
const pi: Stripe.PaymentIntent = await stripe.paymentIntents.create(p);

// Commonly used types
type Customer       = Stripe.Customer;
type PaymentIntent  = Stripe.PaymentIntent;
type Subscription   = Stripe.Subscription;
type Session        = Stripe.Checkout.Session;
type Event          = Stripe.Event;
type Invoice        = Stripe.Invoice;
type Refund         = Stripe.Refund;

// Expandable fields: string | FullObject | null
if (typeof pi.customer === 'object' && pi.customer !== null) {
  console.log(pi.customer.email);
}

// Response metadata
pi.lastResponse.requestId;   // 'req_abc'
pi.lastResponse.statusCode;  // 200
```

## 3. Payment Intents

```typescript
// Create
const pi = await stripe.paymentIntents.create({
  amount: 2000,                    // required, cents
  currency: 'usd',                // required, ISO 4217 lowercase
  customer: 'cus_xxx',
  payment_method: 'pm_xxx',
  confirm: true,                   // default false
  capture_method: 'automatic',     // 'automatic' | 'automatic_async' | 'manual'
  automatic_payment_methods: { enabled: true },
  metadata: { sessionToken: 'tok_abc' },
  receipt_email: 'buyer@example.com',
  setup_future_usage: 'off_session',  // 'on_session' | 'off_session'
  statement_descriptor: 'ZENBU ORDER', // max 22 chars
});
// pi.client_secret  -- for frontend Stripe.js
// pi.status: 'requires_payment_method'|'requires_confirmation'|'requires_action'
//            |'processing'|'succeeded'|'requires_capture'|'canceled'

// Retrieve / Confirm / Capture / Cancel
await stripe.paymentIntents.retrieve('pi_xxx', { expand: ['customer'] });
await stripe.paymentIntents.confirm('pi_xxx', { payment_method: 'pm_card_visa' });
await stripe.paymentIntents.capture('pi_xxx', { amount_to_capture: 1500 });
await stripe.paymentIntents.cancel('pi_xxx', { cancellation_reason: 'requested_by_customer' });

// List
await stripe.paymentIntents.list({ customer: 'cus_xxx', limit: 25 });
```

## 4. Customers

```typescript
await stripe.customers.create({
  email: 'jenny@example.com', name: 'Jenny Rosen', phone: '+886912345678',
  metadata: { userId: 'usr_123' },
  address: { line1: '123 Main St', city: 'Taipei', country: 'TW' },
  payment_method: 'pm_xxx',
  invoice_settings: { default_payment_method: 'pm_xxx' },
});
await stripe.customers.retrieve('cus_xxx');
await stripe.customers.update('cus_xxx', { name: 'New Name' });
await stripe.customers.del('cus_xxx');
await stripe.customers.list({ email: 'jenny@example.com', limit: 10 });
await stripe.customers.search({ query: "email:'jenny@example.com'" });
```

## 5. Products & Prices

```typescript
// Product
await stripe.products.create({
  name: 'Premium Plan',         // required
  description: 'Full access',
  images: ['https://example.com/img.png'], // up to 8
  metadata: { tier: 'premium' },
  default_price_data: { currency: 'usd', unit_amount: 2000, recurring: { interval: 'month' } },
});
await stripe.products.retrieve('prod_xxx');
await stripe.products.update('prod_xxx', { name: 'New Name' });
await stripe.products.list({ active: true, limit: 100 });

// One-time Price
await stripe.prices.create({
  product: 'prod_xxx', currency: 'twd', unit_amount: 99900,  // NT$999
});

// Recurring Price
await stripe.prices.create({
  product: 'prod_xxx', currency: 'usd', unit_amount: 1000,
  recurring: { interval: 'month', interval_count: 1, usage_type: 'licensed' },
  lookup_key: 'premium_monthly',
});

// Inline product + price
await stripe.prices.create({
  currency: 'usd', unit_amount: 5000,
  product_data: { name: 'Starter Plan' },
  recurring: { interval: 'month' },
});
```

## 6. Checkout Sessions

```typescript
// Payment mode
const session = await stripe.checkout.sessions.create({
  mode: 'payment',   // 'payment' | 'subscription' | 'setup'
  line_items: [
    { price: 'price_xxx', quantity: 2 },
    { price_data: { currency: 'twd', product_data: { name: 'Item' }, unit_amount: 50000 }, quantity: 1 },
  ],
  success_url: 'https://shop.example.com/success?session_id={CHECKOUT_SESSION_ID}',
  cancel_url: 'https://shop.example.com/cancel',
  customer: 'cus_xxx',
  metadata: { orderId: 'order_123' },
  payment_intent_data: { metadata: { sessionToken: 'tok_abc' } },
  allow_promotion_codes: true,
  automatic_tax: { enabled: true },
  expires_at: Math.floor(Date.now() / 1000) + 1800,
  locale: 'zh-TW',
});
// session.url -- redirect customer here

// Subscription mode
await stripe.checkout.sessions.create({
  mode: 'subscription',
  line_items: [{ price: 'price_recurring_xxx', quantity: 1 }],
  success_url: '...', cancel_url: '...',
  subscription_data: { trial_period_days: 14 },
});

await stripe.checkout.sessions.retrieve('cs_xxx', { expand: ['line_items','payment_intent'] });
await stripe.checkout.sessions.listLineItems('cs_xxx');
await stripe.checkout.sessions.expire('cs_xxx');
```

## 7. Subscriptions

```typescript
const sub = await stripe.subscriptions.create({
  customer: 'cus_xxx',                    // required
  items: [{ price: 'price_xxx' }],       // up to 20
  default_payment_method: 'pm_xxx',
  payment_behavior: 'default_incomplete', // 'allow_incomplete'|'error_if_incomplete'
  trial_period_days: 14,
  metadata: { plan: 'premium' },
});
// sub.status: 'active'|'trialing'|'past_due'|'unpaid'|'canceled'|'incomplete'|'paused'

await stripe.subscriptions.update('sub_xxx', {
  items: [{ id: 'si_xxx', quantity: 2 }],
  proration_behavior: 'create_prorations',
  cancel_at_period_end: true,
});

await stripe.subscriptions.cancel('sub_xxx', {
  cancellation_details: { feedback: 'too_expensive' },
  invoice_now: true, prorate: true,
});

await stripe.subscriptions.retrieve('sub_xxx');
await stripe.subscriptions.list({ customer: 'cus_xxx', status: 'active' });
```

## 8. Refunds

```typescript
// Full refund
await stripe.refunds.create({ payment_intent: 'pi_xxx', reason: 'requested_by_customer' });
// Partial refund
await stripe.refunds.create({ payment_intent: 'pi_xxx', amount: 500 });
// By charge (legacy)
await stripe.refunds.create({ charge: 'ch_xxx', amount: 1000 });

await stripe.refunds.retrieve('re_xxx');
await stripe.refunds.list({ payment_intent: 'pi_xxx' });
await stripe.refunds.cancel('re_xxx');  // only if status='pending'
```

## 9. Webhooks

```typescript
// Signature verification -- rawBody MUST be unparsed Buffer/string
const event = stripe.webhooks.constructEvent(rawBody, signature, 'whsec_...');
// Throws Stripe.errors.StripeSignatureVerificationError on failure

// Key e-commerce events:
// payment_intent.succeeded / payment_intent.payment_failed
// checkout.session.completed / checkout.session.expired
// customer.subscription.created/updated/deleted
// invoice.paid / invoice.payment_failed
// charge.refunded / charge.dispute.created

// NestJS: enable raw body
const app = await NestFactory.create(AppModule, { rawBody: true });

// Unit test helper
const header = stripe.webhooks.generateTestHeaderString({ payload, secret });
```

See `references/webhooks-and-patterns.md` for complete NestJS controller/service patterns and event type table.

## 10. Error Handling

```typescript
try {
  await stripe.paymentIntents.create({ amount: 2000, currency: 'usd' });
} catch (err) {
  if (err instanceof Stripe.errors.StripeCardError) {
    // 402: err.code ('card_declined'), err.decline_code ('insufficient_funds'), err.message
  } else if (err instanceof Stripe.errors.StripeInvalidRequestError) {
    // 400: err.param (which parameter was invalid)
  } else if (err instanceof Stripe.errors.StripeAuthenticationError) {
    // 401: invalid API key
  } else if (err instanceof Stripe.errors.StripeRateLimitError) {
    // 429: too many requests
  } else if (err instanceof Stripe.errors.StripeAPIError) {
    // 5xx: Stripe server error (rare)
  } else if (err instanceof Stripe.errors.StripeConnectionError) {
    // network failure
  } else if (err instanceof Stripe.errors.StripeIdempotencyError) {
    // 409: idempotency key conflict
  } else if (err instanceof Stripe.errors.StripeSignatureVerificationError) {
    // webhook signature mismatch
  }
  // All share: err.type, err.statusCode, err.message, err.requestId, err.code
}
```

## 11. Idempotency

```typescript
await stripe.paymentIntents.create(
  { amount: 2000, currency: 'usd' },
  { idempotencyKey: `create_pi_${orderId}` },  // max 255 chars, use UUID v4
);
// Same key + same params = cached result. Same key + diff params = StripeIdempotencyError.
// Keys expire after 24h. Only POST (create) needs idempotency; GET/DELETE are natural.
```

## 12. Testing

**Test keys**: `sk_test_...` (server), `pk_test_...` (client). No real charges.

| Card Number | Behavior |
|-------------|----------|
| `4242424242424242` | Visa success |
| `5555555555554444` | Mastercard success |
| `4000000000000002` | Generic decline |
| `4000000000009995` | Insufficient funds |
| `4000000000009987` | Lost card |
| `4000000000000069` | Expired card |
| `4000000000000127` | Incorrect CVC |
| `4000000000000119` | Processing error |
| `4000000000003220` | 3D Secure required (completes) |

Server-side PM tokens: `pm_card_visa`, `pm_card_mastercard`, `pm_card_chargeDeclined`.
Use any future expiry (e.g., `12/34`), any 3-digit CVC.

```bash
stripe listen --forward-to localhost:6011/v1/commerce/payments/stripe/webhook
stripe trigger payment_intent.succeeded
```

## 13. Auto-Pagination

```typescript
for await (const c of stripe.customers.list({ limit: 100 })) { /* all pages */ }
const all = await stripe.customers.list().autoPagingToArray({ limit: 10_000 });
await stripe.customers.list().autoPagingEach(async (c) => { if (done) return false; });
```

## 14. Security Checklist

1. Never expose `sk_test_*` / `sk_live_*` -- server-only
2. Always verify webhook signatures via `constructEvent`
3. Use idempotency keys for all payment-creating operations
4. Store keys in settings/env -- never hardcode
5. Return 200 quickly from webhooks, process async (BullMQ queue)
6. Log `requestId` from errors for Stripe support
7. Use metadata to link Stripe objects to domain entities (orderId, sessionToken)
