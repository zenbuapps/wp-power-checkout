/**
 * Live Setup — 錄影驗收（04-live）站點前置：管理員 REST、provider 設定、測試商品、classic 結帳
 *
 * - 前置設定在「不錄影」的 admin context 完成（AdminSession），錄影 page 只呈現驗收畫面
 * - 所有寫入走站點 REST（power-checkout/v1/settings、wc/v3），不直連 DB
 * - 測試商品以固定 SKU `pc-live-*` 冪等建立（避開站上既有商品，例如 72）
 */
import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { ACCEPTANCE_VIDEO_DIR, adminCredentials, LIVE_BASE_URL } from './live-env.js'

/** REST 回應 */
export interface LiveApiResponse<T = any> {
  status: number
  json: T
  text: string
}

/** 以 wp-login 登入管理員（可用於錄影 page 或背景 context） */
export async function adminLogin(page: Page): Promise<void> {
  const { user, pass } = adminCredentials()
  await page.context().addCookies([
    { name: 'wordpress_test_cookie', value: 'WP+Cookie+check', url: LIVE_BASE_URL },
  ])
  await page.goto(`${LIVE_BASE_URL}/wp-login.php`)
  if (page.url().includes('/wp-admin')) {
    return
  }
  await page.fill('#user_login', user)
  await page.fill('#user_pass', pass)
  // 站點經 tunnel 較慢：不讓 click 等導航完成（actionTimeout 30s），改由 waitForURL 等 120s
  const navigated = page.waitForURL('**/wp-admin/**', { timeout: 120_000, waitUntil: 'domcontentloaded' })
  await page.locator('#user_pass').press('Enter')
  await navigated
}

/** 背景（不錄影）管理員 session：REST 前置 / 查驗 */
export class AdminSession {
  private constructor(
    public readonly context: BrowserContext,
    public readonly page: Page,
    private nonce: string,
  ) {}

  /** 建立並登入 */
  static async open(browser: Browser): Promise<AdminSession> {
    const context = await browser.newContext({ ignoreHTTPSErrors: true })
    const page = await context.newPage()
    await adminLogin(page)
    const nonce = await fetchRestNonce(page)
    return new AdminSession(context, page, nonce)
  }

  /** 關閉 */
  async close(): Promise<void> {
    await this.context.close()
  }

  /** 呼叫站點 REST（route 不含 /wp-json/ 前綴） */
  async api<T = any>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', route: string, data?: unknown): Promise<LiveApiResponse<T>> {
    const response = await this.context.request.fetch(`${LIVE_BASE_URL}/wp-json/${route.replace(/^\//, '')}`, {
      method,
      headers: { 'X-WP-Nonce': this.nonce, 'Content-Type': 'application/json' },
      data: data === undefined ? undefined : JSON.stringify(data),
      timeout: 120_000,
    })
    const text = await response.text()
    let json: any = null
    try {
      json = JSON.parse(text)
    } catch {
      json = null
    }
    return { status: response.status(), json, text }
  }

  /** 寫入 provider 設定（POST power-checkout/v1/settings/{id}，與設定頁相同端點；合併既有值） */
  async saveProviderSettings(providerId: string, data: Record<string, unknown>): Promise<void> {
    const res = await this.api('POST', `power-checkout/v1/settings/${providerId}`, data)
    expect(res.status, `儲存 ${providerId} 設定：${res.text.slice(0, 200)}`).toBe(200)
  }

  /** 讀取 provider 設定 */
  async getProviderSettings(providerId: string): Promise<Record<string, any>> {
    const res = await this.api('GET', `power-checkout/v1/settings/${providerId}`)
    expect(res.status).toBe(200)
    return (res.json?.data ?? {}) as Record<string, any>
  }

  /** 確保商店幣別 TWD、國家 TW（台灣金流 / 物流前提） */
  async ensureTaiwanStore(): Promise<void> {
    const currency = await this.api('GET', 'wc/v3/settings/general/woocommerce_currency')
    if (currency.json?.value !== 'TWD') {
      const res = await this.api('PUT', 'wc/v3/settings/general/woocommerce_currency', { value: 'TWD' })
      expect(res.status).toBe(200)
    }
  }

  /**
   * 以固定 SKU 冪等建立測試商品
   *
   * @returns 商品 ID
   */
  async ensureProduct(input: { sku: string, name: string, price: number, virtual: boolean, weight?: string }): Promise<number> {
    const found = await this.api<any[]>('GET', `wc/v3/products?sku=${encodeURIComponent(input.sku)}&status=any`)
    const body = {
      name: input.name,
      type: 'simple',
      status: 'publish',
      regular_price: String(input.price),
      virtual: input.virtual,
      weight: input.weight ?? '',
      manage_stock: false,
      stock_status: 'instock',
    }
    if (Array.isArray(found.json) && found.json.length > 0) {
      const id = Number(found.json[0].id)
      await this.api('PUT', `wc/v3/products/${id}`, body)
      return id
    }
    const created = await this.api('POST', 'wc/v3/products', { ...body, sku: input.sku })
    expect(created.status, created.text.slice(0, 200)).toBe(201)
    return Number(created.json.id)
  }

  /** 取得訂單（wc/v3） */
  async getOrder(orderId: number): Promise<any> {
    const res = await this.api('GET', `wc/v3/orders/${orderId}`)
    expect(res.status).toBe(200)
    return res.json
  }

  /** 取得訂單備註文字（新到舊） */
  async getOrderNotes(orderId: number): Promise<string[]> {
    const res = await this.api<any[]>('GET', `wc/v3/orders/${orderId}/notes`)
    return Array.isArray(res.json) ? res.json.map((n) => String(n.note).replace(/<[^>]+>/g, ' ')) : []
  }

  /** 取得訂單 meta 值 */
  async getOrderMeta(orderId: number, key: string): Promise<unknown> {
    const order = await this.getOrder(orderId)
    const meta = (order.meta_data as Array<{ key: string, value: unknown }>).find((m) => m.key === key)
    return meta?.value
  }
}

/** 取 REST nonce（WP core admin-ajax rest-nonce） */
export async function fetchRestNonce(page: Page): Promise<string> {
  const res = await page.request.get(`${LIVE_BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce`)
  const nonce = (await res.text()).trim()
  expect(nonce, '無法取得 REST nonce（管理員未登入？）').toMatch(/^[a-f0-9]{10}$/)
  return nonce
}

/** 於錄影畫面頂端顯示步驟字幕（只影響畫面，不影響頁面行為） */
export async function caption(page: Page, text: string): Promise<void> {
  await page.evaluate((t) => {
    let el = document.getElementById('pc-live-caption')
    if (!el) {
      el = document.createElement('div')
      el.id = 'pc-live-caption'
      el.setAttribute('style', [
        'position:fixed', 'left:0', 'right:0', 'bottom:0', 'z-index:2147483647', 'pointer-events:none',
        'background:rgba(17,24,39,.88)', 'color:#fff', 'font:600 18px/1.4 system-ui,sans-serif', 'padding:10px 16px',
      ].join(';'))
      document.body.appendChild(el)
    }
    el.textContent = `【P8 錄影驗收】${t}`
  }, text).catch(() => undefined)
}

/** 測試帳單資料（虛構資料） */
export const LIVE_BILLING = {
  first_name: '測試',
  last_name: '王',
  phone: '0912345678',
  email: 'pc-live-acceptance@example.com',
  address_1: '信義路五段7號',
  city: '台北市',
  postcode: '110',
}

/**
 * 訪客 classic 結帳：加入購物車 → 填帳單 → 選付款方式 → 下單
 *
 * @returns 下單後頁面 URL（order-received / 金流導轉頁）
 */
export async function classicGuestCheckout(page: Page, productId: number, gatewayId: string): Promise<void> {
  await page.goto(`${LIVE_BASE_URL}/?add-to-cart=${productId}`)
  await page.goto(`${LIVE_BASE_URL}/checkout/`)
  await page.waitForSelector('form.checkout', { timeout: 90_000 })

  const fill = async (selector: string, value: string): Promise<void> => {
    const el = page.locator(selector)
    if (await el.count() > 0 && await el.first().isVisible()) {
      await el.first().fill(value)
    }
  }
  // 國家 TW（select2 隱藏的原生 select）
  await page.evaluate(() => {
    const select = document.querySelector<HTMLSelectElement>('#billing_country')
    if (select && select.value !== 'TW') {
      select.value = 'TW'
      select.dispatchEvent(new Event('change', { bubbles: true }))
      ;(window as any).jQuery?.(select).trigger('change')
    }
  })
  await fill('#billing_last_name', LIVE_BILLING.last_name)
  await fill('#billing_first_name', LIVE_BILLING.first_name)
  await fill('#billing_phone', LIVE_BILLING.phone)
  await fill('#billing_email', LIVE_BILLING.email)
  await fill('#billing_address_1', LIVE_BILLING.address_1)
  await fill('#billing_city', LIVE_BILLING.city)
  await fill('#billing_postcode', LIVE_BILLING.postcode)

  // 佈景主題以自訂樣式隱藏原生 radio → 等 attached 後點 label
  await page.waitForSelector(`#payment_method_${gatewayId}`, { state: 'attached', timeout: 90_000 })
  await page.waitForFunction(() => !document.querySelector('.blockUI.blockOverlay'), undefined, { timeout: 90_000 })
  await page.locator(`label[for="payment_method_${gatewayId}"]`).scrollIntoViewIfNeeded()
  await page.locator(`label[for="payment_method_${gatewayId}"]`).click()
  await expect(page.locator(`#payment_method_${gatewayId}`)).toBeChecked()
  await page.waitForFunction(() => !document.querySelector('.blockUI.blockOverlay'), undefined, { timeout: 90_000 })
}

/** 送出 classic 結帳表單 */
export async function classicPlaceOrder(page: Page): Promise<void> {
  const terms = page.locator('#terms')
  if (await terms.count() > 0 && await terms.isVisible() && !(await terms.isChecked())) {
    await terms.check()
  }
  await page.locator('#place_order').click()
}

/** 從 order-received URL 解析訂單 ID */
export function orderIdFromUrl(url: string): number {
  const match = /order-received\/(\d+)/.exec(url) || /[?&]order(?:_id)?=(\d+)/.exec(url)
  expect(match, `無法從 URL 解析訂單 ID：${url}`).not.toBeNull()
  return Number(match![1])
}

/** 訂單編輯頁 URL（HPOS / legacy 皆可，WC 會自動導向） */
export function orderEditUrl(orderId: number): string {
  return `${LIVE_BASE_URL}/wp-admin/post.php?post=${orderId}&action=edit`
}

/** Power Checkout 設定頁 URL */
export const SETTINGS_PAGE_URL = `${LIVE_BASE_URL}/wp-admin/admin.php?page=wc-settings&tab=power_checkout_wc_settings`

/**
 * 關閉錄影 page 並把影片存到 acceptance-videos/<YYYY-MM-DD>/<name>.webm（已 gitignore）
 *
 * @returns 影片絕對路徑；未錄影時回空字串
 */
export async function saveAcceptanceVideo(page: Page, name: string): Promise<string> {
  const video = page.video()
  if (!video) {
    return ''
  }
  const now = new Date()
  const day = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  const target = path.join(ACCEPTANCE_VIDEO_DIR, day, `${name}.webm`)
  fs.mkdirSync(path.dirname(target), { recursive: true })
  await page.close().catch(() => undefined)
  await video.saveAs(target)
  console.log(`[live] 影片：${target}`)
  return target
}

/**
 * 於錄影 page 打開 Power Checkout 設定頁並進入指定 provider（provider 須已啟用才有設定齒輪）
 *
 * @param menu  左側選單（金流 / 物流）
 * @param title 卡片標題（method_title）
 */
export async function openProviderSettingsPage(page: Page, menu: '金流' | '物流', title: string): Promise<void> {
  await page.goto(SETTINGS_PAGE_URL)
  await page.locator('#power-checkout-wc-setting-app').first().waitFor({ timeout: 90_000 })
  await page.locator('.el-menu-item', { hasText: menu }).click()
  const card = page.locator('.el-card', { has: page.locator('h5', { hasText: title }) }).first()
  await card.waitFor({ timeout: 60_000 })
  await card.scrollIntoViewIfNeeded()
  await card.locator('a').first().click()
  await page.locator('.el-form').first().waitFor({ timeout: 60_000 })
  // 等設定 GET 完成（載入中 el-form 帶 opacity-25 + loading mask），否則回填會覆蓋剛輸入的值
  await page.waitForFunction(() => !document.querySelector('.el-form.opacity-25') && !document.querySelector('.el-form .el-loading-mask'), undefined, { timeout: 90_000 })
  await page.waitForTimeout(1_500)
}

/** 填寫 Element Plus 表單欄位（依 el-form-item label） */
export async function fillFormItem(page: Page, label: string, value: string): Promise<void> {
  const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const item = page.locator('.el-form-item', { has: page.locator('.el-form-item__label', { hasText: new RegExp(`^\\s*${escaped}\\s*$`) }) }).first()
  const input = item.locator('input:not([type=hidden]), textarea').first()
  await input.scrollIntoViewIfNeeded()
  await input.fill(value)
  await input.blur()
}

/** 點設定頁「儲存」並等成功通知 */
export async function clickSave(page: Page): Promise<void> {
  const button = page.locator('.el-button', { hasText: /^\s*儲存\s*$/ }).last()
  await button.scrollIntoViewIfNeeded()
  await button.click()
  await expect(page.locator('.el-notification, .el-message').filter({ hasText: /成功/ }).first()).toBeVisible({ timeout: 60_000 })
}

/** 點「測試連線」並回傳結果區塊文字（成功 alert 或錯誤通知） */
export async function clickPing(page: Page): Promise<string> {
  const button = page.locator('.el-button', { hasText: '測試連線' }).first()
  await button.scrollIntoViewIfNeeded()
  const response = page.waitForResponse((r) => /\/logistics\/[a-z_]+\/ping/.test(r.url()), { timeout: 120_000 })
  await button.click()
  const res = await response
  let body = await res.text()
  try {
    body = JSON.stringify(JSON.parse(body)) // 還原 \uXXXX 為中文，便於 log 與比對
  } catch {
    // 非 JSON（例如 CDN 錯誤頁）原樣保留
  }
  await page.waitForTimeout(2_500)
  return `HTTP ${res.status()} ${body.slice(0, 400)}`
}
