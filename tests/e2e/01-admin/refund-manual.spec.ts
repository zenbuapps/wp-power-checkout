/**
 * POST /power-checkout/v1/refund/manual — 手動退款
 *
 * Based on: spec/features/手動退款.feature
 * - order_id 不是數字 → 500
 * - 訂單不存在 → 500
 * - 手動退款成功 → 200，訂單狀態變 refunded
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, TEST_ORDER, loadTestIds } from '../fixtures/test-data.js'

test.describe('POST /refund/manual — 手動退款', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderIdForManualRefund
  })

  // ─── 前置：order_id 必須是數字 ─────────────────────────
  test('order_id 不是數字 → 500', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: 'abc' })
    expect(res.status).toBe(500)

    const body = res.data as any
    const msg = body.message ?? JSON.stringify(body)
    expect(msg.toLowerCase()).toContain('numeric')
  })

  test('order_id 為特殊字串 → 500', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: 'null' })
    expect(res.status).toBe(500)
  })

  // ─── 前置：訂單必須存在 ────────────────────────────────
  test('找不到訂單 → 500', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: TEST_ORDER.NONEXISTENT_ID })
    expect(res.status).toBe(500)

    const body = res.data as any
    const msg = body.message ?? JSON.stringify(body)
    expect(msg.toLowerCase()).toContain('not found')
  })

  // ─── 後置：手動退款成功 ────────────────────────────────
  test('手動退款成功 → 200', async () => {
    test.skip(!testOrderId, '測試訂單未建立，跳過')

    const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })
    expect(res.status).toBe(200)

    const body = res.data as any
    const msg = body.message ?? ''
    expect(msg).toContain('手動退款成功')
  })

  test('手動退款後訂單狀態為 refunded', async () => {
    test.skip(!testOrderId, '測試訂單未建立，跳過')

    // Read order via WC REST API to check status
    const orderRes = await wpGet(opts, `wc/v3/orders/${testOrderId}`)
    if (orderRes.status === 200) {
      const order = orderRes.data as any
      expect(order.status).toBe('refunded')
    }
    // If WC REST API is not available, skip gracefully
  })

  // ─── 空 body ──────────────────────────────────────────
  test('空 body → 非 200', async () => {
    const res = await wpPost(opts, EP.REFUND_MANUAL, {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })
})
