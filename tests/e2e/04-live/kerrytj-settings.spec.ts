/**
 * P8 錄影驗收：嘉里大榮（kerrytj_logistics）— 降級驗收（無憑證）
 *
 * 嘉里大榮 EDI 憑證未核發（.env.local 為空）→ 不打建單 API。錄影內容：
 * 1. 設定頁填寫（佔位帳密）+ 號段前端驗證（錯誤格式 → 錯誤訊息；修正後通過）
 * 2. 儲存 → 重新進入顯示「常溫 / 低溫號段狀態：剩餘 N 號，下一號 …」
 * 3. 測試連線 → 佔位帳密被嘉里大榮拒絕 → 顯示失敗訊息（AUTH，非 200）
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

const PROVIDER_ID = 'kerrytj_logistics'

test('嘉里大榮：設定頁填寫儲存 + 號段驗證 + 測試連線失敗訊息', async ({ page, browser }) => {
  const admin = await AdminSession.open(browser)
  try {
    // 前置：啟用（設定齒輪才會出現）並清空帳密與號段，錄影從空白開始填
    await admin.saveProviderSettings(PROVIDER_ID, {
      enabled: 'yes',
      mode: 'test',
      username: '',
      password: '',
      customer_no: '',
      ambient_waybill_start: '',
      ambient_waybill_end: '',
      cold_waybill_start: '',
      cold_waybill_end: '',
    })

    await adminLogin(page)
    await openProviderSettingsPage(page, '物流', '嘉里大榮')
    await caption(page, '嘉里大榮設定頁：填寫佔位帳密（尚未核發正式 / 測試憑證）')

    await fillFormItem(page, '帳號', 'placeholder-user')
    await fillFormItem(page, '密碼', 'placeholder-pass')
    await fillFormItem(page, '客戶編號', '12345678')
    await fillFormItem(page, '寄件人名稱', 'PC Live 測試')
    await fillFormItem(page, '寄件人電話', '0223456789')
    await fillFormItem(page, '郵遞區號', '110')
    await fillFormItem(page, '寄件地址', '台北市信義區信義路五段7號')

    // 號段驗證：錯誤格式
    await caption(page, '號段驗證：常溫須 11 碼、低溫須 9 碼流水號或檢查碼正確的 10 碼')
    await fillFormItem(page, '常溫起號', '123')
    await fillFormItem(page, '常溫迄號', '456')
    await fillFormItem(page, '低溫起號', '1234567890') // 檢查碼錯誤（123456789 % 7 = 1）
    await fillFormItem(page, '低溫迄號', '1234567899')
    await expect(page.getByText('常溫號段起訖須同時填寫 11 碼數字').first()).toBeVisible()
    await expect(page.getByText('低溫號段起訖須同時填寫 9 碼流水號或檢查碼正確的 10 碼單號').first()).toBeVisible()
    await page.waitForTimeout(2_500)

    // 起號 > 迄號
    await fillFormItem(page, '常溫起號', '90000000100')
    await fillFormItem(page, '常溫迄號', '90000000001')
    await expect(page.getByText('起號不可大於迄號').first()).toBeVisible()
    await page.waitForTimeout(2_000)

    // 修正為合法號段
    await caption(page, '修正號段：常溫 90000000001–90000000100；低溫 800000001–8000001004（10 碼含檢查碼）')
    await fillFormItem(page, '常溫起號', '90000000001')
    await fillFormItem(page, '常溫迄號', '90000000100')
    await fillFormItem(page, '低溫起號', '800000001')
    await fillFormItem(page, '低溫迄號', '8000001004') // 800000100 % 7 = 4
    // 起訖互相參照：迄號改完後再觸發一次起號驗證，讓舊錯誤訊息消失
    await fillFormItem(page, '常溫起號', '90000000001')
    await fillFormItem(page, '低溫起號', '800000001')
    await page.waitForTimeout(500)
    console.log(`[kerrytj] 表單錯誤：${JSON.stringify(await page.locator('.el-form-item.is-error').evaluateAll((els) => els.map((e) => e.textContent?.replace(/\s+/g, ' ').slice(0, 80))))}`)
    await expect(page.locator('.el-form-item__error')).toHaveCount(0)
    await clickSave(page)

    // 重新進入：號段狀態
    await openProviderSettingsPage(page, '物流', '嘉里大榮')
    await caption(page, '重新進入設定頁：顯示常溫 / 低溫號段剩餘量與下一號')
    const ambient = page.locator('.el-form-item', { hasText: '常溫號段狀態' })
    const cold = page.locator('.el-form-item', { hasText: '低溫號段狀態' })
    await ambient.scrollIntoViewIfNeeded()
    await expect(ambient).toContainText(/剩餘 100 號，下一號 90000000001/)
    await expect(cold).toContainText(/剩餘 100 號，下一號 800000001/)
    console.log(`[kerrytj] 常溫：${(await ambient.innerText()).replace(/\s+/g, ' ')}`)
    console.log(`[kerrytj] 低溫：${(await cold.innerText()).replace(/\s+/g, ' ')}`)
    await page.waitForTimeout(2_500)

    // 測試連線：佔位帳密 → 失敗訊息
    await caption(page, '測試連線：佔位帳密應被嘉里大榮 Basic Auth 拒絕並顯示失敗訊息')
    const result = await clickPing(page)
    console.log(`[kerrytj] 測試連線：${result}`)
    expect(result, '應回 424（非 5xx，避免 CDN 改寫本文）').toMatch(/^HTTP 424/)
    const notice = page.locator('.el-notification, .el-message').last()
    await expect(notice).toBeVisible({ timeout: 30_000 })
    console.log(`[kerrytj] 畫面訊息：${(await notice.innerText()).replace(/\s+/g, ' ')}`)
    await page.waitForTimeout(4_000)
  } finally {
    await admin.close()
    await saveAcceptanceVideo(page, 'kerrytj_logistics-degraded')
  }
})
