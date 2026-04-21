/**
 * P0 — POST /power-checkout/v1/settings/{provider_id}/toggle — 開關服務
 *
 * 依據：specs/features/settings/provider-settings.feature
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - toggle amego yes → no（禁用成功，message 包含「禁用成功」，data 為 provider_id）
 * - toggle SLP no → yes（啟用成功，message 包含「啟用成功」）
 * - 連續兩次 toggle 回到原始狀態（冪等性驗證）
 * - 邊界：不存在的 provider_id → 非 200
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

test.describe('POST /settings/{provider_id}/toggle — 開關服務', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── P1：未授權存取 ─────────────────────────────────────────
  test('未登入的訪客無法切換服務 → 401 或 403', async ({ request }) => {
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(unauthOpts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect([401, 403]).toContain(res.status)
  })

  // ─── P0：Amego toggle 切換邏輯 ─────────────────────────────
  test('toggle amego：enabled 在 yes/no 之間切換', async () => {
    // 讀取目前狀態
    const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(before.status).toBe(200)
    const dataBefore = ((before.data as Record<string, unknown>).data ?? before.data) as Record<string, unknown>
    const enabledBefore = dataBefore.enabled as string
    const expectedAfter = enabledBefore === 'yes' ? 'no' : 'yes'

    // 執行 toggle
    const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect(toggleRes.status).toBe(200)

    const toggleBody = toggleRes.data as Record<string, unknown>
    expect(toggleBody.code).toBe('success')

    // spec 要求 message 包含「啟用成功」或「禁用成功」
    const message = toggleBody.message as string
    if (expectedAfter === 'no') {
      expect(message).toContain('禁用成功')
    } else {
      expect(message).toContain('啟用成功')
    }

    // spec 要求 data 為 provider_id 字串
    expect(toggleBody.data).toBe(PROVIDERS.AMEGO)

    // GET 確認狀態已更新
    const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    const dataAfter = ((after.data as Record<string, unknown>).data ?? after.data) as Record<string, unknown>
    expect(dataAfter.enabled).toBe(expectedAfter)

    // 還原原始狀態
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
  })

  // ─── P0：SLP toggle 切換邏輯 ───────────────────────────────
  test('toggle SLP：enabled 在 yes/no 之間切換', async () => {
    const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(before.status).toBe(200)
    const dataBefore = ((before.data as Record<string, unknown>).data ?? before.data) as Record<string, unknown>
    const expectedAfter = dataBefore.enabled === 'yes' ? 'no' : 'yes'

    const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.SLP), {})
    expect(toggleRes.status).toBe(200)

    // GET 確認狀態已更新
    const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    const dataAfter = ((after.data as Record<string, unknown>).data ?? after.data) as Record<string, unknown>
    expect(dataAfter.enabled).toBe(expectedAfter)

    // 還原原始狀態
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.SLP), {})
  })

  // ─── P2：連續兩次 toggle 回到原始狀態 ─────────────────────
  test('連續兩次 toggle amego，最終回到原始 enabled 值', async () => {
    const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    const dataBefore = ((before.data as Record<string, unknown>).data ?? before.data) as Record<string, unknown>
    const originalEnabled = dataBefore.enabled as string

    // 第一次 toggle
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    // 第二次 toggle（還原）
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})

    // 確認回到原始值
    const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    const dataAfter = ((after.data as Record<string, unknown>).data ?? after.data) as Record<string, unknown>
    expect(dataAfter.enabled).toBe(originalEnabled)
  })

  // ─── P1：不存在的 provider_id ──────────────────────────────
  test('toggle 不存在的 provider → 非 200', async () => {
    const res = await wpPost(opts, EP.SETTINGS_TOGGLE('nonexistent_provider'), {})
    // 應回傳錯誤（provider 不在容器中）
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  // ─── P3：toggle 回應結構驗證 ───────────────────────────────
  test('toggle 回應 data 為 provider_id 字串', async () => {
    const res = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    // spec 明確要求 data 為 provider_id
    expect(body.data).toBe(PROVIDERS.AMEGO)

    // 還原
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
  })
})
