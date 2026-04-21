/**
 * Issue #12 — SLP 分期期數設定的持久化與防呆
 *
 * 覆蓋：
 * - Red 回歸：商家取消勾選 0 期後，設定儲存與讀回都不應含 '0'
 * - Red 回歸：商家儲存 installmentCounts = [3, 6] 後，讀回必須完全一致
 * - 連續存兩次 payload，驗證 wp_parse_args 不會累加陣列
 *
 * 這是 API-level E2E 測試，直接戳 REST /settings/{id} 端點；
 * 前端 A 案（表單 validator）由 Vue 單元層確保不送空陣列，
 * 此處主要驗證「後端能正確持久化非空陣列、不會再出現 0」。
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

type SlpSettings = {
  enabled?: string
  allowPaymentMethodList?: string[]
  paymentMethodOptions?: {
    CreditCard?: { installmentCounts?: string[] }
    ChaileaseBNPL?: { installmentCounts?: string[] }
    [key: string]: unknown
  }
  [key: string]: unknown
}

test.describe('SLP 分期期數設定 — Issue #12 回歸 (A 案：擋 empty installmentCounts)', () => {
  let opts: ApiOptions
  // 備份原始 installmentCounts，測試後還原
  let original: SlpSettings = {}

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }

    const res = await wpGet<{ data: SlpSettings }>(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    if (res.status === 200) {
      const body = res.data as { data?: SlpSettings } & SlpSettings
      const data = (body.data ?? body) as SlpSettings
      original = {
        allowPaymentMethodList: data.allowPaymentMethodList,
        paymentMethodOptions: data.paymentMethodOptions,
      }
    }
  })

  test.afterAll(async ({ request }) => {
    // 還原原始 installmentCounts，避免污染其他測試
    const nonce = getNonce()
    const restoreOpts: ApiOptions = { request, baseURL: BASE_URL, nonce }
    await wpPost(restoreOpts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: original.paymentMethodOptions ?? {
        CreditCard: { installmentCounts: ['0', '3', '6', '9', '12', '18', '24'] },
        ChaileaseBNPL: { installmentCounts: ['0', '3', '6', '12', '18', '24', '30', '36'] },
      },
    }).catch(() => { /* ignore restore failures */ })
  })

  // ─── Issue #12 主測試：取消勾選 0 期後，持久化必須不含 '0' ────────
  test('CreditCard 取消勾選 0 期後，讀回不含 0 期', async () => {
    const postRes = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: {
        CreditCard: { installmentCounts: ['3', '6'] },
      },
    })
    expect(postRes.status).toBe(200)

    const getRes = await wpGet<{ data: SlpSettings } | SlpSettings>(
      opts,
      EP.SETTINGS_SINGLE(PROVIDERS.SLP),
    )
    expect(getRes.status).toBe(200)
    const body = getRes.data as { data?: SlpSettings } & SlpSettings
    const data = (body.data ?? body) as SlpSettings
    const creditCounts = data.paymentMethodOptions?.CreditCard?.installmentCounts ?? []

    expect(creditCounts).toEqual(['3', '6'])
    expect(creditCounts).not.toContain('0')
  })

  test('ChaileaseBNPL 取消勾選 0 期後，讀回不含 0 期', async () => {
    const postRes = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: {
        ChaileaseBNPL: { installmentCounts: ['3', '6', '12'] },
      },
    })
    expect(postRes.status).toBe(200)

    const getRes = await wpGet<{ data: SlpSettings } | SlpSettings>(
      opts,
      EP.SETTINGS_SINGLE(PROVIDERS.SLP),
    )
    expect(getRes.status).toBe(200)
    const body = getRes.data as { data?: SlpSettings } & SlpSettings
    const data = (body.data ?? body) as SlpSettings
    const bnplCounts = data.paymentMethodOptions?.ChaileaseBNPL?.installmentCounts ?? []

    expect(bnplCounts).toEqual(['3', '6', '12'])
    expect(bnplCounts).not.toContain('0')
  })

  // ─── 連續儲存：wp_parse_args 不應累加陣列 ──────────────────────
  test('連續兩次儲存不同期數，讀回為最後一次的值（不累加）', async () => {
    // 先存 [3, 6]
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: {
        ChaileaseBNPL: { installmentCounts: ['3', '6'] },
      },
    })

    // 再改成 [6, 12]
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: {
        ChaileaseBNPL: { installmentCounts: ['6', '12'] },
      },
    })

    const getRes = await wpGet<{ data: SlpSettings } | SlpSettings>(
      opts,
      EP.SETTINGS_SINGLE(PROVIDERS.SLP),
    )
    const body = getRes.data as { data?: SlpSettings } & SlpSettings
    const data = (body.data ?? body) as SlpSettings
    const bnplCounts = data.paymentMethodOptions?.ChaileaseBNPL?.installmentCounts ?? []

    // 必須是取代而非累加；長度為 2，非 4
    expect(bnplCounts).toEqual(['6', '12'])
    expect(bnplCounts).toHaveLength(2)
  })

  // ─── 雙 method 同時更新 ────────────────────────────────────
  test('同時更新 CreditCard 與 ChaileaseBNPL，兩者獨立正確持久化', async () => {
    const postRes = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      paymentMethodOptions: {
        CreditCard: { installmentCounts: ['3', '6', '12'] },
        ChaileaseBNPL: { installmentCounts: ['6', '12', '24'] },
      },
    })
    expect(postRes.status).toBe(200)

    const getRes = await wpGet<{ data: SlpSettings } | SlpSettings>(
      opts,
      EP.SETTINGS_SINGLE(PROVIDERS.SLP),
    )
    const body = getRes.data as { data?: SlpSettings } & SlpSettings
    const data = (body.data ?? body) as SlpSettings

    expect(data.paymentMethodOptions?.CreditCard?.installmentCounts).toEqual(['3', '6', '12'])
    expect(data.paymentMethodOptions?.ChaileaseBNPL?.installmentCounts).toEqual(['6', '12', '24'])
  })
})
