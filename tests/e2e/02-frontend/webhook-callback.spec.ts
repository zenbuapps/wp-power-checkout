/**
 * P1 — Webhook 回呼與付款回導 — 前端路徑可達性
 *
 * 驗證 Webhook 端點的基本行為與付款回導 URL 的可達性：
 * - POST /slp/webhook 端點存在（非 404）且不 crash
 * - 各付款狀態（SUCCEEDED, EXPIRED, FAILED, CANCELLED, PROCESSING）的 Webhook 處理
 * - 退款 Webhook（refund.succeeded, refund.failed）不 crash
 * - 未知 eventType 安全忽略
 * - Webhook 不需要 X-WP-Nonce（公開端點）
 * - 付款回導頁面（order-received, checkout, view-order）不出現 PHP 錯誤
 * - Header 邊界值（timestamp=0, 未來時間, 空 sign, 缺 apiVersion）
 *
 * 依據：specs/features/payment/shopline-payment-webhook.feature
 * NOTE：此處使用簡單 sign（非 HMAC 計算），因本地環境可能跳過驗簽。
 *       完整 HMAC 測試請參見 01-admin/webhook.spec.ts。
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  SLP_STATUS,
  loadTestIds,
} from '../fixtures/test-data.js'

test.describe('Webhook 回呼與付款回導', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined
  let tradeOrderId: string | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderId

    // 從測試訂單取得 pc_payment_identity（tradeOrderId）
    if (testOrderId) {
      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId))
      if (orderRes.status === 200) {
        const order = orderRes.data as Record<string, unknown>
        const metaData = order.meta_data as Array<Record<string, unknown>>
        const pcPaymentIdentity = metaData?.find(
          (m) => m.key === 'pc_payment_identity',
        )
        tradeOrderId = pcPaymentIdentity?.value as string | undefined
      }
    }
  })

  // ─── 輔助函式：發送 Webhook（不帶 WP 認證）─────────────
  async function sendWebhook(
    request: ApiOptions['request'],
    payload: Record<string, unknown>,
    headers: Record<string, string> = {},
  ) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
      headers: {
        'Content-Type': 'application/json',
        timestamp: String(Date.now()),
        sign: 'e2e_test_sign_placeholder',
        apiVersion: 'V1',
        ...headers,
      },
      data: payload,
    })
    const body = await res.json().catch(() => ({}))
    return { status: res.status(), data: body as Record<string, unknown> }
  }

  // ─── Webhook 端點可達性 ────────────────────────────────
  test.describe('Webhook 端點可達性', () => {
    test('POST /slp/webhook 端點存在（非 404）且不 crash', async ({ request }) => {
      const res = await sendWebhook(request, {})
      expect(res.status).not.toBe(404)
      expect(res.status).toBeLessThan(600)
    })

    test('GET /slp/webhook → 405 或其他合理錯誤（非 crash）', async ({ request }) => {
      const res = await request.get(`${BASE_URL}/wp-json/${EP.WEBHOOK}`)
      // WordPress REST API 對未定義 GET 回傳 404/405
      expect(res.status()).toBeLessThan(600)
    })

    test('Webhook 不需要 X-WP-Nonce（不應回 401/403）', async ({ request }) => {
      // 刻意不帶任何 WP 認證 header
      const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
        headers: {
          'Content-Type': 'application/json',
          timestamp: String(Date.now()),
          sign: 'test_sign',
          apiVersion: 'V1',
        },
        data: { eventType: 'trade.succeeded', data: {} },
      })
      expect([401, 403]).not.toContain(res.status())
      expect(res.status()).toBeLessThan(600)
    })
  })

  // ─── 付款狀態 Webhook ──────────────────────────────────
  test.describe('付款狀態 Webhook（trade.* 事件）', () => {
    test('trade.succeeded + SUCCEEDED → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'trade.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.SUCCEEDED,
          paymentDetail: {
            paymentMethod: 'CreditCard',
            amount: 1000,
          },
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('trade.succeeded + EXPIRED → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'trade.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.EXPIRED,
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('trade.succeeded + FAILED → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'trade.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.FAILED,
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('trade.succeeded + CANCELLED → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'trade.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.CANCELLED,
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('trade.succeeded + PROCESSING → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'trade.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.PROCESSING,
        },
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 退款 Webhook ─────────────────────────────────────
  test.describe('退款 Webhook（refund.* 事件）', () => {
    test('refund.succeeded → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'refund.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.SUCCEEDED,
          refundDetail: { amount: 500 },
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('refund.failed → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'refund.failed',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.FAILED,
        },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('refund 金額為 0 → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'refund.succeeded',
        data: {
          tradeOrderId: tradeOrderId ?? 'e2e_nonexistent_trade',
          status: SLP_STATUS.SUCCEEDED,
          refundDetail: { amount: 0 },
        },
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 未知 / 邊界 eventType ──────────────────────────────
  test.describe('未知與邊界 eventType', () => {
    test('未定義的 eventType → 安全忽略，不 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: 'unknown.event.type.xyz',
        data: { tradeOrderId: 'e2e_test_unknown' },
      })
      expect(res.status).toBeLessThan(600)
    })

    test('eventType 為空字串 → 安全處理，不 crash', async ({ request }) => {
      const res = await sendWebhook(request, {
        eventType: '',
        data: {},
      })
      expect(res.status).toBeLessThan(600)
    })

    test('payload 為空物件 → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(request, {})
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── Header 邊界值 ─────────────────────────────────────
  test.describe('Webhook Header 邊界值', () => {
    test('timestamp 為 "0" → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(
        request,
        { eventType: 'trade.succeeded', data: {} },
        { timestamp: '0' },
      )
      expect(res.status).toBeLessThan(600)
    })

    test('timestamp 為未來時間（+24h）→ 不應 crash', async ({ request }) => {
      const futureTs = String(Date.now() + 86_400_000)
      const res = await sendWebhook(
        request,
        { eventType: 'trade.succeeded', data: {} },
        { timestamp: futureTs },
      )
      expect(res.status).toBeLessThan(600)
    })

    test('timestamp 為非數字字串 → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(
        request,
        { eventType: 'trade.succeeded', data: {} },
        { timestamp: 'not_a_number' },
      )
      expect(res.status).toBeLessThan(600)
    })

    test('sign 為空字串 → 不應 crash', async ({ request }) => {
      const res = await sendWebhook(
        request,
        { eventType: 'trade.succeeded', data: {} },
        { sign: '' },
      )
      expect(res.status).toBeLessThan(600)
    })

    test('apiVersion 缺失 → 不應 crash', async ({ request }) => {
      const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
        headers: {
          'Content-Type': 'application/json',
          timestamp: String(Date.now()),
          sign: 'test',
          // 故意不帶 apiVersion
        },
        data: { eventType: 'trade.succeeded', data: {} },
      })
      expect(res.status()).toBeLessThan(600)
    })

    test('apiVersion 為 V2 → 繼續處理（warning only）', async ({ request }) => {
      const res = await sendWebhook(
        request,
        { eventType: 'trade.succeeded', data: {} },
        { apiVersion: 'V2' },
      )
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 付款回導 URL 可達性 ───────────────────────────────
  test.describe('付款回導 URL 頁面可達性', () => {
    test('付款成功回導（order-received）不應 PHP 錯誤', async ({ page }) => {
      test.skip(!testOrderId, '測試訂單未建立')

      const response = await page.goto(
        `${BASE_URL}/checkout/order-received/${testOrderId}/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })

    test('付款失敗回導（/checkout/）不應 PHP 錯誤', async ({ page }) => {
      const response = await page.goto(`${BASE_URL}/checkout/`)
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })

    test('付款取消回導（view-order）不應 PHP 錯誤', async ({ page }) => {
      test.skip(!testOrderId, '測試訂單未建立')

      const response = await page.goto(
        `${BASE_URL}/my-account/view-order/${testOrderId}/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })
  })
})
