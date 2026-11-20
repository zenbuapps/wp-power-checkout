/**
 * P8 錄影驗收：黑貓宅急便直串（tcat_logistics）— 降級驗收（憑證為佔位值）
 *
 * .env.local 的 TCAT_CUSTOMER_ID_TEST / TOKEN_TEST 為 `<...>` 佔位 → 無法建單。錄影內容：
 * 1. 設定頁填寫佔位契客代號 / 授權碼 + 寄件人 → 儲存
 * 2. 測試連線（ParsingAddress 查寄件地址郵號，唯讀）→ 回郵號，但此接口不驗證授權碼 → 畫面明示未驗證
 *
 * 佔位值為明顯假值（非真實憑證）。
 */
import { expect, test } from '@playwright/test'
import {
  AdminSession,
  adminLogin,
  caption,
  clickPing,
  clickSave,
  fillFormItem,
  openProviderSettingsPage,
  saveAcceptanceVideo,
} from '../helpers/live-setup.js'

const PROVIDER_ID = 'tcat_logistics'

test('黑貓宅急便：設定頁填寫儲存 + 測試連線（明示授權碼未驗證）', async ({ page, browser }) => {
  const admin = await AdminSession.open(browser)
  try {
    await admin.saveProviderSettings(PROVIDER_ID, { enabled: 'yes', mode: 'test', customer_id: '', customer_token: '' })

    await adminLogin(page)
    await openProviderSettingsPage(page, '物流', '黑貓宅急便')
    await caption(page, '黑貓宅急便設定頁：填寫佔位契客代號 / 授權碼（尚未取得測試授權碼）')
    await fillFormItem(page, '契客代號', '0000000000')
    await fillFormItem(page, '授權碼', 'placeholder-token')
    await fillFormItem(page, '寄件人姓名', 'PC Live 測試')
    await fillFormItem(page, '寄件人電話', '0223456789')
    await fillFormItem(page, '寄件人手機', '0912345678')
    await fillFormItem(page, '寄件人地址', '台北市信義區信義路五段7號')
    await clickSave(page)

    await caption(page, '測試連線：ParsingAddress 唯讀查寄件地址郵號（此接口不驗證授權碼）')
    const result = await clickPing(page)
    console.log(`[tcat_logistics] 測試連線：${result}`)
    // P8 實測：測試站以佔位憑證呼叫 ParsingAddress 仍回郵號 → 不可宣稱授權碼已驗證（fix 6877c7c）
    expect(result).toMatch(/^HTTP 200/)
    expect(result).toContain('"credentials_verified":false')
    const info = page.locator('.el-alert', { hasText: '不驗證契客代號與授權碼' })
    await expect(info).toBeVisible({ timeout: 30_000 })
    const success = page.locator('.el-alert', { hasText: '連線成功' }).first()
    console.log(`[tcat_logistics] 畫面：${(await success.innerText()).replace(/\s+/g, ' ')} ／ ${(await info.innerText()).replace(/\s+/g, ' ')}`)
    await info.scrollIntoViewIfNeeded()
    await caption(page, '黑貓回郵號（已連上），並明示授權碼未驗證、需以實際建單確認（佔位憑證無法建單）')
    await page.waitForTimeout(4_000)
  } finally {
    await admin.close()
    await saveAcceptanceVideo(page, 'tcat_logistics-degraded')
  }
})
