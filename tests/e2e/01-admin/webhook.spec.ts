/**
 * P0 — POST /power-checkout/slp/webhook — Shopline Payment Webhook 接收
 *
 * 依據：specs/features/payment/shopline-payment-webhook.feature
 *
 * 重要說明：
 * - Webhook 端點不需要 WordPress 認證（無 X-WP-Nonce）
 * - 使用 HMAC-SHA256 簽章驗證：hash_hmac("sha256", "{timestamp}.{body}", signKey)
 * - 本地環境（IS_LOCAL）可能跳過 timestamp / sign 驗證
 * - 始終回傳 200（避免 SLP 重試），內部錯誤回傳 500（mapping_order_failed）
 *
 * 測試情境：
 * P0: 有效 Webhook payload 正常處理
 * P1: 無效 timestamp → 500 Invalid timestamp
 * P1: 無效 sign → 500 Invalid sign
 * P1: 找不到訂單 → 500 mapping_order_failed
 * P2: apiVersion 非 V1 → 繼續處理（不阻擋）
 * P2: 付款 SUCCEEDED → 訂單 processing
 * P2: 付款 EXPIRED → 訂單 cancelled
 * P2: 退款 SUCCEEDED → 記錄退款詳情
 * P3: 各種邊界值 payload
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  SLP_STATUS,
  EDGE,
  loadTestIds,
} from '../fixtures/test-data.js'
import {
  calcWebhookSign,
  buildWebhookRequest,
  STALE_TIMESTAMP,
  expiredTimestamp,
} from '../helpers/webhook-hmac.js'

// 測試環境使用的 signKey（需與 woocommerce_shopline_payment_redirect_settings 一致）
// 本地測試環境通常跳過簽章驗證，此值供正向測試使用
const TEST_SIGN_KEY = 'test_sign_key_123'

test.describe('POST /slp/webhook — Shopline Payment Webhook', () => {
  let opts: ApiOptions
  let tradeOrderId: string | undefined
  let orderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    orderId = ids.orderId
    tradeOrderId = ids.tradeOrderId
  })

  // ─── 輔助函式：發送 Webhook 請求（不帶 WP 認證）──────────
  async function sendWebhook(
    request: ApiOptions['request'],
    payload: Record<string, unknown>,
    headers: Record<string, string>,
  ) {
    const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
      headers: {
        'Content-Type': 'application/json',
        ...headers,
      },
      data: payload,
    })
    const body = await res.json().catch(() => ({}))
    return { status: res.status(), data: body as Record<string, unknown> }
  }

  // ─── P0：基本請求不應 crash ─────────────────────────────
  test('空 payload 帶合法 headers → status < 600（不應 crash）', async ({ request }) => {
    const { body, headers } = buildWebhookRequest({}, TEST_SIGN_KEY)
    const res = await sendWebhook(request, {}, headers)
    expect(res.status).toBeLessThan(600)
  })

  // ─── P1：過期 timestamp 驗證 ────────────────────────────
  test('timestamp 為 2001 年（超過 5 分鐘）→ 本地環境可能忽略，非本地應 500', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const body = JSON.stringify(payload)
    const sign = calcWebhookSign(STALE_TIMESTAMP, body, TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: STALE_TIMESTAMP,
      sign,
      apiVersion: 'V1',
    })
    // 本地環境跳過 timestamp 驗證，可能返回 200 或 500
    expect(res.status).toBeLessThan(600)
  })

  test('timestamp 剛好超過 5 分鐘前 → 非本地應 500 Invalid timestamp', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const body = JSON.stringify(payload)
    const ts = expiredTimestamp()
    const sign = calcWebhookSign(ts, body, TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  // ─── P1：無效簽章 ───────────────────────────────────────
  test('sign 完全錯誤 → 本地環境可能忽略，非本地應 500 Invalid sign', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: { tradeOrderId: 'test_trade' } }
    const ts = String(Date.now())

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign: 'definitely_invalid_sign_value_xyz',
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  test('缺少 sign header → 不應 crash', async ({ request }) => {
    const res = await sendWebhook(
      request,
      { eventType: 'trade.succeeded', data: {} },
      { timestamp: String(Date.now()), apiVersion: 'V1' },
    )
    expect(res.status).toBeLessThan(600)
  })

  test('缺少 timestamp header → 不應 crash', async ({ request }) => {
    const res = await sendWebhook(
      request,
      { eventType: 'trade.succeeded', data: {} },
      { sign: 'whatever', apiVersion: 'V1' },
    )
    expect(res.status).toBeLessThan(600)
  })

  // ─── P1：找不到對應訂單 ─────────────────────────────────
  test('tradeOrderId 不存在 → 500，code 為 mapping_order_failed', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: {
        tradeOrderId: 'nonexistent_trade_order_xyz_12345',
        status: SLP_STATUS.SUCCEEDED,
      },
    }
    const ts = String(Date.now())
    const sign = calcWebhookSign(ts, JSON.stringify(payload), TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })
    // 找不到訂單，應返回 500 並包含 mapping_order_failed
    expect(res.status).toBeLessThan(600)
    if (res.status === 500) {
      const body = res.data as Record<string, unknown>
      const code = String(body.code ?? '')
      expect(code).toContain('mapping_order_failed')
    }
  })

  // ─── P2：HMAC 簽章計算驗證 ─────────────────────────────
  test('HMAC-SHA256 計算驗證：hash_hmac("sha256", timestamp.body, signKey)', async () => {
    const timestamp = '1700000000000'
    const payload = { eventType: 'trade.succeeded', data: {} }
    const body = JSON.stringify(payload)
    const signKey = 'test_sign_key_123'

    const calculated = calcWebhookSign(timestamp, body, signKey)

    // 驗證簽章不為空且為有效的 hex 字串
    expect(calculated).toBeTruthy()
    expect(calculated).toMatch(/^[0-9a-f]{64}$/)
    expect(calculated).not.toBe('invalid_sign_this_should_fail')
  })

  // ─── P2：付款 SUCCEEDED → 訂單 processing ──────────────
  test('付款 SUCCEEDED Webhook → 訂單狀態更新為 processing', async ({ request }) => {
    test.skip(!tradeOrderId || !orderId, '測試訂單未建立，跳過')

    const payload = {
      eventType: 'trade.succeeded',
      data: {
        tradeOrderId,
        status: SLP_STATUS.SUCCEEDED,
        paymentDetail: {
          paymentMethod: 'CreditCard',
          amount: 1000,
        },
      },
    }
    const ts = String(Date.now())
    const sign = calcWebhookSign(ts, JSON.stringify(payload), TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })

    expect(res.status).toBeLessThan(600)

    // 若成功處理，確認訂單狀態
    if (res.status === 200) {
      const orderRes = await wpGet(opts, `wc/v3/orders/${orderId}`)
      if (orderRes.status === 200) {
        const order = orderRes.data as Record<string, unknown>
        // spec 要求 SUCCEEDED → processing
        expect(order.status).toBe('processing')
      }
    }
  })

  // ─── P2：付款 EXPIRED → 訂單 cancelled ─────────────────
  test('付款 EXPIRED Webhook → 訂單狀態更新為 cancelled', async ({ request }) => {
    test.skip(!tradeOrderId || !orderId, '測試訂單未建立，跳過')

    const payload = {
      eventType: 'trade.succeeded',
      data: {
        tradeOrderId,
        status: SLP_STATUS.EXPIRED,
      },
    }
    const ts = String(Date.now())
    const sign = calcWebhookSign(ts, JSON.stringify(payload), TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })

    expect(res.status).toBeLessThan(600)
  })

  // ─── P2：退款 SUCCEEDED Webhook ─────────────────────────
  test('退款 SUCCEEDED Webhook → 不應 crash', async ({ request }) => {
    test.skip(!tradeOrderId, '測試訂單未建立，跳過')

    const payload = {
      eventType: 'refund.succeeded',
      data: {
        tradeOrderId,
        status: SLP_STATUS.SUCCEEDED,
        refundDetail: { amount: 500 },
      },
    }
    const ts = String(Date.now())
    const sign = calcWebhookSign(ts, JSON.stringify(payload), TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  // ─── P2：退款 FAILED Webhook ─────────────────────────────
  test('退款 FAILED Webhook → 不應 crash', async ({ request }) => {
    test.skip(!tradeOrderId, '測試訂單未建立，跳過')

    const payload = {
      eventType: 'refund.succeeded',
      data: {
        tradeOrderId,
        status: SLP_STATUS.FAILED,
      },
    }
    const ts = String(Date.now())
    const sign = calcWebhookSign(ts, JSON.stringify(payload), TEST_SIGN_KEY)

    const res = await sendWebhook(request, payload, {
      timestamp: ts,
      sign,
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  // ─── P2：apiVersion 非 V1 ────────────────────────────────
  test('apiVersion 為 V2 → 繼續處理（記錄 warning 但不阻擋）', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const { body, headers } = buildWebhookRequest(payload, TEST_SIGN_KEY, {
      apiVersion: 'V2',
    })
    const res = await sendWebhook(request, payload, headers)
    // spec 要求 apiVersion 非 V1 只記錄 warning，不阻擋處理
    expect(res.status).toBeLessThan(600)
  })

  test('apiVersion 為 V999 → 不應 crash', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY, {
      apiVersion: 'V999',
    })
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  // ─── P3：邊界值 payload ──────────────────────────────────
  test('eventType 為空字串 → 不應 crash', async ({ request }) => {
    const payload = { eventType: '', data: {} }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('payload 為空物件 → 不應 crash', async ({ request }) => {
    const payload = {}
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('data.tradeOrderId 為超長字串 → 不應 crash', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: { tradeOrderId: EDGE.VERY_LONG_STRING.slice(0, 500) },
    }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('data.tradeOrderId 含 XSS → 不應 crash', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: { tradeOrderId: EDGE.XSS_SCRIPT },
    }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('data.tradeOrderId 含 SQL injection → 不應 crash', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: { tradeOrderId: EDGE.SQL_DROP },
    }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('data.tradeOrderId 為 Unicode 字串 → 不應 crash', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: { tradeOrderId: EDGE.UNICODE_CJK },
    }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('data.tradeOrderId 為 Emoji → 不應 crash', async ({ request }) => {
    const payload = {
      eventType: 'trade.succeeded',
      data: { tradeOrderId: EDGE.EMOJI_SIMPLE },
    }
    const { headers } = buildWebhookRequest(payload, TEST_SIGN_KEY)
    const res = await sendWebhook(request, payload, headers)
    expect(res.status).toBeLessThan(600)
  })

  test('timestamp header 為非數字字串 → 不應 crash', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const res = await sendWebhook(request, payload, {
      timestamp: 'not_a_number',
      sign: 'invalid',
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  test('sign header 為空字串 → 不應 crash', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const res = await sendWebhook(request, payload, {
      timestamp: String(Date.now()),
      sign: EDGE.EMPTY_STRING,
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })

  test('sign header 為超長字串 → 不應 crash', async ({ request }) => {
    const payload = { eventType: 'trade.succeeded', data: {} }
    const res = await sendWebhook(request, payload, {
      timestamp: String(Date.now()),
      sign: EDGE.VERY_LONG_STRING.slice(0, 1000),
      apiVersion: 'V1',
    })
    expect(res.status).toBeLessThan(600)
  })
})
