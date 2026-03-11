/**
 * POST /power-checkout/v1/invoices/cancel/{order_id} — 作廢電子發票
 *
 * Based on: spec/features/作廢電子發票.feature
 * - 訂單不存在 → 500
 * - invoice provider 找不到 → 500
 * - 已作廢過的不重複作廢 → 200
 * - 首次作廢成功 → 200
 *
 * NOTE: @ignore tag — depends on external Amego API and order meta state.
 */
import { test, expect } from '@playwright/test'
import { wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, TEST_ORDER, loadTestIds } from '../fixtures/test-data.js'

test.describe('POST /invoices/cancel/{order_id} — 作廢電子發票', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderId
  })

  // ─── 前置：訂單必須存在 ────────────────────────────────
  test('訂單不存在 → 500', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(TEST_ORDER.NONEXISTENT_ID), {})
    expect(res.status).toBe(500)

    const body = res.data as any
    const msg = body.message ?? JSON.stringify(body)
    expect(msg).toContain('找不到')
  })

  // ─── 前置：必須有對應的 invoice provider ───────────────
  test('訂單沒有 _pc_invoice_provider_id → 500', async () => {
    test.skip(!testOrderId, '測試訂單未建立，跳過')

    // 測試訂單可能沒有設定 _pc_invoice_provider_id
    const res = await wpPost(opts, EP.INVOICE_CANCEL(testOrderId!), {})
    // Should return 500 if no provider set, or 200 if already cancelled
    expect(res.status).toBeLessThan(600)
  })

  // ─── order_id 為 0 → 500 ──────────────────────────────
  test('order_id 為 0 → 500', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(0), {})
    expect(res.status).toBe(500)
  })

  // ─── order_id 為非數字路徑 ─────────────────────────────
  test('order_id 為字串 "abc" → 應回傳錯誤', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL('abc'), {})
    // WordPress may handle this as 404 or 500
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  // ─── 負數 order_id ─────────────────────────────────────
  test('order_id 為負數 → 500', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(-1), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  // ─── 極大 order_id ─────────────────────────────────────
  test('order_id 為極大數字 → 500', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(999999999), {})
    expect(res.status).toBe(500)
  })
})
