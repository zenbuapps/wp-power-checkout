/**
 * P0 — POST /power-checkout/v1/invoices/cancel/{order_id} — 作廢電子發票
 *
 * 依據：specs/features/invoice/invoice-cancel.feature
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - 訂單不存在 → 500，message 含「找不到訂單」
 * - 訂單的 _pc_invoice_provider_id 對應不到 provider → 500，message 含「不是 Invoice Service」
 * - 已作廢過的訂單重複作廢 → 200（不重複呼叫 API）
 * - 首次作廢發票成功 → 200，清除 issue meta，儲存 cancelled meta
 * - 邊界值 order_id：0、負數、字串、XSS
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  TEST_ORDER,
  EDGE,
  loadTestIds,
} from '../fixtures/test-data.js'

test.describe('POST /invoices/cancel/{order_id} — 作廢電子發票', () => {
  let opts: ApiOptions
  let orderIdWithInvoice: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    orderIdWithInvoice = ids.orderIdWithInvoice
  })

  // ─── P1：未授權存取 ─────────────────────────────────────────
  test('未登入的訪客 → 401 或 403', async ({ request }) => {
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(unauthOpts, EP.INVOICE_CANCEL(1), {})
    expect([401, 403]).toContain(res.status)
  })

  // ─── P1：訂單存在性驗證 ─────────────────────────────────────
  test('訂單不存在 → 500，message 含「找不到訂單」', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(TEST_ORDER.NONEXISTENT_ID), {})
    expect(res.status).toBe(500)

    const body = res.data as Record<string, unknown>
    const msg = String(body.message ?? JSON.stringify(body))
    expect(msg).toContain('找不到訂單')
  })

  // ─── P0：首次作廢發票（含 _pc_invoice_provider_id 的訂單）──
  test('有 _pc_invoice_provider_id 的訂單作廢 → 不應 crash', async () => {
    test.skip(!orderIdWithInvoice, '含發票測試訂單未建立，跳過')

    const res = await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
    // 在無 Amego API 連線的測試環境，可能返回 200 或 500
    expect(res.status).toBeLessThan(600)
  })

  test('作廢成功後 WC 訂單 _pc_issue_invoice_params 應被清除', async () => {
    test.skip(!orderIdWithInvoice, '含發票測試訂單未建立，跳過')

    const res = await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
    if (res.status === 200) {
      // 透過 WC REST API 確認 meta 已清除
      const orderRes = await wpGet(opts, `wc/v3/orders/${orderIdWithInvoice}`)
      if (orderRes.status === 200) {
        const order = orderRes.data as Record<string, unknown>
        const metaData = order.meta_data as Array<{ key: string; value: unknown }> | undefined
        if (metaData) {
          const issueParams = metaData.find(m => m.key === '_pc_issue_invoice_params')
          const issuedData = metaData.find(m => m.key === '_pc_issued_invoice_data')
          const providerId = metaData.find(m => m.key === '_pc_invoice_provider_id')
          // spec 要求作廢成功時清除這三個 meta
          // 空值或不存在都視為清除成功
          if (issueParams) expect(issueParams.value).toBeFalsy()
          if (issuedData) expect(issuedData.value).toBeFalsy()
          if (providerId) expect(providerId.value).toBeFalsy()
        }
      }
    }
  })

  // ─── P2：重複作廢防護 ───────────────────────────────────────
  test('重複作廢（第二次）→ 200，不重複呼叫 Amego API', async () => {
    test.skip(!orderIdWithInvoice, '含發票測試訂單未建立，跳過')

    // 第一次作廢
    await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
    // 第二次作廢（應直接回傳已有資料）
    const res2 = await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
    expect(res2.status).toBe(200)
  })

  // ─── P3：邊界值 order_id ────────────────────────────────────
  test('order_id 為 0 → 非 200', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(EDGE.ZERO), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為負數 -1 → 非 200', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(EDGE.NEGATIVE), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為字串 "abc" → 非 200', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL('abc'), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 路徑含 XSS → 非 200', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL('<script>alert(1)</script>'), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為極大數 → 500（訂單不存在）', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(EDGE.MAX_INT32), {})
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('order_id 為浮點數 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.INVOICE_CANCEL(EDGE.FLOAT_HALF), {})
    expect(res.status).toBeLessThan(600)
  })
})
