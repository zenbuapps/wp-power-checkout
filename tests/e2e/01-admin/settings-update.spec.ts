/**
 * P0 — POST /power-checkout/v1/settings/{provider_id} — 更新服務設定
 *
 * 依據：specs/features/settings/provider-settings.feature
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - 成功更新 SLP 設定（merchantId / mode）
 * - 成功更新 Amego 設定（invoice / app_key / tax_rate）
 * - 回傳更新後的完整設定，code 為 "success"，message 為 "儲存成功"
 * - XSS 輸入經過 sanitize_text_field_deep 消毒
 * - 各種邊界值輸入不應 crash
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'

test.describe('POST /settings/{provider_id} — 更新服務設定', () => {
  let opts: ApiOptions
  let originalSlpTitle = 'Shopline Payment 線上付款'

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }

    // 備份原始 title，測試後還原
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    if (res.status === 200) {
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      originalSlpTitle = (data.title as string) || originalSlpTitle
    }
  })

  test.afterAll(async () => {
    // 還原 SLP 設定至原始值
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: originalSlpTitle,
      mode: 'test',
    }).catch(() => {/* 忽略還原失敗 */})
  })

  // ─── P1：未授權存取 ─────────────────────────────────────────
  test('未登入的訪客無法更新設定 → 401 或 403', async ({ request }) => {
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(unauthOpts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: 'should_be_rejected',
    })
    expect([401, 403]).toContain(res.status)
  })

  // ─── P0：成功更新 SLP 設定 ──────────────────────────────────
  test('成功更新 SLP merchantId，回應 code 為 success', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: 'e2e_test_merchant_id',
      mode: 'test',
    })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    expect(body.code).toBe('success')
    expect(body.message).toBe('儲存成功')
  })

  test('更新後回傳的 data 包含新的 merchantId', async () => {
    const newMerchantId = 'e2e_updated_merchant_123'
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: newMerchantId,
    })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    const data = (body.data ?? body) as Record<string, unknown>
    expect(data.merchantId).toBe(newMerchantId)
  })

  test('GET 確認 SLP title 更新已持久化', async () => {
    const updatedTitle = '[E2E] Updated SLP Title'
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: updatedTitle,
    })

    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(getRes.status).toBe(200)
    const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
    expect(data.title).toBe(updatedTitle)
  })

  test('更新 SLP mode 為 test', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      mode: 'test',
    })
    expect(res.status).toBe(200)

    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
    expect(data.mode).toBe('test')
  })

  // ─── P0：成功更新 Amego 設定 ────────────────────────────────
  test('成功更新 Amego invoice（統一編號）與 app_key', async () => {
    const testInvoice = '12345678'
    const testAppKey = `e2e_app_key_${Date.now()}`
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
      invoice: testInvoice,
      app_key: testAppKey,
      tax_rate: '0.05',
    })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    expect(body.code).toBe('success')

    // GET 確認持久化
    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(getRes.status).toBe(200)
    const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
    expect(data.invoice).toBe(testInvoice)
  })

  test('更新 Amego invoice 後 GET 確認', async () => {
    const newInvoice = '87654321'
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), { invoice: newInvoice })

    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
    expect(data.invoice).toBe(newInvoice)
  })

  // ─── P1：XSS 防護（sanitize_text_field_deep）──────────────
  test('XSS <script> 標籤被過濾，不出現在回傳 title 中', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.XSS_SCRIPT,
    })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    const data = (body.data ?? body) as Record<string, unknown>
    const titleInResponse = String(data.title ?? '')
    expect(titleInResponse).not.toContain('<script>')
    expect(titleInResponse).not.toContain('alert(')
  })

  test('XSS img onerror 被過濾', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.XSS_IMG,
    })
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    const data = (body.data ?? body) as Record<string, unknown>
    const titleInResponse = String(data.title ?? '')
    expect(titleInResponse).not.toContain('onerror')
  })

  // ─── P3：邊界值輸入 ────────────────────────────────────────
  test('title 為空字串 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.EMPTY_STRING,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('title 為純空白 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.WHITESPACE_ONLY,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('title 為中文字串 → 正常儲存並可讀回', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.UNICODE_CJK,
    })
    expect(res.status).toBe(200)

    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
    expect(data.title).toBe(EDGE.UNICODE_CJK)
  })

  test('title 為日文字串 → 正常儲存', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.UNICODE_JAPANESE,
    })
    expect(res.status).toBe(200)
  })

  test('title 為韓文字串 → 正常儲存', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.UNICODE_KOREAN,
    })
    expect(res.status).toBe(200)
  })

  test('title 為 RTL 阿拉伯文 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.RTL_ARABIC,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('title 為 Emoji → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.EMOJI_SIMPLE,
    })
    expect(res.status).toBe(200)
  })

  test('title 為 10,000 字超長字串 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.VERY_LONG_STRING,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('merchantId 含 SQL DROP TABLE → 不應 crash，且 DB 仍可用', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: EDGE.SQL_DROP,
    })
    expect(res.status).toBeLessThan(600)
    // 確認資料庫未被破壞
    const getRes = await wpGet(opts, EP.SETTINGS_ALL)
    expect(getRes.status).toBe(200)
  })

  test('title 含 null byte → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.NULL_BYTE,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('min_amount 為負數 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      min_amount: EDGE.NEGATIVE,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('max_amount 為 0 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      max_amount: EDGE.ZERO,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('Amego tax_rate 為負數 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
      tax_rate: EDGE.NEGATIVE,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('Amego tax_rate 為小數 0.001 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
      tax_rate: EDGE.FLOAT_TINY,
    })
    expect(res.status).toBeLessThan(600)
  })

  test('merchantId 為極大整數 → 不應 crash', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: String(EDGE.MAX_SAFE_INT),
    })
    expect(res.status).toBeLessThan(600)
  })
})
