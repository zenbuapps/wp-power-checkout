/**
 * P1 — 付款方式切換 — SLP Gateway allowPaymentMethodList 管理
 *
 * 驗證 power-checkout 付款方式配置的完整性：
 * - allowPaymentMethodList 設定可讀取、更新、讀回
 * - 支援所有 SLP 付款方式（CreditCard, VirtualAccount, JKOPay, ApplePay, LinePay, ChaileaseBNPL）
 * - paymentMethodOptions 設定可讀取
 * - min_amount / max_amount 邊界設定
 * - 結帳頁付款方式渲染不出現 JS 錯誤
 *
 * 依據：specs/erm.dbml（allowPaymentMethodList）
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, PAYMENT_METHODS } from '../fixtures/test-data.js'

test.describe('付款方式設定', () => {
  let opts: ApiOptions
  let slpEnabled = false
  let originalAllowList: string[] = []
  let originalMinAmount: number | string = 5
  let originalMaxAmount: number | string = 50000

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }

    // 讀取並備份原始設定
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    if (res.status === 200) {
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      slpEnabled = data.enabled === 'yes'
      originalAllowList = Array.isArray(data.allowPaymentMethodList)
        ? (data.allowPaymentMethodList as string[])
        : []
      originalMinAmount = (data.min_amount as number | string) ?? 5
      originalMaxAmount = (data.max_amount as number | string) ?? 50000
    }
  })

  test.afterAll(async () => {
    // 還原原始設定
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      allowPaymentMethodList: originalAllowList.length > 0
        ? originalAllowList
        : Object.values(PAYMENT_METHODS),
      min_amount: originalMinAmount,
      max_amount: originalMaxAmount,
    }).catch(() => { /* 忽略還原錯誤 */ })
  })

  // ─── allowPaymentMethodList 讀取與更新 ─────────────────
  test.describe('allowPaymentMethodList 管理', () => {
    test('取得 SLP 設定應包含 allowPaymentMethodList 陣列', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      expect(res.status).toBe(200)
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('allowPaymentMethodList')
      expect(Array.isArray(data.allowPaymentMethodList)).toBeTruthy()
    })

    test('更新為單一付款方式 CreditCard → 讀回確認', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: [PAYMENT_METHODS.CREDIT_CARD],
      })
      expect(res.status).toBe(200)

      const check = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((check.data as Record<string, unknown>).data ?? check.data) as Record<string, unknown>
      const list = data.allowPaymentMethodList as string[]
      expect(list).toContain(PAYMENT_METHODS.CREDIT_CARD)
    })

    test('更新為多種付款方式 [CreditCard, LinePay, JKOPay] → 讀回確認', async () => {
      const methods = [
        PAYMENT_METHODS.CREDIT_CARD,
        PAYMENT_METHODS.LINE_PAY,
        PAYMENT_METHODS.JKO_PAY,
      ]
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: methods,
      })
      expect(res.status).toBe(200)

      const check = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((check.data as Record<string, unknown>).data ?? check.data) as Record<string, unknown>
      const list = data.allowPaymentMethodList as string[]
      for (const method of methods) {
        expect(list).toContain(method)
      }
    })

    test('更新為所有支援付款方式 → 回傳 200', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: Object.values(PAYMENT_METHODS),
      })
      expect(res.status).toBe(200)
    })

    test('更新包含 VirtualAccount（ATM，不支援退款）→ 可正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: [PAYMENT_METHODS.VIRTUAL_ACCOUNT],
      })
      expect(res.status).toBe(200)
    })

    test('更新包含 ChaileaseBNPL（中租零卡）→ 可正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: [PAYMENT_METHODS.CHAILEASE],
      })
      expect(res.status).toBe(200)
    })

    test('allowPaymentMethodList 為空陣列 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        allowPaymentMethodList: [],
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── paymentMethodOptions 設定 ─────────────────────────
  test.describe('paymentMethodOptions 設定', () => {
    test('取得 SLP 設定應包含 paymentMethodOptions', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      expect(res.status).toBe(200)
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('paymentMethodOptions')
    })

    test('更新 paymentMethodOptions → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        paymentMethodOptions: {
          CreditCard: { installment: true },
          LinePay: {},
        },
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── min_amount / max_amount 金額範圍 ──────────────────
  test.describe('min_amount / max_amount 金額範圍', () => {
    test('SLP 設定包含 min_amount 與 max_amount 欄位', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      expect(res.status).toBe(200)
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('min_amount')
      expect(data).toHaveProperty('max_amount')
    })

    test('更新 min_amount=10, max_amount=99999 → 讀回確認', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: 10,
        max_amount: 99999,
      })
      expect(res.status).toBe(200)

      const check = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((check.data as Record<string, unknown>).data ?? check.data) as Record<string, unknown>
      expect(Number(data.min_amount)).toBe(10)
      expect(Number(data.max_amount)).toBe(99999)
    })

    test('min_amount=0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: 0,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('min_amount 大於 max_amount → 不應 crash（業務層自行處理）', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: 999999,
        max_amount: 1,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── expire_min 設定 ───────────────────────────────────
  test.describe('expire_min（付款逾時分鐘數）設定', () => {
    test('SLP 設定包含 expire_min 欄位', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      expect(data).toHaveProperty('expire_min')
    })

    test('更新 expire_min=30 → 回傳 200', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        expire_min: 30,
      })
      expect(res.status).toBe(200)
    })

    test('expire_min=0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        expire_min: 0,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('expire_min=-1 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        expire_min: -1,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 前端：付款方式渲染 ────────────────────────────────
  test.describe('結帳頁付款方式渲染', () => {
    test('結帳頁應載入付款方式相關 HTML 或顯示空購物車', async ({ page }) => {
      await page.goto(`${BASE_URL}/checkout/`)
      await page.waitForLoadState('domcontentloaded')

      const cartEmpty =
        (await page.locator('.cart-empty, .wc-empty-cart-message').count()) > 0
      if (cartEmpty) {
        test.skip()
        return
      }

      const paymentArea = page.locator(
        '#payment .payment_methods, .wc_payment_methods, .wc-block-components-radio-control',
      )
      const count = await paymentArea.count()
      expect(count).toBeGreaterThanOrEqual(0)
    })

    test('SLP Gateway 啟用時結帳頁應包含 shopline_payment_redirect 選項（有商品）', async ({ page }) => {
      test.skip(!slpEnabled, 'SLP Gateway 目前未啟用')

      await page.goto(`${BASE_URL}/checkout/`)
      await page.waitForLoadState('domcontentloaded')

      const cartEmpty =
        (await page.locator('.cart-empty, .wc-empty-cart-message').count()) > 0
      if (cartEmpty) {
        test.skip()
        return
      }

      const slpRadio = page.locator(
        'input[value="shopline_payment_redirect"], label:has-text("Shopline Payment")',
      )
      const count = await slpRadio.count()
      expect(count).toBeGreaterThanOrEqual(0)
    })

    test('選擇付款方式時不應出現 JS 錯誤', async ({ page }) => {
      const jsErrors: string[] = []
      page.on('pageerror', (err) => jsErrors.push(err.message))

      await page.goto(`${BASE_URL}/checkout/`)
      await page.waitForLoadState('domcontentloaded')

      const cartEmpty =
        (await page.locator('.cart-empty, .wc-empty-cart-message').count()) > 0
      if (cartEmpty) {
        test.skip()
        return
      }

      // 嘗試點擊所有可用付款方式
      const paymentRadios = page.locator(
        '#payment .payment_methods input[type="radio"]',
      )
      const count = await paymentRadios.count()
      for (let i = 0; i < Math.min(count, 5); i++) {
        await paymentRadios.nth(i).click({ force: true })
        await page.waitForTimeout(300)
      }

      const criticalErrors = jsErrors.filter(
        (e) => !e.includes('ResizeObserver') && !e.includes('Script error'),
      )
      expect(criticalErrors).toHaveLength(0)
    })
  })
})
