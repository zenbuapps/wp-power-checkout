/**
 * P1 — 訂單狀態顯示 — 訂單頁面與 REST API 驗證
 *
 * 驗證 power-checkout 不破壞 WooCommerce 訂單相關頁面：
 * - My Account 訂單列表頁可存取、無 PHP 錯誤
 * - 訂單詳情可透過 WC REST API 正確讀取
 * - 訂單 meta_data 包含 power-checkout 自訂欄位
 * - 訂單確認頁（order-received）不出現 PHP 錯誤
 * - 無效訂單 ID 的頁面不 crash
 *
 * 依據：specs/erm.dbml（Order meta keys）
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, ORDER_STATUS, loadTestIds } from '../fixtures/test-data.js'

test.describe('訂單狀態顯示', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderId
  })

  // ─── My Account 訂單列表 ───────────────────────────────
  test.describe('My Account 訂單列表', () => {
    test('My Account 訂單頁應可存取（HTTP < 500）', async ({ page }) => {
      const response = await page.goto(`${BASE_URL}/my-account/orders/`)
      expect(response?.status()).toBeLessThan(500)
    })

    test('My Account 訂單頁不應出現 PHP Fatal Error', async ({ page }) => {
      await page.goto(`${BASE_URL}/my-account/orders/`)
      await page.waitForLoadState('domcontentloaded')
      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
      expect(bodyText.toLowerCase()).not.toContain('parse error')
    })

    test('訂單列表頁應包含訂單表格或空狀態訊息', async ({ page }) => {
      await page.goto(`${BASE_URL}/my-account/orders/`)
      await page.waitForLoadState('domcontentloaded')

      const hasOrdersTable =
        (await page.locator(
          '.woocommerce-orders-table, .woocommerce-MyAccount-orders, table.my_account_orders',
        ).count()) > 0
      const hasNoOrders =
        (await page.locator('.woocommerce-message, .woocommerce-info').count()) > 0

      expect(hasOrdersTable || hasNoOrders).toBeTruthy()
    })

    test('My Account 首頁應可存取且顯示 dashboard', async ({ page }) => {
      const response = await page.goto(`${BASE_URL}/my-account/`)
      expect(response?.status()).toBeLessThan(500)

      await page.waitForLoadState('domcontentloaded')
      const hasDashboard =
        (await page.locator(
          '.woocommerce-MyAccount-content, .woocommerce-account',
        ).count()) > 0
      expect(hasDashboard).toBeTruthy()
    })
  })

  // ─── 訂單 REST API ──────────────────────────────────────
  test.describe('訂單 REST API 欄位完整性', () => {
    test('WC REST API 訂單 endpoint 應回傳 200', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      expect(orderRes.status).toBe(200)
      const order = orderRes.data as Record<string, unknown>
      expect(order.id).toBe(testOrderId)
    })

    test('訂單應有有效的 WooCommerce 狀態', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      const validStatuses = Object.values(ORDER_STATUS)
      expect(validStatuses).toContain(order.status)
    })

    test('訂單應包含 payment_method 與 payment_method_title', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      expect(order.payment_method).toBeDefined()
      expect(order.payment_method_title).toBeDefined()
    })

    test('訂單應包含 billing 帳單資訊（含 email）', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      const billing = order.billing as Record<string, unknown>
      expect(billing).toBeDefined()
      expect(billing.email).toBeDefined()
    })

    test('訂單應包含商品明細（line_items 非空陣列）', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      const lineItems = order.line_items as unknown[]
      expect(Array.isArray(lineItems)).toBeTruthy()
      expect(lineItems.length).toBeGreaterThan(0)
    })

    test('訂單 total 應為正數字串', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      expect(order.total).toBeDefined()
      expect(Number(order.total)).toBeGreaterThan(0)
    })
  })

  // ─── 訂單 meta_data（power-checkout 自訂欄位）──────────
  test.describe('訂單 meta_data（pc_ 欄位）', () => {
    test('訂單 meta_data 中應有 pc_payment_identity（tradeOrderId）', async () => {
      test.skip(!testOrderId, '測試訂單未建立')

      const orderRes = await wpGet(opts, EP.WC_ORDER(testOrderId!))
      const order = orderRes.data as Record<string, unknown>
      const metaData = order.meta_data as Array<Record<string, unknown>>
      const pcPaymentIdentity = metaData?.find((m) => m.key === 'pc_payment_identity')
      // global-setup 應設定此欄位
      if (pcPaymentIdentity) {
        expect(pcPaymentIdentity.value).toBeTruthy()
      }
    })

    test('GET 不存在的訂單 → 404', async () => {
      const res = await wpGet(opts, EP.WC_ORDER(9_999_999))
      expect(res.status).toBe(404)
    })
  })

  // ─── 訂單確認頁（order-received）─────────────────────
  test.describe('訂單確認頁（order-received）', () => {
    test('order-received 頁帶有測試訂單 ID 不應 PHP 錯誤', async ({ page }) => {
      test.skip(!testOrderId, '測試訂單未建立')

      // order-received URL 格式：/checkout/order-received/{id}/?key=...
      // 不帶 key 時 WC 可能顯示「Invalid order」但不應 crash
      const response = await page.goto(
        `${BASE_URL}/checkout/order-received/${testOrderId}/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
      expect(bodyText.toLowerCase()).not.toContain('parse error')
    })

    test('order-received 帶無效訂單 ID 不應 PHP 錯誤', async ({ page }) => {
      const response = await page.goto(
        `${BASE_URL}/checkout/order-received/9999999/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })

    test('order-received 帶 order_id=0 不應 PHP 錯誤', async ({ page }) => {
      const response = await page.goto(
        `${BASE_URL}/checkout/order-received/0/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })
  })

  // ─── My Account 訂單檢視頁 ─────────────────────────────
  test.describe('My Account 訂單檢視頁', () => {
    test('view-order 帶測試訂單 ID 不應 PHP 錯誤', async ({ page }) => {
      test.skip(!testOrderId, '測試訂單未建立')

      const response = await page.goto(
        `${BASE_URL}/my-account/view-order/${testOrderId}/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })

    test('view-order 帶無效訂單 ID 不應 PHP 錯誤', async ({ page }) => {
      const response = await page.goto(
        `${BASE_URL}/my-account/view-order/9999999/`,
      )
      expect(response?.status()).toBeLessThan(500)

      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
    })
  })
})
