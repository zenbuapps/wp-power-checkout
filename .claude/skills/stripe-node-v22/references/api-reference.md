# Stripe Node.js SDK v22 -- Detailed API Reference

Extended parameter documentation for each resource. Read this file when you need
the full parameter list, return types, or edge-case behaviors beyond what SKILL.md covers.

---

## Table of Contents

1. [PaymentIntent -- Full Parameter Reference](#1-paymentintent)
2. [Customer -- Full Parameter Reference](#2-customer)
3. [Product -- Full Parameter Reference](#3-product)
4. [Price -- Full Parameter Reference](#4-price)
5. [Checkout Session -- Full Parameter Reference](#5-checkout-session)
6. [Subscription -- Full Parameter Reference](#6-subscription)
7. [Refund -- Full Parameter Reference](#7-refund)
8. [Auto-Pagination API](#8-auto-pagination-api)
9. [Amount Handling & Currency](#9-amount-handling--currency)
10. [Expanding Responses](#10-expanding-responses)
11. [Metadata](#11-metadata)
12. [Search API](#12-search-api)

---

## 1. PaymentIntent

### Create Parameters (`Stripe.PaymentIntentCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `amount` | `number` | Y | Positive integer in smallest currency unit. Min ~$0.50 USD equivalent. Max 99999999. |
| `currency` | `string` | Y | ISO 4217 lowercase (e.g., `'usd'`, `'twd'`, `'jpy'`). |
| `customer` | `string` | N | Customer ID. Payment methods must belong to this customer. |
| `description` | `string` | N | Arbitrary text, shown in Dashboard. |
| `metadata` | `Record<string,string>` | N | Up to 50 key-value pairs, keys max 40 chars, values max 500 chars. |
| `payment_method` | `string` | N | PaymentMethod, Card, or Source ID. |
| `payment_method_types` | `string[]` | N | Explicit list: `['card']`, `['card','ideal']`. Mutually exclusive with `automatic_payment_methods`. |
| `automatic_payment_methods` | `object` | N | `{ enabled: true, allow_redirects?: 'always' \| 'never' }`. Uses Dashboard-enabled methods. |
| `confirm` | `boolean` | N | If `true`, confirm immediately (combines create+confirm). Default `false`. |
| `capture_method` | `string` | N | `'automatic'` (default), `'automatic_async'` (recommended), `'manual'` (hold then capture). |
| `receipt_email` | `string` | N | Email for payment receipt. |
| `setup_future_usage` | `string` | N | `'on_session'`, `'off_session'`, `'none'`. Optimizes for SCA compliance. |
| `statement_descriptor` | `string` | N | Max 22 chars, appears on card statement. |
| `statement_descriptor_suffix` | `string` | N | Appended to account statement descriptor. |
| `shipping` | `object` | N | `{ name, phone?, address: { line1, line2?, city?, state?, postal_code?, country? }, carrier?, tracking_number? }` |
| `return_url` | `string` | N | URL for redirect-based payment methods after authentication. |
| `off_session` | `boolean` | N | Indicates merchant-initiated transaction (no customer present). |
| `mandate_data` | `object` | N | For mandate-based payment methods (SEPA, Bacs). |
| `transfer_data` | `object` | N | Connect: `{ destination: 'acct_xxx', amount?: number }`. |
| `application_fee_amount` | `number` | N | Connect: platform fee in cents. |
| `on_behalf_of` | `string` | N | Connect: connected account for settlement. |

### PaymentIntent Object Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | `'pi_xxx'` |
| `object` | `'payment_intent'` | |
| `amount` | `number` | Amount in cents |
| `amount_capturable` | `number` | Amount that can be captured (manual capture) |
| `amount_received` | `number` | Amount successfully collected |
| `canceled_at` | `number \| null` | Unix timestamp of cancellation |
| `cancellation_reason` | `string \| null` | Why it was canceled |
| `capture_method` | `string` | |
| `client_secret` | `string \| null` | For frontend confirmation |
| `confirmation_method` | `string` | `'automatic'` or `'manual'` |
| `created` | `number` | Unix timestamp |
| `currency` | `string` | |
| `customer` | `string \| Stripe.Customer \| null` | Expandable |
| `description` | `string \| null` | |
| `last_payment_error` | `object \| null` | `{ code, decline_code, message, type, payment_method }` |
| `latest_charge` | `string \| Stripe.Charge \| null` | Expandable |
| `metadata` | `Record<string,string>` | |
| `next_action` | `object \| null` | Client action needed (3DS, redirect) |
| `payment_method` | `string \| Stripe.PaymentMethod \| null` | Expandable |
| `payment_method_types` | `string[]` | |
| `receipt_email` | `string \| null` | |
| `setup_future_usage` | `string \| null` | |
| `shipping` | `object \| null` | |
| `statement_descriptor` | `string \| null` | |
| `status` | `string` | See status values below |

### PaymentIntent Status Values

| Status | Meaning |
|--------|---------|
| `requires_payment_method` | Created, awaiting payment method |
| `requires_confirmation` | Has payment method, awaiting confirm |
| `requires_action` | Requires customer action (3DS, redirect) |
| `processing` | Payment is processing |
| `requires_capture` | Authorized, awaiting manual capture |
| `succeeded` | Payment completed |
| `canceled` | Canceled by merchant or system |

### Other PaymentIntent Methods

```typescript
stripe.paymentIntents.retrieve(id, params?)
stripe.paymentIntents.update(id, params)
stripe.paymentIntents.confirm(id, params?)
stripe.paymentIntents.capture(id, params?)    // params: { amount_to_capture? }
stripe.paymentIntents.cancel(id, params?)     // params: { cancellation_reason? }
stripe.paymentIntents.list(params?)
stripe.paymentIntents.search(params)          // params: { query: string }
stripe.paymentIntents.incrementAuthorization(id, params)
stripe.paymentIntents.applyCustomerBalance(id, params?)
```

---

## 2. Customer

### Create Parameters (`Stripe.CustomerCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `email` | `string` | N | Max 512 chars |
| `name` | `string` | N | Max 256 chars |
| `phone` | `string` | N | Max 20 chars |
| `description` | `string` | N | Internal note |
| `metadata` | `Record<string,string>` | N | |
| `address` | `object` | N | `{ line1, line2?, city?, state?, postal_code?, country? }` |
| `shipping` | `object` | N | `{ name, phone?, address }` |
| `payment_method` | `string` | N | Attach PM on creation |
| `source` | `string` | N | Legacy: token or source ID |
| `balance` | `number` | N | Initial balance in cents (positive = credit) |
| `invoice_prefix` | `string` | N | 3-12 uppercase chars for invoice numbers |
| `invoice_settings` | `object` | N | `{ default_payment_method?, custom_fields?, footer?, rendering_options? }` |
| `tax_exempt` | `string` | N | `'none'`, `'exempt'`, `'reverse'` |
| `tax_id_data` | `array` | N | `[{ type: 'eu_vat', value: 'DE123456789' }]` |
| `preferred_locales` | `string[]` | N | IETF language tags ordered by preference |
| `test_clock` | `string` | N | Test clock ID for subscription testing |

### Customer Object Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | `'cus_xxx'` |
| `object` | `'customer'` | |
| `balance` | `number` | Current balance in cents |
| `created` | `number` | Unix timestamp |
| `currency` | `string \| null` | Default currency |
| `default_source` | `string \| null` | Default payment source ID |
| `delinquent` | `boolean` | Has unpaid invoices |
| `description` | `string \| null` | |
| `discount` | `Stripe.Discount \| null` | Active discount |
| `email` | `string \| null` | |
| `invoice_prefix` | `string` | |
| `metadata` | `Record<string,string>` | |
| `name` | `string \| null` | |
| `phone` | `string \| null` | |
| `shipping` | `object \| null` | |
| `tax_exempt` | `string` | |

### Other Customer Methods

```typescript
stripe.customers.retrieve(id, params?)
stripe.customers.update(id, params)
stripe.customers.del(id)                // soft-delete
stripe.customers.list(params?)          // filter: email, created, limit
stripe.customers.search(params)         // { query: "email:'x@y.com'" }
```

---

## 3. Product

### Create Parameters (`Stripe.ProductCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `name` | `string` | Y | Display name |
| `active` | `boolean` | N | Default `true` |
| `description` | `string` | N | Long-form description |
| `id` | `string` | N | Custom ID (auto-generated if omitted) |
| `images` | `string[]` | N | Up to 8 image URLs |
| `metadata` | `Record<string,string>` | N | |
| `shippable` | `boolean` | N | Physical goods flag |
| `statement_descriptor` | `string` | N | Max 22 chars, uppercase only |
| `unit_label` | `string` | N | Max 12 chars (e.g., `'seat'`, `'GB'`) |
| `url` | `string` | N | Public product page URL |
| `default_price_data` | `object` | N | Create inline price: `{ currency, unit_amount, recurring? }` |
| `marketing_features` | `array` | N | Up to 15 feature strings for pricing tables |
| `package_dimensions` | `object` | N | `{ height, length, weight, width }` in inches/ounces |
| `tax_code` | `string` | N | Tax category ID |

### Other Product Methods

```typescript
stripe.products.retrieve(id, params?)
stripe.products.update(id, params)
stripe.products.del(id)
stripe.products.list(params?)    // filter: active, created, ids, limit
stripe.products.search(params)   // { query: "active:'true' AND name~'premium'" }
```

---

## 4. Price

### Create Parameters (`Stripe.PriceCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `currency` | `string` | Y | ISO 4217 lowercase |
| `unit_amount` | `number` | C | Required unless `billing_scheme=tiered` or `custom_unit_amount`. In smallest unit. |
| `unit_amount_decimal` | `string` | C | Decimal alternative (up to 12 places) |
| `product` | `string` | C | Product ID. Required unless `product_data` given. |
| `product_data` | `object` | C | Create product inline: `{ name, active?, metadata? }` |
| `recurring` | `object` | N | `{ interval, interval_count?, usage_type?, meter? }` |
| `active` | `boolean` | N | Default `true` |
| `billing_scheme` | `string` | N | `'per_unit'` (default) or `'tiered'` |
| `nickname` | `string` | N | Internal label |
| `metadata` | `Record<string,string>` | N | |
| `lookup_key` | `string` | N | Max 200 chars, for programmatic retrieval |
| `tiers` | `array` | C | Required if `billing_scheme=tiered`. `[{ up_to, unit_amount }]` |
| `tiers_mode` | `string` | C | Required if tiered. `'graduated'` or `'volume'` |
| `transform_quantity` | `object` | N | `{ divide_by, round: 'up'\|'down' }` |
| `tax_behavior` | `string` | N | `'inclusive'`, `'exclusive'`, `'unspecified'` |
| `custom_unit_amount` | `object` | N | `{ enabled, minimum?, maximum?, preset? }` |
| `transfer_lookup_key` | `boolean` | N | If `true`, transfers lookup_key from existing price |

### Recurring Object

```typescript
recurring: {
  interval: 'day' | 'week' | 'month' | 'year',  // required
  interval_count?: number,                         // default 1
  usage_type?: 'licensed' | 'metered',             // default 'licensed'
  meter?: string,                                  // meter ID for metered billing
}
```

### Other Price Methods

```typescript
stripe.prices.retrieve(id, params?)
stripe.prices.update(id, params)     // cannot change unit_amount or currency
stripe.prices.list(params?)          // filter: product, active, type, limit
stripe.prices.search(params)
```

---

## 5. Checkout Session

### Create Parameters (`Stripe.Checkout.SessionCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `mode` | `string` | Y | `'payment'`, `'subscription'`, `'setup'` |
| `line_items` | `array` | Y* | Required for payment/subscription. Each: `{ price, quantity }` or `{ price_data, quantity }` |
| `success_url` | `string` | Y | Redirect on success. Use `{CHECKOUT_SESSION_ID}` placeholder. |
| `cancel_url` | `string` | N | Redirect on cancel. |
| `customer` | `string` | N | Existing customer ID |
| `customer_email` | `string` | N | Pre-fill email (max 800 chars) |
| `metadata` | `Record<string,string>` | N | Attached to session |
| `payment_method_types` | `string[]` | N | Explicit methods: `['card', 'ideal']` |
| `payment_method_configuration` | `string` | N | PM config ID |
| `locale` | `string` | N | IETF tag: `'zh-TW'`, `'en'`, `'ja'` |
| `currency` | `string` | N | Override line item currency |
| `billing_address_collection` | `string` | N | `'auto'` or `'required'` |
| `shipping_address_collection` | `object` | N | `{ allowed_countries: ['TW','US'] }` |
| `allow_promotion_codes` | `boolean` | N | Let customer enter promo codes |
| `discounts` | `array` | N | Pre-applied: `[{ coupon: 'xxx' }]` or `[{ promotion_code: 'xxx' }]` |
| `automatic_tax` | `object` | N | `{ enabled: true }` |
| `expires_at` | `number` | N | Unix timestamp, min 30 min / max 24h from now |
| `payment_intent_data` | `object` | N | Customize PI: `{ metadata, capture_method, setup_future_usage, statement_descriptor }` |
| `subscription_data` | `object` | N | Customize sub: `{ trial_period_days, metadata, default_tax_rates }` |
| `invoice_creation` | `object` | N | `{ enabled: true, invoice_data?: { metadata } }` |
| `custom_fields` | `array` | N | Up to 3 fields: `{ key, label: { type: 'custom', custom: 'VAT Number' }, type: 'text' }` |
| `custom_text` | `object` | N | `{ submit?: { message }, shipping_address?: { message } }` |
| `consent_collection` | `object` | N | `{ promotions: 'auto', terms_of_service: 'required' }` |
| `after_expiration` | `object` | N | `{ recovery: { enabled: true, allow_promotion_codes: true } }` |

### Line Item with Price Data (Inline)

```typescript
line_items: [{
  price_data: {
    currency: 'twd',
    product_data: { name: 'Widget', description: 'A fine widget' },
    unit_amount: 50000,          // NT$500
    recurring: { interval: 'month' },  // only for subscription mode
  },
  quantity: 1,
  adjustable_quantity: {         // let customer change quantity
    enabled: true,
    minimum: 1,
    maximum: 10,
  },
}]
```

### Session Object Key Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | `'cs_xxx'` |
| `url` | `string \| null` | Redirect URL for customer |
| `payment_intent` | `string \| Stripe.PaymentIntent \| null` | For payment mode |
| `subscription` | `string \| Stripe.Subscription \| null` | For subscription mode |
| `customer` | `string \| Stripe.Customer \| null` | |
| `status` | `string` | `'open'`, `'complete'`, `'expired'` |
| `payment_status` | `string` | `'paid'`, `'unpaid'`, `'no_payment_required'` |
| `amount_total` | `number` | Total in cents |
| `currency` | `string` | |
| `metadata` | `Record<string,string>` | |

### Other Session Methods

```typescript
stripe.checkout.sessions.retrieve(id, params?)
stripe.checkout.sessions.update(id, params)
stripe.checkout.sessions.list(params?)
stripe.checkout.sessions.expire(id)
stripe.checkout.sessions.listLineItems(id, params?)
```

---

## 6. Subscription

### Create Parameters (`Stripe.SubscriptionCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `customer` | `string` | Y | Customer ID |
| `items` | `array` | Y | Up to 20 items: `[{ price, quantity? }]` |
| `default_payment_method` | `string` | N | PM ID |
| `default_source` | `string` | N | Legacy source |
| `cancel_at_period_end` | `boolean` | N | Default `false` |
| `trial_period_days` | `number` | N | Days of free trial |
| `trial_end` | `number \| 'now'` | N | Unix timestamp or `'now'` |
| `metadata` | `Record<string,string>` | N | |
| `payment_behavior` | `string` | N | `'allow_incomplete'`, `'default_incomplete'`, `'error_if_incomplete'`, `'pending_if_incomplete'` |
| `collection_method` | `string` | N | `'charge_automatically'` (default), `'send_invoice'` |
| `currency` | `string` | N | ISO 4217 |
| `description` | `string` | N | Max 500 chars |
| `billing_cycle_anchor` | `number` | N | Unix timestamp for billing anchor |
| `cancel_at` | `number` | N | Future cancellation timestamp |
| `automatic_tax` | `object` | N | `{ enabled: true }` |
| `discounts` | `array` | N | `[{ coupon: 'xxx' }]` |
| `off_session` | `boolean` | N | Merchant-initiated |
| `days_until_due` | `number` | N | For send_invoice |
| `proration_behavior` | `string` | N | `'create_prorations'`, `'always_invoice'`, `'none'` |
| `payment_settings` | `object` | N | PM options, types |
| `transfer_data` | `object` | N | Connect: `{ destination, amount_percent? }` |

### Update Parameters (additional to create)

| Parameter | Type | Description |
|-----------|------|-------------|
| `items` | `array` | Must include `id` for existing items: `[{ id: 'si_xxx', quantity: 2 }]` |
| `proration_behavior` | `string` | How to handle prorations on changes |
| `proration_date` | `number` | Custom proration calculation timestamp |
| `billing_cycle_anchor` | `'now' \| 'unchanged'` | |
| `pause_collection` | `object \| ''` | `{ behavior: 'mark_uncollectible' \| 'keep_as_draft' \| 'void' }` or `''` to resume |

### Cancel Parameters

```typescript
stripe.subscriptions.cancel(id, {
  cancellation_details?: {
    feedback?: 'too_expensive' | 'missing_features' | 'switched_service' |
               'low_quality' | 'customer_service' | 'too_complex' | 'unused' | 'other',
    comment?: string,
  },
  invoice_now?: boolean,     // generate final invoice
  prorate?: boolean,         // credit unused time
})
```

### Subscription Status Values

| Status | Meaning |
|--------|---------|
| `incomplete` | Initial payment failed, awaiting action (23h window) |
| `incomplete_expired` | Initial payment not resolved in 23h |
| `trialing` | In trial period |
| `active` | Paid and current |
| `past_due` | Latest invoice unpaid, retrying |
| `unpaid` | All retry attempts exhausted |
| `canceled` | Terminated |
| `paused` | Collection paused (if enabled) |

### Other Subscription Methods

```typescript
stripe.subscriptions.retrieve(id, params?)
stripe.subscriptions.update(id, params)
stripe.subscriptions.cancel(id, params?)    // preferred over del()
stripe.subscriptions.del(id, params?)       // alias for cancel
stripe.subscriptions.list(params?)          // filter: customer, status, price, limit
stripe.subscriptions.search(params)
stripe.subscriptions.resume(id, params?)    // resume paused sub
```

---

## 7. Refund

### Create Parameters (`Stripe.RefundCreateParams`)

| Parameter | Type | Req | Description |
|-----------|------|-----|-------------|
| `payment_intent` | `string` | C | PI ID. One of `payment_intent` or `charge` required. |
| `charge` | `string` | C | Charge ID (legacy). |
| `amount` | `number` | N | Partial refund amount in cents. Default = full refund. |
| `reason` | `string` | N | `'duplicate'`, `'fraudulent'`, `'requested_by_customer'` |
| `metadata` | `Record<string,string>` | N | |
| `instructions_email` | `string` | N | Email for refund instructions |
| `refund_application_fee` | `boolean` | N | Connect: refund app fee proportionally |
| `reverse_transfer` | `boolean` | N | Connect: reverse transfer proportionally |
| `origin` | `string` | N | `'customer_balance'` for balance refunds |

### Refund Object Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | `'re_xxx'` |
| `amount` | `number` | Refunded amount in cents |
| `charge` | `string` | Associated charge |
| `payment_intent` | `string` | Associated PI |
| `currency` | `string` | |
| `created` | `number` | Unix timestamp |
| `metadata` | `Record<string,string>` | |
| `reason` | `string \| null` | |
| `status` | `string` | `'succeeded'`, `'pending'`, `'failed'`, `'canceled'`, `'requires_action'` |
| `balance_transaction` | `string \| null` | |
| `failure_reason` | `string \| null` | If status is `'failed'` |

### Other Refund Methods

```typescript
stripe.refunds.retrieve(id, params?)
stripe.refunds.update(id, params)       // only metadata
stripe.refunds.list(params?)            // filter: charge, payment_intent, created, limit
stripe.refunds.cancel(id)               // only if status='pending'
```

---

## 8. Auto-Pagination API

All `.list()` methods return a `Stripe.ApiList<T>` with pagination helpers:

```typescript
// Properties
list.data          // T[] -- current page
list.has_more      // boolean -- more pages available
list.url           // string -- API endpoint
list.object        // 'list'

// Method 1: for-await-of (recommended)
for await (const item of stripe.customers.list({ limit: 100 })) {
  // iterates through ALL pages automatically
}

// Method 2: autoPagingEach
await stripe.customers.list().autoPagingEach(async (customer) => {
  await process(customer);
  if (done) return false;  // stop iteration
});

// Method 3: autoPagingToArray (with safety limit)
const all = await stripe.customers.list({ limit: 100 })
  .autoPagingToArray({ limit: 10_000 });

// Method 4: Manual pagination
let params: Stripe.CustomerListParams = { limit: 100 };
let hasMore = true;
while (hasMore) {
  const page = await stripe.customers.list(params);
  for (const c of page.data) { /* process */ }
  hasMore = page.has_more;
  if (hasMore) params = { ...params, starting_after: page.data[page.data.length - 1].id };
}
```

---

## 9. Amount Handling & Currency

Stripe always uses the **smallest currency unit**:

| Currency | Unit | Multiplier | Example |
|----------|------|-----------|---------|
| USD | cents | ×100 | `2000` = $20.00 |
| **TWD** | **cents (NOT zero-decimal)** | **×100** | `99900` = NT$999 |
| JPY | yen (zero-decimal) | ×1 | `1000` = ¥1,000 |
| EUR | cents | ×100 | `1050` = €10.50 |

> ### ⚠️ TWD is NOT a zero-decimal currency
>
> **An earlier revision of this table claimed `TWD | dollars (zero-decimal) | 999 = NT$999`. That was wrong**, and it directly caused a production bug: a refund handler copied the claim into a code comment (“TWD 為 zero-decimal currency，amount 直接以整數『元』為單位”) and passed `Math.round(amount)` instead of `Math.round(amount * 100)`, so **every Stripe refund returned only 1/100 of the intended amount**. Stripe accepted it as a legal *partial* refund, returned `succeeded`, and raised no error — the loss was completely silent.
>
> The table was also **self-contradictory**: the zero-decimal list below has never contained TWD, and `toStripeAmount()` below has always multiplied TWD by 100.
>
> **The rule:** for `charge` / `payment_intent` / `refund`, TWD is an ordinary two-decimal currency → **always ×100**. TWD is treated as zero-decimal *only in payouts* (Stripe pays merchants out in whole dollars), which is not an API amount you ever construct.
>
> Never hand-write `* 100` / `/ 100`. Use `toStripeAmount()` / `fromStripeAmount()` and keep **one** converter shared by the create-PaymentIntent path and the refund path — a unit mismatch between those two is exactly how this class of bug survives.

### Zero-Decimal Currencies (no multiplication by 100)

BIF, CLP, DJF, GNF, JPY, KMF, KRW, MGA, PYG, RWF, UGX, VND, VUV, XAF, XOF, XPF

**TWD, USD, EUR, GBP, HKD, CNY, SGD… are all two-decimal → ×100.**

### Conversion Helper

```typescript
const ZERO_DECIMAL = new Set([
  'bif','clp','djf','gnf','jpy','kmf','krw','mga',
  'pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf',
]); // ← note: no 'twd'

/** Major unit (dollars) → Stripe minor unit. TWD/USD → ×100; JPY → ×1. */
function toStripeAmount(amount: number, currency: string): number {
  const factor = ZERO_DECIMAL.has(currency.toLowerCase()) ? 1 : 100;
  return Math.round(amount * factor);
}

/** Stripe minor unit → major unit. Use when reading `paymentIntent.amount`. */
function fromStripeAmount(minorAmount: number, currency: string): number {
  const factor = ZERO_DECIMAL.has(currency.toLowerCase()) ? 1 : 100;
  return minorAmount / factor;
}
```

---

## 10. Expanding Responses

Many fields are IDs by default but can be expanded to full objects:

```typescript
// Single expand
const pi = await stripe.paymentIntents.retrieve('pi_xxx', {
  expand: ['customer', 'payment_method'],
});
// pi.customer is now Stripe.Customer, not string

// Nested expand
const invoice = await stripe.invoices.retrieve('in_xxx', {
  expand: ['subscription.default_payment_method'],
});

// On list
const list = await stripe.paymentIntents.list({
  limit: 10,
  expand: ['data.customer'],
});

// On create
const pi = await stripe.paymentIntents.create({
  amount: 2000,
  currency: 'usd',
  expand: ['latest_charge'],
});
```

### TypeScript Handling of Expandable Fields

```typescript
// Stripe types expandable as: string | Stripe.Customer | null
// After expanding, cast or check:
const pi = await stripe.paymentIntents.retrieve('pi_xxx', { expand: ['customer'] });
if (typeof pi.customer === 'object' && pi.customer !== null) {
  console.log(pi.customer.email);  // safe access
}
// OR cast (if you know it's expanded):
const email = (pi.customer as Stripe.Customer).email;
```

---

## 11. Metadata

- Up to **50 keys** per object
- Key: max **40** characters
- Value: max **500** characters
- Values are always strings
- Useful for linking Stripe objects to your application data

```typescript
// Set metadata
await stripe.paymentIntents.create({
  amount: 2000,
  currency: 'usd',
  metadata: {
    orderId: 'order_123',
    userId: 'user_456',
    sessionToken: 'tok_abc',
  },
});

// Update specific key (other keys preserved)
await stripe.paymentIntents.update('pi_xxx', {
  metadata: { status: 'shipped' },
});

// Remove a key
await stripe.paymentIntents.update('pi_xxx', {
  metadata: { status: '' },  // empty string removes key
});

// Clear all metadata
await stripe.paymentIntents.update('pi_xxx', {
  metadata: {},  // empty object clears all
});
```

---

## 12. Search API

Available on Customers, PaymentIntents, Subscriptions, Invoices, Products, Prices, Charges:

```typescript
const results = await stripe.customers.search({
  query: "email:'jenny@example.com'",
  limit: 10,
});

// Query syntax:
// Exact match:  field:'value'
// Prefix:       field~'prefix'
// Numeric:      field>100  field>=100  field<100  field<=100
// AND/OR:       field1:'x' AND field2:'y'
//               field1:'x' OR field2:'y'
// Negation:     -field:'value'
// Metadata:     metadata['key']:'value'
// NULL check:   field:null  -field:null

// Examples:
"email:'user@example.com' AND metadata['orderId']:'123'"
"created>1672531200 AND status:'active'"
"name~'Jenny'"
```

**Search result** returns `Stripe.ApiSearchResult<T>` with `data`, `has_more`, `next_page`, `url`.
Use `page` parameter for pagination (not `starting_after`).
