# Stripe Webhooks & NestJS Integration Patterns

Detailed webhook event reference, NestJS integration patterns, error handling strategies,
and production best practices.

---

## Table of Contents

1. [Webhook Event Reference](#1-webhook-event-reference)
2. [Signature Verification Deep Dive](#2-signature-verification-deep-dive)
3. [NestJS Webhook Integration](#3-nestjs-webhook-integration)
4. [Error Handling Patterns](#4-error-handling-patterns)
5. [Subscription Lifecycle Webhooks](#5-subscription-lifecycle-webhooks)
6. [Production Best Practices](#6-production-best-practices)
7. [Testing Strategies](#7-testing-strategies)
8. [Stripe CLI Reference](#8-stripe-cli-reference)
9. [TypeScript Import Patterns for v22](#9-typescript-import-patterns-for-v22)

---

## 1. Webhook Event Reference

### Payment Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `payment_intent.created` | PI created | `Stripe.PaymentIntent` |
| `payment_intent.succeeded` | Payment captured | `Stripe.PaymentIntent` |
| `payment_intent.payment_failed` | Payment attempt failed | `Stripe.PaymentIntent` |
| `payment_intent.canceled` | PI canceled | `Stripe.PaymentIntent` |
| `payment_intent.processing` | Payment processing (async) | `Stripe.PaymentIntent` |
| `payment_intent.requires_action` | Customer action needed (3DS) | `Stripe.PaymentIntent` |
| `payment_intent.amount_capturable_updated` | Manual capture ready | `Stripe.PaymentIntent` |
| `payment_intent.partially_funded` | Partial payment received | `Stripe.PaymentIntent` |

### Checkout Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `checkout.session.completed` | Customer finished checkout | `Stripe.Checkout.Session` |
| `checkout.session.expired` | Session expired (unused) | `Stripe.Checkout.Session` |
| `checkout.session.async_payment_succeeded` | Delayed payment confirmed | `Stripe.Checkout.Session` |
| `checkout.session.async_payment_failed` | Delayed payment failed | `Stripe.Checkout.Session` |

### Subscription Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `customer.subscription.created` | New sub created | `Stripe.Subscription` |
| `customer.subscription.updated` | Sub changed (status, plan, quantity) | `Stripe.Subscription` |
| `customer.subscription.deleted` | Sub canceled/expired | `Stripe.Subscription` |
| `customer.subscription.trial_will_end` | Trial ends in 3 days | `Stripe.Subscription` |
| `customer.subscription.paused` | Sub paused | `Stripe.Subscription` |
| `customer.subscription.resumed` | Sub resumed from pause | `Stripe.Subscription` |
| `customer.subscription.pending_update_applied` | Pending update applied | `Stripe.Subscription` |
| `customer.subscription.pending_update_expired` | Pending update expired | `Stripe.Subscription` |

### Invoice Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `invoice.created` | Invoice generated | `Stripe.Invoice` |
| `invoice.finalized` | Invoice finalized | `Stripe.Invoice` |
| `invoice.paid` | Invoice fully paid | `Stripe.Invoice` |
| `invoice.payment_failed` | Invoice payment failed | `Stripe.Invoice` |
| `invoice.payment_succeeded` | Invoice payment succeeded | `Stripe.Invoice` |
| `invoice.voided` | Invoice voided | `Stripe.Invoice` |
| `invoice.upcoming` | Upcoming invoice (for sub renewal) | `Stripe.Invoice` |
| `invoice.payment_action_required` | Customer action needed | `Stripe.Invoice` |

### Charge Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `charge.succeeded` | Charge captured | `Stripe.Charge` |
| `charge.failed` | Charge failed | `Stripe.Charge` |
| `charge.refunded` | Charge refunded (full or partial) | `Stripe.Charge` |
| `charge.dispute.created` | Customer filed dispute | `Stripe.Dispute` |
| `charge.dispute.closed` | Dispute resolved | `Stripe.Dispute` |
| `charge.dispute.updated` | Dispute status changed | `Stripe.Dispute` |

### Customer Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `customer.created` | Customer created | `Stripe.Customer` |
| `customer.updated` | Customer data changed | `Stripe.Customer` |
| `customer.deleted` | Customer deleted | `Stripe.Customer` |

### Product & Price Events

| Event | When | `data.object` Type |
|-------|------|---------------------|
| `product.created` | Product created | `Stripe.Product` |
| `product.updated` | Product changed | `Stripe.Product` |
| `product.deleted` | Product deleted | `Stripe.Product` |
| `price.created` | Price created | `Stripe.Price` |
| `price.updated` | Price changed | `Stripe.Price` |
| `price.deleted` | Price deleted | `Stripe.Price` |

### Event Object Structure

```typescript
interface StripeEvent {
  id: string;              // 'evt_xxx'
  object: 'event';
  type: string;            // e.g., 'payment_intent.succeeded'
  created: number;         // Unix timestamp
  livemode: boolean;
  pending_webhooks: number; // remaining undelivered
  request: {
    id: string | null;     // API request that triggered event
    idempotency_key: string | null;
  } | null;
  data: {
    object: object;        // The resource (PaymentIntent, Subscription, etc.)
    previous_attributes?: object;  // Changed fields (on *.updated events)
  };
  api_version: string;    // API version when event was generated
}
```

---

## 2. Signature Verification Deep Dive

### How It Works

Stripe signs each webhook payload using HMAC-SHA256:

1. Stripe creates a signed payload: `{timestamp}.{raw_body}`
2. Computes HMAC-SHA256 with your endpoint's signing secret
3. Sends the signature in `Stripe-Signature` header:
   ```
   t=1614556800,v1=abc123...def456,v0=test_signature
   ```

### The `constructEvent` Method

```typescript
// Signature
stripe.webhooks.constructEvent(
  payload: string | Buffer,   // raw request body, NOT parsed JSON
  header: string,              // stripe-signature header value
  secret: string,              // whsec_... from Dashboard
  tolerance?: number,          // max age in seconds (default 300 = 5 min)
): Stripe.Event;

// Throws: Stripe.errors.StripeSignatureVerificationError
```

### Why Raw Body Matters

`constructEvent` hashes the exact bytes Stripe sent. If your framework parses the body
to JSON and re-serializes it, whitespace/ordering changes will cause signature mismatch.

**Express**: Use `express.raw({ type: 'application/json' })` on the webhook route.
**NestJS 10+**: Enable `rawBody: true` in `NestFactory.create` options.

### Timestamp Tolerance

By default, events older than 5 minutes (300 seconds) are rejected to prevent replay attacks.
Override with the 4th argument:

```typescript
const event = stripe.webhooks.constructEvent(body, sig, secret, 600); // 10 min tolerance
```

---

## 3. NestJS Webhook Integration

### Complete Webhook Module

```typescript
// stripe.module.ts
import { Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { StripeController } from './stripe.controller';
import { StripeService } from './stripe.service';
import { Order } from '../orders/entities/order.entity';
import { OrdersModule } from '../orders/orders.module';
import { SettingsModule } from '../../settings/settings.module';

@Module({
  imports: [
    TypeOrmModule.forFeature([Order]),
    OrdersModule,
    SettingsModule,
  ],
  controllers: [StripeController],
  providers: [StripeService],
  exports: [StripeService],
})
export class StripeModule {}
```

### Controller with Raw Body Access

```typescript
// stripe.controller.ts
import {
  Controller, Post, Req, Res, Headers,
  HttpCode, BadRequestException, Logger,
} from '@nestjs/common';
import { Request, Response } from 'express';
import { StripeService } from './stripe.service';

interface RequestWithRawBody extends Request {
  rawBody?: Buffer;
}

@Controller('commerce/payments/stripe')
export class StripeController {
  private readonly logger = new Logger(StripeController.name);

  constructor(private readonly stripe: StripeService) {}

  @Post('webhook')
  @HttpCode(200)
  async webhook(
    @Req() req: RequestWithRawBody,
    @Res() res: Response,
    @Headers('stripe-signature') signature: string,
  ) {
    if (!signature) throw new BadRequestException('Missing stripe-signature header');
    if (!req.rawBody) throw new BadRequestException('Raw body not available');

    try {
      await this.stripe.handleWebhook(req.rawBody, signature);
      res.json({ received: true });
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      this.logger.error(`Stripe webhook error: ${msg}`);
      res.status(400).json({ error: 'Webhook processing failed' });
    }
  }
}
```

### Service with Event Router

```typescript
// stripe.service.ts
import { Injectable, Logger, BadRequestException, ConflictException } from '@nestjs/common';
import Stripe from 'stripe';
import { SettingsService } from '../../settings/settings.service';

@Injectable()
export class StripeService {
  private readonly logger = new Logger(StripeService.name);

  constructor(private readonly settings: SettingsService) {}

  private async getStripe(): Promise<Stripe> {
    const key = await this.settings.get('payment.stripe_secret_key') as string | null;
    if (!key) throw new ConflictException('Stripe not configured');
    return new Stripe(key);
  }

  async handleWebhook(rawBody: Buffer, signature: string): Promise<void> {
    const webhookSecret = await this.settings.get('payment.stripe_webhook_secret') as string | null;
    if (!webhookSecret) throw new BadRequestException('Webhook secret not configured');

    const stripe = await this.getStripe();

    let event: Stripe.Event;
    try {
      event = stripe.webhooks.constructEvent(rawBody, signature, webhookSecret);
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      this.logger.warn(`Webhook signature verification failed: ${msg}`);
      throw new BadRequestException('Invalid webhook signature');
    }

    // Route to handler by event type
    switch (event.type) {
      case 'payment_intent.succeeded':
        await this.onPaymentSucceeded(event.data.object as Stripe.PaymentIntent);
        break;
      case 'payment_intent.payment_failed':
        await this.onPaymentFailed(event.data.object as Stripe.PaymentIntent);
        break;
      case 'checkout.session.completed':
        await this.onCheckoutCompleted(event.data.object as Stripe.Checkout.Session);
        break;
      case 'customer.subscription.created':
      case 'customer.subscription.updated':
      case 'customer.subscription.deleted':
        await this.onSubscriptionChange(event.type, event.data.object as Stripe.Subscription);
        break;
      case 'invoice.paid':
        await this.onInvoicePaid(event.data.object as Stripe.Invoice);
        break;
      case 'invoice.payment_failed':
        await this.onInvoicePaymentFailed(event.data.object as Stripe.Invoice);
        break;
      case 'charge.refunded':
        await this.onChargeRefunded(event.data.object as Stripe.Charge);
        break;
      case 'charge.dispute.created':
        await this.onDisputeCreated(event.data.object as Stripe.Dispute);
        break;
      default:
        this.logger.log(`Unhandled event type: ${event.type}`);
    }
  }

  private async onPaymentSucceeded(pi: Stripe.PaymentIntent): Promise<void> {
    const sessionToken = pi.metadata?.sessionToken;
    if (!sessionToken) {
      this.logger.warn('payment_intent.succeeded missing sessionToken metadata');
      return;
    }
    // Process order fulfillment...
    this.logger.log(`Payment succeeded: ${pi.id}`);
  }

  private async onPaymentFailed(pi: Stripe.PaymentIntent): Promise<void> {
    const reason = pi.last_payment_error?.message
      ?? pi.last_payment_error?.code
      ?? 'unknown';
    this.logger.warn(`Payment failed ${pi.id}: ${reason}`);
    // Notify admin, update order status...
  }

  // ... implement other handlers
}
```

### Enable Raw Body in main.ts

```typescript
// main.ts -- NestJS 10+ built-in raw body support
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule, {
    rawBody: true,  // makes req.rawBody available on all routes
  });

  // Alternative: raw body only for webhook route (Express-level)
  // const expressApp = app.getHttpAdapter().getInstance();
  // expressApp.use('/v1/commerce/payments/stripe/webhook',
  //   require('express').raw({ type: 'application/json' }),
  // );

  await app.listen(6011);
}
bootstrap();
```

---

## 4. Error Handling Patterns

### Comprehensive Error Handler for NestJS

```typescript
import Stripe from 'stripe';

async function handleStripeOperation<T>(
  operation: () => Promise<T>,
  context: string,
  logger: Logger,
): Promise<T> {
  try {
    return await operation();
  } catch (err) {
    if (err instanceof Stripe.errors.StripeCardError) {
      logger.warn(`Card error in ${context}: ${err.code} - ${err.message}`);
      throw new BadRequestException({
        error: 'card_error',
        code: err.code,
        decline_code: err.decline_code,
        message: err.message,
      });
    }
    if (err instanceof Stripe.errors.StripeInvalidRequestError) {
      logger.error(`Invalid request in ${context}: ${err.message} (param: ${err.param})`);
      throw new BadRequestException({
        error: 'invalid_request',
        message: err.message,
        param: err.param,
      });
    }
    if (err instanceof Stripe.errors.StripeRateLimitError) {
      logger.warn(`Rate limited in ${context}`);
      throw new ServiceUnavailableException('Payment service rate limited, please retry');
    }
    if (err instanceof Stripe.errors.StripeAuthenticationError) {
      logger.error(`Stripe authentication failed in ${context}`);
      throw new InternalServerErrorException('Payment service configuration error');
    }
    if (err instanceof Stripe.errors.StripeConnectionError) {
      logger.error(`Stripe connection failed in ${context}`);
      throw new ServiceUnavailableException('Payment service temporarily unavailable');
    }
    if (err instanceof Stripe.errors.StripeAPIError) {
      logger.error(`Stripe API error in ${context}: ${err.message}`);
      throw new ServiceUnavailableException('Payment service error');
    }
    // Unknown error
    logger.error(`Unexpected error in ${context}: ${err}`);
    throw err;
  }
}

// Usage:
const pi = await handleStripeOperation(
  () => stripe.paymentIntents.create({ amount: 2000, currency: 'usd' }),
  'createPaymentIntent',
  this.logger,
);
```

### Common Error Codes (card_error)

| Code | Decline Code | Meaning |
|------|-------------|---------|
| `card_declined` | `generic_decline` | General decline |
| `card_declined` | `insufficient_funds` | Not enough funds |
| `card_declined` | `lost_card` | Card reported lost |
| `card_declined` | `stolen_card` | Card reported stolen |
| `card_declined` | `fraudulent` | Suspected fraud |
| `expired_card` | - | Card is expired |
| `incorrect_cvc` | - | CVC check failed |
| `incorrect_number` | - | Card number invalid |
| `processing_error` | - | Stripe processing issue |
| `authentication_required` | - | 3DS authentication needed |

---

## 5. Subscription Lifecycle Webhooks

### Recommended Event Flow for Subscription Management

```
1. customer.subscription.created      -> Provision access
2. customer.subscription.trial_will_end -> Notify customer (3 days before)
3. invoice.paid                        -> Confirm renewal
4. invoice.payment_failed              -> Notify, retry logic
5. customer.subscription.updated       -> Check status changes
   - status: 'past_due'               -> Restrict access, notify
   - status: 'unpaid'                 -> Suspend access
6. customer.subscription.deleted       -> Revoke access
```

### Handling Subscription Status Changes

```typescript
private async onSubscriptionChange(
  eventType: string,
  sub: Stripe.Subscription,
): Promise<void> {
  const customerId = typeof sub.customer === 'string'
    ? sub.customer
    : sub.customer.id;

  switch (sub.status) {
    case 'active':
      await this.grantAccess(customerId, sub.id);
      break;
    case 'trialing':
      await this.grantAccess(customerId, sub.id);
      break;
    case 'past_due':
      await this.notifyPaymentRequired(customerId, sub.id);
      break;
    case 'unpaid':
    case 'canceled':
    case 'incomplete_expired':
      await this.revokeAccess(customerId, sub.id);
      break;
    case 'paused':
      await this.pauseAccess(customerId, sub.id);
      break;
  }
}
```

---

## 6. Production Best Practices

### 1. Return 200 Immediately, Process Async

```typescript
// In NestJS: enqueue to BullMQ, respond immediately
@Post('webhook')
@HttpCode(200)
async webhook(@Req() req, @Res() res, @Headers('stripe-signature') sig) {
  const event = stripe.webhooks.constructEvent(req.rawBody, sig, secret);

  // Enqueue for async processing
  await this.webhookQueue.add('stripe-event', event, {
    attempts: 3,
    backoff: { type: 'exponential', delay: 2000 },
    removeOnComplete: 100,
    removeOnFail: 200,
  });

  res.json({ received: true });
}
```

### 2. Idempotent Event Processing

```typescript
// Use event ID + type as dedup key
async processEvent(event: Stripe.Event): Promise<void> {
  const dedupKey = `stripe_event:${event.id}`;

  // Check Redis/DB for already-processed events
  const processed = await this.redis.get(dedupKey);
  if (processed) {
    this.logger.log(`Event ${event.id} already processed, skipping`);
    return;
  }

  // Process...
  await this.handleEvent(event);

  // Mark as processed (expire after 48h to handle late retries)
  await this.redis.set(dedupKey, '1', 'EX', 48 * 3600);
}
```

### 3. Verify Object State Before Acting

Events may arrive out of order. Always fetch the latest state:

```typescript
private async onPaymentSucceeded(pi: Stripe.PaymentIntent): Promise<void> {
  const stripe = await this.getStripe();

  // Fetch latest state to avoid acting on stale data
  const latest = await stripe.paymentIntents.retrieve(pi.id);

  if (latest.status !== 'succeeded') {
    this.logger.warn(`PI ${pi.id} status is ${latest.status}, not succeeded. Skipping.`);
    return;
  }

  // Now safe to fulfill
}
```

### 4. Webhook Secret Rotation

- Rotate secrets periodically via Dashboard > Webhooks > Roll secret
- Stripe supports a grace period (up to 24h) where both old and new secrets work
- `constructEvent` tries both signatures during the grace period

### 5. API Key Security

```typescript
// NEVER log or expose API keys
// Store in environment variables or secure settings table
// Use restricted keys with minimum required permissions when possible

// For multi-tenant: store per-tenant keys encrypted
// For single-tenant (like ZenbuCart): store in settings table
```

### 6. Metadata Convention

Link Stripe objects to your domain:

```typescript
// On PaymentIntent creation
metadata: {
  sessionToken: 'checkout_session_token',  // your checkout session
  orderId: 'order_uuid',                   // if known at creation time
  source: 'web_checkout',                  // origin tracking
}

// On Subscription creation
metadata: {
  userId: 'user_uuid',
  plan: 'premium',
  source: 'upgrade_flow',
}

// On Checkout Session
metadata: {
  cartId: 'cart_uuid',
  promoCode: 'SAVE20',
}
```

---

## 7. Testing Strategies

### Unit Testing Webhook Handler

```typescript
import Stripe from 'stripe';

describe('StripeService', () => {
  let service: StripeService;
  const stripe = new Stripe('sk_test_fake');

  it('should verify webhook signature and process event', async () => {
    const payload = JSON.stringify({
      id: 'evt_test_123',
      object: 'event',
      type: 'payment_intent.succeeded',
      data: {
        object: {
          id: 'pi_test_123',
          object: 'payment_intent',
          amount: 2000,
          currency: 'usd',
          status: 'succeeded',
          metadata: { sessionToken: 'tok_abc' },
        },
      },
    });

    const secret = 'whsec_test_secret';
    const header = stripe.webhooks.generateTestHeaderString({
      payload,
      secret,
    });

    // Call the service method with generated test data
    await service.handleWebhook(Buffer.from(payload), header);

    // Assert side effects (order created, email sent, etc.)
  });
});
```

### Integration Testing with Stripe CLI

```bash
# Terminal 1: Start your NestJS app
npm run start:dev

# Terminal 2: Forward Stripe events to local webhook
stripe listen --forward-to localhost:6011/v1/commerce/payments/stripe/webhook

# Terminal 3: Trigger test events
stripe trigger payment_intent.succeeded
stripe trigger payment_intent.payment_failed
stripe trigger checkout.session.completed
stripe trigger customer.subscription.created
stripe trigger customer.subscription.deleted
stripe trigger charge.refunded
stripe trigger invoice.paid
stripe trigger invoice.payment_failed
```

### Test Mode PaymentMethod Tokens

For server-side testing without real card input:

```typescript
// Successful payments
'pm_card_visa'                        // Visa success
'pm_card_mastercard'                  // MC success
'pm_card_amex'                        // Amex success

// Decline scenarios
'pm_card_chargeDeclined'              // Generic decline
'pm_card_chargeDeclinedFraudulent'    // Fraud decline
'pm_card_chargeDeclinedInsufficientFunds'  // Insufficient funds
'pm_card_chargeDeclinedExpiredCard'   // Expired card
'pm_card_chargeDeclinedProcessingError' // Processing error

// 3D Secure
'pm_card_threeDSecure2Required'       // 3DS required (will complete in test)
'pm_card_threeDSecureRequired'        // 3DS required (legacy)

// Country-specific
'pm_card_us'     // US card
'pm_card_gb'     // UK card
'pm_card_jp'     // Japan card
'pm_card_tw'     // Taiwan card (if available)
```

---

## 8. Stripe CLI Reference

### Installation

```bash
# macOS
brew install stripe/stripe-cli/stripe

# Windows (scoop)
scoop install stripe

# npm (global)
npm install -g @stripe/cli
```

### Common Commands

```bash
# Authenticate
stripe login

# Listen for webhooks
stripe listen --forward-to localhost:6011/v1/commerce/payments/stripe/webhook

# Listen for specific events only
stripe listen --events payment_intent.succeeded,payment_intent.payment_failed,checkout.session.completed \
  --forward-to localhost:6011/v1/commerce/payments/stripe/webhook

# Trigger test events
stripe trigger payment_intent.succeeded
stripe trigger checkout.session.completed
stripe trigger customer.subscription.created

# View recent events
stripe events list --limit 10

# Resend an event
stripe events resend evt_xxx --webhook-endpoint=we_xxx

# View logs
stripe logs tail

# Create test resources
stripe products create --name="Test Product"
stripe prices create --product=prod_xxx --unit-amount=2000 --currency=usd
stripe customers create --email=test@example.com
```

### Webhook Signing Secret from CLI

When using `stripe listen`, it outputs a local signing secret:
```
> Ready! Your webhook signing secret is whsec_abc123... (^C to quit)
```
Use this secret in your `.env` for local testing.

---

## 9. TypeScript Import Patterns for v22

### Default Import (ESM)

```typescript
import Stripe from 'stripe';

const stripe = new Stripe('sk_test_...');
```

### Type-Only Imports

```typescript
import type Stripe from 'stripe';

// Use for type annotations without importing the runtime class
function processPayment(pi: Stripe.PaymentIntent): void {
  console.log(pi.id);
}
```

### Accessing Nested Types

```typescript
// stripe/cjs/stripe.core exports the core type
// But prefer the namespace approach:
import Stripe from 'stripe';

type PaymentIntent = Stripe.PaymentIntent;
type Customer = Stripe.Customer;
type Subscription = Stripe.Subscription;
type Event = Stripe.Event;
type CheckoutSession = Stripe.Checkout.Session;
type Invoice = Stripe.Invoice;
type Charge = Stripe.Charge;
type Refund = Stripe.Refund;
type Product = Stripe.Product;
type Price = Stripe.Price;

// Parameter types
type PICreateParams = Stripe.PaymentIntentCreateParams;
type PIListParams = Stripe.PaymentIntentListParams;
type CustomerCreateParams = Stripe.CustomerCreateParams;
type SessionCreateParams = Stripe.Checkout.SessionCreateParams;
type SubCreateParams = Stripe.SubscriptionCreateParams;
type RefundCreateParams = Stripe.RefundCreateParams;

// Error types
type StripeError = Stripe.errors.StripeError;
type CardError = Stripe.errors.StripeCardError;
type InvalidReqError = Stripe.errors.StripeInvalidRequestError;
type AuthError = Stripe.errors.StripeAuthenticationError;
type RateLimitError = Stripe.errors.StripeRateLimitError;
type APIError = Stripe.errors.StripeAPIError;
type ConnError = Stripe.errors.StripeConnectionError;
type IdempotencyError = Stripe.errors.StripeIdempotencyError;
type SigVerifyError = Stripe.errors.StripeSignatureVerificationError;
```

### Example Project Pattern

A common import pattern for type compatibility:

```typescript
// Runtime import
import StripeLib from 'stripe';

// Type import from core (for use as type annotations)
import type { Stripe as StripeCore } from 'stripe/cjs/stripe.core';

// Type aliases
type StripeEvent = StripeCore.Event;
type StripePaymentIntent = StripeCore.PaymentIntent;

// Instantiation
const stripe: StripeCore = new StripeLib(secretKey);
```

This pattern is used because the project pins TypeScript 5.4.5 for NestJS 10
decorator metadata compatibility, and this import style resolves certain type
inference issues with that specific TS version.
