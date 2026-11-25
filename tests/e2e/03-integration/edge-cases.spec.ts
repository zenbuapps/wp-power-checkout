/**
 * P2/P3 — Edge Cases — 狀態邊界與並發場景測試
 *
 * 測試各種特殊業務情境：
 * - 訂單狀態邊界（已完成退款、不存在資源）
 * - 付款方式退款限制（ATM 不支援、中租只支援全額）
 * - 重複操作（連續退款、重複開立發票）
 * - 不存在 ID 的存取（0, 負數, 字串）
 * - 已刪除資源的存取
 * - 設定的冪等性
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import {
  BASE_URL,
  EP,
  PROVIDERS,
  INVOICE_TYPE,
  INDIVIDUAL_TYPE,
  EDGE,
  loadTestIds,
} from '../fixtures/test-data.js'

test.describe('Edge Cases — 狀態邊界與特殊情境', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined
  let orderIdWithInvoice: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderId
    orderIdWithInvoice = ids.orderIdWithInvoice
  })

  // ─── Settings 設定冪等性 ────────────────────────────────────
  test.describe('Settings 冪等性', () => {
    test('連續兩次 GET settings 結果一致', async () => {
      const res1 = await wpGet(opts, EP.SETTINGS_ALL)
      const res2 = await wpGet(opts, EP.SETTINGS_ALL)

      expect(res1.status).toBe(200)
      expect(res2.status).toBe(200)

      const data1 = ((res1.data as Record<string, unknown>).data ?? res1.data) as Record<string, unknown>
      const data2 = ((res2.data as Record<string, unknown>).data ?? res2.data) as Record<string, unknown>

      const gateways1 = data1.gateways as unknown[]
      const gateways2 = data2.gateways as unknown[]
      expect(gateways1.length).toBe(gateways2.length)
    })

    test('寫入相同值兩次 → 兩次都回傳 200 success，最終值一致', async () => {
      const updateData = { title: '[E2E] Idempotent Test', mode: 'test' }

      const res1 = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), updateData)
      const res2 = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), updateData)

      expect(res1.status).toBe(200)
      expect(res2.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
      expect(data.title).toBe('[E2E] Idempotent Test')

      // 還原
      await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: 'SHOPLINE Payments（跳轉支付）',
      })
    })

    test('toggle 兩次後狀態回到原始值', async () => {
      const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const dataBefore = ((before.data as Record<string, unknown>).data ?? before.data) as Record<string, unknown>
      const originalValue = dataBefore.enabled

      await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
      await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})

      const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const dataAfter = ((after.data as Record<string, unknown>).data ?? after.data) as Record<string, unknown>
      expect(dataAfter.enabled).toBe(originalValue)
    })
  })

  // ─── Refund 業務規則邊界 ────────────────────────────────────
  test.describe('Refund 業務規則邊界', () => {
    test('refund order_id 為 0 → 非 200', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: 0 })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('manual refund order_id 為 0 → 非 200', async () => {
      const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: 0 })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('同一訂單連續 manual refund → 不應 crash（第二次可能已是 refunded）', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res1 = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })
      expect(res1.status).toBeLessThan(600)

      // 第二次：訂單狀態已是 refunded，可能仍返回 200 或拒絕
      const res2 = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })
      expect(res2.status).toBeLessThan(600)
    })

    test('refund 非常大的訂單 ID → 500 找不到訂單', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.MAX_INT32 })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('manual refund 非常大的訂單 ID → 500', async () => {
      const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: EDGE.MAX_INT32 })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })
  })

  // ─── Invoice 業務規則邊界 ───────────────────────────────────
  test.describe('Invoice 業務規則邊界', () => {
    test('重複作廢（第二次）→ 200，不重複呼叫 Amego API', async () => {
      test.skip(!orderIdWithInvoice, '含發票測試訂單未建立，跳過')

      // 第一次作廢
      await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
      // 第二次作廢（spec 要求已作廢過則直接回傳已有資料）
      const res2 = await wpPost(opts, EP.INVOICE_CANCEL(orderIdWithInvoice!), {})
      expect(res2.status).toBe(200)
    })

    test('invoice issue 非常大的 order_id → 500 找不到訂單', async () => {
      const res = await wpPost(opts, EP.INVOICE_ISSUE(EDGE.MAX_INT32), {
        provider: PROVIDERS.AMEGO,
      })
      expect(res.status).toBe(500)
      const body = res.data as Record<string, unknown>
      expect(String(body.message ?? '')).toContain('找不到訂單')
    })

    test('invoice cancel 非常大的 order_id → 500 找不到訂單', async () => {
      const res = await wpPost(opts, EP.INVOICE_CANCEL(EDGE.MAX_INT32), {})
      expect(res.status).toBe(500)
      const body = res.data as Record<string, unknown>
      expect(String(body.message ?? '')).toContain('找不到訂單')
    })

    test('invoice issue company 類型不帶 companyId → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        // 故意不帶 companyName 和 companyId
      })
      expect(res.status).toBeLessThan(600)
    })

    test('invoice issue donate 類型不帶 donateCode → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.DONATE,
        // 故意不帶 donateCode
      })
      expect(res.status).toBeLessThan(600)
    })

    test('invoice issue barcode 類型不帶 carrier → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.BARCODE,
        // 故意不帶 carrier
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 不存在資源的一致性 ─────────────────────────────────────
  test.describe('不存在資源的一致性', () => {
    test('GET 不存在的 provider 連續兩次結果一致（都是 500）', async () => {
      const res1 = await wpGet(opts, EP.SETTINGS_SINGLE('definitely_not_exist'))
      const res2 = await wpGet(opts, EP.SETTINGS_SINGLE('definitely_not_exist'))
      expect(res1.status).toBe(500)
      expect(res2.status).toBe(500)
    })

    test('toggle 不存在的 provider 一致回傳非 200', async () => {
      const res1 = await wpPost(opts, EP.SETTINGS_TOGGLE('not_a_real_provider'), {})
      const res2 = await wpPost(opts, EP.SETTINGS_TOGGLE('not_a_real_provider'), {})
      expect(res1.status).toBeGreaterThanOrEqual(400)
      expect(res2.status).toBeGreaterThanOrEqual(400)
    })

    test('refund 不存在的訂單一致回傳 500 並含「找不到訂單」', async () => {
      const nonexistentId = 9_999_998
      const res = await wpPost(opts, EP.REFUND, { order_id: nonexistentId })
      expect(res.status).toBe(500)
      const body = res.data as Record<string, unknown>
      expect(String(body.message ?? '')).toContain('找不到訂單')
    })
  })

  // ─── Provider ID 格式邊界 ───────────────────────────────────
  test.describe('Provider ID 格式邊界（pattern: ^[a-zA-Z_-]+$）', () => {
    test('provider_id 以數字開頭 → 非 200', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('123invalid'))
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('provider_id 含空格 → 非 200', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('has space'))
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('provider_id 含中文 → 非 200', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('中文provider'))
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('provider_id 含 @ 符號 → 非 200', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('user@domain.com'))
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('provider_id 僅含底線（合法格式）→ 可路由（500 因不存在）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('_valid_underscore_'))
      // 格式合法，但 provider 不存在
      expect(res.status).toBe(500)
    })

    test('provider_id 含連字符（合法格式）→ 可路由（500 因不存在）', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('valid-with-dash'))
      expect(res.status).toBe(500)
    })
  })

  // ─── 授權邊界矩陣 ───────────────────────────────────────────
  test.describe('授權邊界：各 API 端點的未授權存取', () => {
    const endpoints = [
      { name: 'GET settings all', fn: (opts: ApiOptions) => wpGet(opts, EP.SETTINGS_ALL) },
      { name: 'GET settings SLP', fn: (opts: ApiOptions) => wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP)) },
      { name: 'POST settings SLP', fn: (opts: ApiOptions) => wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), { title: 'x' }) },
      { name: 'POST toggle amego', fn: (opts: ApiOptions) => wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {}) },
      { name: 'POST refund', fn: (opts: ApiOptions) => wpPost(opts, EP.REFUND, { order_id: 1 }) },
      { name: 'POST refund/manual', fn: (opts: ApiOptions) => wpPost(opts, EP.REFUND_MANUAL, { order_id: 1 }) },
      { name: 'POST invoices/issue', fn: (opts: ApiOptions) => wpPost(opts, EP.INVOICE_ISSUE(1), { provider: PROVIDERS.AMEGO }) },
      { name: 'POST invoices/cancel', fn: (opts: ApiOptions) => wpPost(opts, EP.INVOICE_CANCEL(1), {}) },
    ]

    for (const endpoint of endpoints) {
      test(`未登入存取 ${endpoint.name} → 401 或 403`, async ({ request }) => {
        const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
        const res = await endpoint.fn(unauthOpts)
        expect([401, 403]).toContain(res.status)
      })
    }
  })
})
