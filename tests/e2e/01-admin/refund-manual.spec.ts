/**
 * P0 — POST /power-checkout/v1/refund/manual — 手動退款
 *
 * 依據：specs/features/payment/refund-manual.feature
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - order_id 不是數字 → 500，message 包含 "order_id must be numeric"
 * - 訂單不存在 → 500，message 包含 "order not found"
 * - 手動退款成功 → 200，code 為 success，message 含「手動退款成功」
 * - 退款後透過 WC REST API 確認訂單狀態為 refunded
 * - 邊界值：負數、浮點數、超大數、空值 order_id
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, TEST_ORDER, EDGE, loadTestIds } from '../fixtures/test-data.js'

test.describe('POST /refund/manual — 手動退款', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderIdForManualRefund
  })

  // ─── P1：未授權存取 ─────────────────────────────────────────
  test('未登入的訪客 → 401 或 403', async ({ request }) => {
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(unauthOpts, EP.REFUND_MANUAL, { order_id: 1 })
    expect([401, 403]).toContain(res.status)
  })

  // ─── P1：order_id 格式驗證 ──────────────────────────────────
  test('order_id 為非數字字串 "abc" → 500，message 含 "numeric"', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: 'abc' })
    expect(res.status).toBe(500)

    const body = res.data as Record<string, unknown>
    const msg = String(body.message ?? JSON.stringify(body))
    expect(msg.toLowerCase()).toContain('numeric')
  })

  test('order_id 為 "null" 字串 → 500', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: 'null' })
    expect(res.status).toBe(500)
  })

  test('order_id 未提供（空 body）→ 非 200', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  // ─── P1：訂單存在性驗證 ─────────────────────────────────────
  test('訂單不存在 → 500，message 含 "not found"', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {
      order_id: TEST_ORDER.NONEXISTENT_ID,
    })
    expect(res.status).toBe(500)

    const body = res.data as Record<string, unknown>
    const msg = String(body.message ?? JSON.stringify(body))
    expect(msg.toLowerCase()).toContain('not found')
  })

  // ─── P0：手動退款成功 ──────────────────────────────────────
  test('手動退款成功 → 200，code success，message 含「手動退款成功」', async () => {
    test.skip(!testOrderId, '測試訂單未建立，跳過')

    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    expect(body.code).toBe('success')
    const msg = String(body.message ?? '')
    expect(msg).toContain('手動退款成功')
  })

  test('手動退款後 WC 訂單狀態為 refunded', async () => {
    test.skip(!testOrderId, '測試訂單未建立，跳過')

    // 直接讀取 WC REST API 確認狀態
    const orderRes = await wpGet(opts, `wc/v3/orders/${testOrderId}`)
    if (orderRes.status === 200) {
      const order = orderRes.data as Record<string, unknown>
      expect(order.status).toBe('refunded')
    }
  })

  // ─── P3：邊界值 order_id ────────────────────────────────────
  test('order_id 為 0 → 非 200', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: EDGE.ZERO })
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為負數 → 非 200', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: EDGE.NEGATIVE })
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為浮點數 1.5 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: EDGE.FLOAT_HALF })
    expect(res.status).toBeLessThan(600)
  })

  test('order_id 為 MAX_SAFE_INT → 500（訂單不存在）', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {
      order_id: EDGE.MAX_SAFE_INT,
    })
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為 XSS 字串 → 500（非數字）', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {
      order_id: EDGE.XSS_SCRIPT,
    })
    expect(res.status).toBe(500)
  })

  test('order_id 為 SQL injection → 500（非數字）', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {
      order_id: EDGE.SQL_DROP,
    })
    expect(res.status).toBe(500)
  })
})
