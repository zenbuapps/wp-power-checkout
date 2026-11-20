/**
 * P8 錄影驗收：新竹物流（hct_logistics）— 降級驗收（唯讀）
 *
 * 新竹未核發測試號段，官方文件測試帳號（test / test1）實測屬「正式環境」帳號 →
 * **禁止建單 / 取消 / 退貨**（會打到正式系統）。只做唯讀驗證：
 * 1. 設定頁填寫帳密（測試站）→ 儲存 → 測試連線：新竹回「測試環境請勿使用正式公司名稱或密碼」→ 失敗訊息
 * 2. 切換正式站 → 儲存 → 測試連線（QueryEDELNO_Json 查一個不存在的訂單編號，唯讀）→ 連線成功
 *
 * 憑證：LIVE_ENV_FILES 指向新竹 .env.local（HCT_EDI_COMPANY / HCT_EDI_PASSWORD，官方文件公開測試帳號）。
 * 測試結束把環境切回測試站，避免站上留下正式站設定。
 */
import { expect, test } from '@playwright/test'
import { requireLiveEnv } from '../helpers/live-env.js'
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

const PROVIDER_ID = 'hct_logistics'

test('新竹物流：設定頁 + 正式站唯讀測試連線', async ({ page, browser }) => {
  const creds = requireLiveEnv({
    company: ['HCT_EDI_COMPANY'],
    password: ['HCT_EDI_PASSWORD'],
  })
  const admin = await AdminSession.open(browser)
  try {
    await admin.saveProviderSettings(PROVIDER_ID, { enabled: 'yes', mode: 'test', company: '', password: '' })

    await adminLogin(page)
    await openProviderSettingsPage(page, '物流', '新竹物流')
    await caption(page, '新竹物流設定頁：填寫官方文件測試帳號（實測屬正式環境），先以測試站儲存')
    await fillFormItem(page, '公司名稱', creds.company)
    await fillFormItem(page, '密碼', creds.password)
    await fillFormItem(page, '出貨人名稱', 'PC Live 測試')
    await clickSave(page)

    // 測試站 + 正式帳號 → 新竹拒絕，畫面顯示失敗訊息
    await caption(page, '測試連線（測試站）：正式帳號應被新竹測試站拒絕並提示切換正式站')
    const testResult = await clickPing(page)
    console.log(`[hct] 測試站測試連線：${testResult}`)
    expect(testResult).not.toMatch(/^HTTP 200/)
    expect(testResult).toMatch(/測試環境|正式/)
    await expect(page.locator('.el-notification, .el-message').last()).toBeVisible({ timeout: 30_000 })
    await page.waitForTimeout(3_000)

    // 切正式站（唯讀查詢，不建單）
    await caption(page, '切換正式站（只做唯讀查詢，禁止建單 / 取消 / 退貨）')
    await page.locator('.el-radio', { hasText: '正式站' }).click()
    await clickSave(page)
    const prodResult = await clickPing(page)
    console.log(`[hct] 正式站測試連線：${prodResult}`)
    expect(prodResult).toMatch(/^HTTP 200/)
    const success = page.locator('.el-alert--success', { hasText: '連線成功（正式站）' })
    await expect(success).toBeVisible({ timeout: 30_000 })
    console.log(`[hct] 畫面：${(await success.innerText()).replace(/\s+/g, ' ')}`)
    await caption(page, '正式站唯讀測試連線成功（QueryEDELNO 查不存在的訂單編號）')
    await page.waitForTimeout(4_000)
  } finally {
    // 還原為測試站，避免站上留下正式站設定
    await admin.saveProviderSettings(PROVIDER_ID, { mode: 'test' }).catch(() => undefined)
    await admin.close()
    await saveAcceptanceVideo(page, 'hct_logistics-degraded')
  }
})
