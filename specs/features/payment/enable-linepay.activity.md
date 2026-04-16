# Activity: Enable LINE Pay Payment Method

## Summary

Shopline Payment (SLP) has opened LINE Pay support. Remove all artificial restrictions
that were preventing LINE Pay from being used in Power Checkout.

## Scope

This is a **configuration unlock** -- LINE Pay was already defined in the backend enum
(`PaymentMethod::LINEPAY`) and frontend enum (`EPaymentMethods.LINE_PAY`). The changes
are limited to removing disabled flags, adjusting UI messaging, and updating defaults.

## Changes

### Frontend (Vue 3)

1. **`js/src/pages/Payments/SLP/Shared/types.ts`**
   - Change `disabled: true` to `disabled: false` for LINE_PAY entry
   - Change `tooltip` from the "尚未開放" message to `undefined`

2. **`js/src/pages/Payments/SLP/index.vue`**
   - Change LINE Pay `el-alert` from `type="error"` to `type="warning"`
   - Change alert title to: `請先確認已在 SLP 後台啟用 LINE Pay`

### Backend (PHP)

3. **`inc/classes/Domains/Payment/ShoplinePayment/DTOs/RedirectSettingsDTO.php`**
   - Uncomment `'LinePay'` in the `$allowPaymentMethodList` default array

4. **`inc/classes/Domains/Payment/ShoplinePayment/DTOs/Components/PaymentMethodOptions.php`**
   - Update class docblock: change "暫不支援設定" to "不需要設定"

5. **`inc/classes/Domains/Payment/ShoplinePayment/DTOs/Trade/Session/CreateSessionDTO.php`**
   - Update `$paymentMethodOptions` property docblock: change "暫不支援設定" to "不需要設定"

### Backend (No change needed)

6. **`inc/classes/Domains/Payment/ShoplinePayment/Shared/Enums/PaymentMethod.php`**
   - `can_refund()`: LINE Pay already falls through to `return true` (full + partial refund supported). No change needed.

### Documentation

7. **`.claude/worktrees/8/.claude/CLAUDE.md`** (or root `.claude/CLAUDE.md`)
   - Add LINE Pay row to the Refund support table:
     | LINE Pay | Yes | Yes |

## Backward Compatibility

- Existing stores that have already saved `allowPaymentMethodList` will NOT have LINE Pay
  auto-enabled. The default array only applies to fresh installations or stores that have
  never saved the SLP settings.
- The `RedirectSettingsDTO::instance()` merges saved WC options over defaults, so the
  saved array takes precedence.

## Refund Rules

| Payment Method | Partial Refund | Full Refund |
|---|---|---|
| Credit Card | Yes | Yes |
| Apple Pay | No | Yes |
| LINE Pay | Yes | Yes |
| ZingalaCard | No | Yes |
| ATM Virtual Account | No | No |

## WC Block Checkout

LINE Pay is part of the `shopline_payment_redirect` gateway. No new block entry file
is needed. The existing `inc/assets/blocks/shopline_payment_redirect.tsx` handles all
SLP payment methods as a single gateway.

## PaymentMethodOptions

LINE Pay does not require `paymentMethodOptions` configuration. The
`PaymentMethodOptions` DTO correctly excludes it from the `$fields` array.

## Testing

- PHPStan / ESLint must pass
- Sandbox: SLP sandbox uses a simulation page for non-credit-card payment methods.
  LINE Pay will appear on the SLP hosted page when included in `allowPaymentMethodList`.
- Verify: settings page checkbox works, warning displays, save persists, checkout creates
  session with LinePay in the list.
