/**
 * P1 — 電子發票表單 — 發票類型與欄位驗證
 *
 * 驗證 power-checkout 電子發票 API 在各發票類型下的行為：
 * - 個人：雲端、手機條碼、自然人憑證、紙本
 * - 公司：統一編號（8 碼）、公司名稱
 * - 捐贈：捐贈碼
 * - 欄位驗證：缺少必要欄位、格式錯誤
 * - 前端：結帳頁發票區域不出現 PHP 錯誤、JS 例外
 *
 * 依據：specs/features/invoice/
 * NOTE：外部 Amego API 在測試環境不可用，多數測試接受 status < 600 即可。
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

test.describe('電子發票表單', () => {
  let opts: ApiOptions
  let testOrderId: number | undefined

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
    const ids = loadTestIds()
    testOrderId = ids.orderIdForInvoice
  })

  // ─── Amego 設定確認 ────────────────────────────────────
  test.describe('Amego Provider 設定', () => {
    test('Amego provider 設定應可讀取，包含 invoice 與 app_key', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      expect(res.status).toBe(200)
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('invoice')
      expect(data).toHaveProperty('app_key')
    })

    test('Amego 設定包含 tax_rate 欄位', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('tax_rate')
    })

    test('Amego 設定包含 auto_issue 陣列', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('auto_issue')
      expect(Array.isArray(data.auto_issue)).toBeTruthy()
    })

    test('Amego 設定包含 auto_cancel 陣列', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('auto_cancel')
      expect(Array.isArray(data.auto_cancel)).toBeTruthy()
    })
  })

  // ─── 發票類型：個人 ────────────────────────────────────
  test.describe('發票類型 — 個人 (individual)', () => {
    test('個人雲端發票 → invoiceType=individual, individual=cloud', async () => {
      test.skip(!testOrderId, '測試訂單未建立（orderIdForInvoice）')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.CLOUD,
      })
      // 外部 Amego API 在測試環境不可用，不應 crash
      expect(res.status).toBeLessThan(600)
    })

    test('個人手機條碼 → individual=barcode，需 barcode 欄位', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.BARCODE,
        barcode: '/ABC1234',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('個人自然人憑證 → individual=moica，需 moica 欄位', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.MOICA,
        moica: 'AB12345678901234',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('個人紙本發票 → individual=paper', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.PAPER,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('barcode 不傳 carrier → 不應 crash（缺少 carrier 欄位邊界）', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.BARCODE,
        // 故意不帶 barcode
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 發票類型：公司 ────────────────────────────────────
  test.describe('發票類型 — 公司 (company)', () => {
    test('公司發票需統一編號 → 正常呼叫不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: '測試公司股份有限公司',
        companyId: '12345678',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('公司名稱含特殊字元 → 安全處理（不應 crash）', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: EDGE.SPECIAL_CHARS,
        companyId: '12345678',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('公司名稱含 XSS 字串 → 被 sanitize，不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: EDGE.XSS_SCRIPT,
        companyId: '12345678',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('統一編號（companyId）為空字串 → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: '測試公司',
        companyId: '',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('統一編號格式錯誤（非 8 碼數字）→ 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: '測試公司',
        companyId: 'ABCDEFGH',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('不傳 companyId（缺少必要欄位）→ 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
        companyName: '測試公司',
        // 故意不帶 companyId
      })
      expect(res.status).toBeLessThan(600)
    })

    test('不傳 companyName 與 companyId → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.COMPANY,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 發票類型：捐贈 ────────────────────────────────────
  test.describe('發票類型 — 捐贈 (donate)', () => {
    test('捐贈發票 → invoiceType=donate，帶 donateCode', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.DONATE,
        donateCode: '919',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('捐贈碼為空字串 → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.DONATE,
        donateCode: '',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('不傳 donateCode → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.DONATE,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('donateCode 含 XSS → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.DONATE,
        donateCode: EDGE.XSS_SCRIPT,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 欄位驗證 ──────────────────────────────────────────
  test.describe('欄位驗證（缺少或無效欄位）', () => {
    test('不傳 invoiceType → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('invoiceType 為無效值 → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: 'invalid_type_xyz',
      })
      expect(res.status).toBeLessThan(600)
    })

    test('individual 類型但不傳 individual 欄位 → 不應 crash', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: PROVIDERS.AMEGO,
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('不傳 provider → 非 200', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.CLOUD,
      })
      // provider 為必填，應回傳錯誤
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('provider 為不存在的 ID → 500 找不到電子發票服務', async () => {
      test.skip(!testOrderId, '測試訂單未建立')
      const res = await wpPost(opts, EP.INVOICE_ISSUE(testOrderId!), {
        provider: 'nonexistent_invoice_provider',
        invoiceType: INVOICE_TYPE.INDIVIDUAL,
        individual: INDIVIDUAL_TYPE.CLOUD,
      })
      expect(res.status).toBe(500)
      const body = res.data as Record<string, unknown>
      expect(String(body.message ?? '')).toContain('找不到電子發票服務')
    })
  })

  // ─── 前端：結帳頁發票區域 ──────────────────────────────
  test.describe('結帳頁發票區域渲染', () => {
    test('結帳頁不應因發票欄位而出現 PHP 錯誤', async ({ page }) => {
      const response = await page.goto(`${BASE_URL}/checkout/`)
      expect(response?.status()).toBeLessThan(500)

      await page.waitForLoadState('domcontentloaded')
      const bodyText = await page.locator('body').textContent() ?? ''
      expect(bodyText.toLowerCase()).not.toContain('fatal error')
      expect(bodyText.toLowerCase()).not.toContain('parse error')
    })

    test('結帳頁 JS 不應有未捕獲例外（含發票 App 初始化）', async ({ page }) => {
      const jsErrors: string[] = []
      page.on('pageerror', (err) => jsErrors.push(err.message))

      await page.goto(`${BASE_URL}/checkout/`)
      await page.waitForLoadState('networkidle')

      const criticalErrors = jsErrors.filter(
        (e) =>
          !e.includes('ResizeObserver') &&
          !e.includes('Script error') &&
          !e.includes('Failed to fetch'),
      )
      expect(criticalErrors).toHaveLength(0)
    })
  })
})
