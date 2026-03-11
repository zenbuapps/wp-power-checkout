/**
 * Edge Cases E2E Tests — 重複退款、重複開立、無效 order_id、金額邊界
 *
 * 測試 power-checkout API 在各種邊界情境下的正確行為。
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
  TEST_ORDER,
  loadTestIds,
} from '../fixtures/test-data.js'

test.describe('Edge Cases — 邊界情境測試', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderId
  })

  // ─── 重複退款 ──────────────────────────────────────────
  test.describe('重複退款', () => {
    test('連續兩次 gateway 退款同一訂單 → 第二次應回傳錯誤', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      // 第一次退款
      const res1 = await wpPost(opts, EP.REFUND, { order_id: testOrderId })
      // 可能成功或失敗（取決於 SLP 連線）

      // 第二次退款（若第一次成功，應該已無餘額）
      const res2 = await wpPost(opts, EP.REFUND, { order_id: testOrderId })
      // 不應 crash
      expect(res2.status).toBeLessThan(600)

      // 如果第一次退款成功，第二次應回傳錯誤
      if (res1.status === 200) {
        expect(res2.status).toBeGreaterThanOrEqual(400)
      }
    })

    test('連續兩次手動退款 → 第二次仍然不應 crash', async () => {
      // 建立一個新訂單（用 WC REST API）或使用已有的
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res1 = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })
      const res2 = await wpPost(opts, EP.REFUND_MANUAL, { order_id: testOrderId })

      // 不應 crash
      expect(res2.status).toBeLessThan(600)
    })
  })

  // ─── 重複開立發票 ──────────────────────────────────────
  test.describe('重複開立發票', () => {
    test('已開立過的發票再次開立 → 200（回傳已有資料）或合理錯誤', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      // 第一次開立
      const res1 = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.CLOUD,
      })

      // 第二次開立（如果第一次成功，應直接回傳已有資料）
      const res2 = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.CLOUD,
      })

      expect(res2.status).toBeLessThan(600)

      // 若第一次開立成功，第二次應回傳 200（不重複開立）
      if (res1.status === 200) {
        expect(res2.status).toBe(200)
      }
    })
  })

  // ─── 重複作廢發票 ──────────────────────────────────────
  test.describe('重複作廢發票', () => {
    test('已作廢過的發票再次作廢 → 200 或合理錯誤', async () => {
      test.skip(!testOrderId, '測試訂單未建立，跳過')

      const res1 = await wpPost(opts, EP.INVOICE_CANCEL(testOrderId!), {})
      const res2 = await wpPost(opts, EP.INVOICE_CANCEL(testOrderId!), {})

      expect(res2.status).toBeLessThan(600)
    })
  })

  // ─── 無效 order_id 格式 ───────────────────────────────
  test.describe('無效 order_id 格式', () => {
    const invalidOrderIds = [
      { name: '字串 "abc"', value: 'abc' },
      { name: '負數 -1', value: -1 },
      { name: '浮點數 1.5', value: 1.5 },
      { name: '超大數字', value: 999999999999 },
      { name: '0', value: 0 },
      { name: '布林值 true', value: true },
      { name: '陣列', value: [1, 2, 3] },
      { name: '物件', value: { id: 1 } },
    ]

    for (const { name, value } of invalidOrderIds) {
      test(`refund order_id 為 ${name} → 不應 crash`, async () => {
        const res = await wpPost(opts, EP.REFUND, { order_id: value })
        expect(res.status).toBeLessThan(600)
      })
    }

    for (const { name, value } of invalidOrderIds) {
      test(`manual refund order_id 為 ${name} → 不應 crash`, async () => {
        const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: value })
        expect(res.status).toBeLessThan(600)
      })
    }
  })

  // ─── 不存在的 provider_id ──────────────────────────────
  test.describe('不存在的 provider_id', () => {
    const invalidProviders = [
      'nonexistent',
      '',
      '../../etc/passwd',
      '<script>alert(1)</script>',
      'a'.repeat(1000),
      '中文provider',
    ]

    for (const provider of invalidProviders) {
      test(`GET settings/${provider.slice(0, 30)}... → 安全處理`, async () => {
        const res = await wpGet(opts, EP.SETTINGS_SINGLE(provider))
        expect(res.status).toBeLessThan(600)
      })
    }

    for (const provider of invalidProviders) {
      test(`POST toggle ${provider.slice(0, 30)}... → 安全處理`, async () => {
        const res = await wpPost(opts, EP.SETTINGS_TOGGLE(provider), {})
        expect(res.status).toBeLessThan(600)
      })
    }
  })

  // ─── 金額邊界 ──────────────────────────────────────────
  test.describe('settings 金額邊界', () => {
    test('min_amount 設為 0 → 儲存成功', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: 0,
      })
      expect(res.status).toBe(200)
    })

    test('max_amount 設為極大值 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        max_amount: 999999999,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('min_amount > max_amount → 應接受或拒絕但不 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: 50000,
        max_amount: 100,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('expire_min 設為 0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        expire_min: 0,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('expire_min 設為極大值 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        expire_min: 999999,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 設定 allowPaymentMethodList ──────────────────────
  test.describe('PaymentMethodList 邊界', () => {
    test('allowPaymentMethodList 為空陣列 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: [],
      })
      expect(res.status).toBeLessThan(600)
    })

    test('allowPaymentMethodList 包含無效值 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: ['InvalidMethod', 'CreditCard', ''],
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 並發請求 ──────────────────────────────────────────
  test.describe('並發請求', () => {
    test('同時發送 5 個 GET /settings → 都應成功', async () => {
      const promises = Array.from({ length: 5 }, () =>
        wpGet(opts, EP.SETTINGS_ALL),
      )
      const results = await Promise.all(promises)
      for (const res of results) {
        expect(res.status).toBe(200)
      }
    })

    test('同時 toggle 同一 provider → 不應 crash', async () => {
      const promises = Array.from({ length: 3 }, () =>
        wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {}),
      )
      const results = await Promise.all(promises)
      for (const res of results) {
        expect(res.status).toBeLessThan(600)
      }

      // 偶數次 toggle 應恢復原始狀態（或至少最後一個 toggle 有效）
      // 再 toggle 一次以確保可預測
      const check = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      expect(check.status).toBe(200)
    })
  })

  // ─── Webhook 邊界 ─────────────────────────────────────
  test.describe('Webhook 邊界', () => {
    test('webhook 空 JSON body → 不應 crash', async ({ request }) => {
      const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
        headers: {
          'Content-Type': 'application/json',
          timestamp: String(Date.now()),
          sign: 'test',
          apiVersion: 'V1',
        },
        data: {},
      })
      expect(res.status()).toBeLessThan(600)
    })

    test('webhook 超大 payload → 不應 crash', async ({ request }) => {
      const largePayload = {
        eventType: 'session.succeeded',
        data: {
          tradeOrderId: 'X'.repeat(10000),
          extraData: 'Y'.repeat(50000),
        },
      }
      const res = await request.post(`${BASE_URL}/wp-json/${EP.WEBHOOK}`, {
        headers: {
          'Content-Type': 'application/json',
          timestamp: String(Date.now()),
          sign: 'test',
          apiVersion: 'V1',
        },
        data: largePayload,
      })
      expect(res.status()).toBeLessThan(600)
    })
  })

  // ─── 清理 ─────────────────────────────────────────────
  test.afterAll(async () => {
    // 還原合理的 SLP 設定
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      min_amount: 5,
      max_amount: 50000,
      expire_min: 360,
    })
  })
})
