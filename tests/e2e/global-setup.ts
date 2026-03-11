/**
 * Global Setup — E2E 測試初始化
 *
 * 1. Apply LC bypass
 * 2. Login as admin, save auth state & nonce
 * 3. Create test data via WooCommerce REST API
 * 4. Save test IDs to .auth/test-ids.json
 */
import { chromium, type FullConfig } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { applyLcBypass } from './helpers/lc-bypass.js'
import { loginAsAdmin, AUTH_FILE, NONCE_FILE, getNonce } from './helpers/admin-setup.js'
import { BASE_URL, TEST_IDS_FILE, type TestIds } from './fixtures/test-data.js'

async function globalSetup(config: FullConfig) {
  console.log('\n🚀 E2E Global Setup')

  // ── 0. Ensure .auth directory exists ──
  const authDir = path.dirname(AUTH_FILE)
  if (!fs.existsSync(authDir)) {
    fs.mkdirSync(authDir, { recursive: true })
  }

  // ── 1. Apply LC bypass ──
  try {
    applyLcBypass()
  } catch (e) {
    console.warn('LC bypass 跳過:', (e as Error).message)
  }

  // ── 2. Login as admin ──
  console.log('🔑 登入管理員...')
  const nonce = await loginAsAdmin(BASE_URL)
  console.log('✅ Nonce 已取得:', nonce.slice(0, 6) + '...')

  // ── 3. Create test data via REST API ──
  console.log('📦 建立測試資料...')
  const testIds: TestIds = {}

  try {
    const browser = await chromium.launch()
    const context = await browser.newContext({ storageState: AUTH_FILE })

    const apiContext = await context.request

    // Create a test order for refund testing
    const orderRes = await apiContext.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
      },
      data: {
        status: 'processing',
        payment_method: 'shopline_payment_redirect',
        payment_method_title: 'Shopline Payment 線上付款',
        billing: {
          first_name: '[E2E]',
          last_name: 'TestUser',
          email: 'e2e-test@example.com',
          address_1: '[E2E] Test Address',
          city: 'Taipei',
          country: 'TW',
        },
        line_items: [
          {
            name: '[E2E] Test Product',
            quantity: 1,
            total: '1000',
          },
        ],
        meta_data: [
          { key: '_pc_identity', value: 'e2e_test_session_id' },
          { key: '_pc_payment_identity', value: 'e2e_trade_order_001' },
        ],
      },
    })

    if (orderRes.ok()) {
      const order = await orderRes.json()
      testIds.orderId = order.id
      console.log(`  ✅ 測試訂單已建立: #${order.id}`)
    } else {
      console.warn('  ⚠️ 建立測試訂單失敗:', orderRes.status(), await orderRes.text().catch(() => ''))
    }

    // Create a second order for manual refund testing
    const orderRes2 = await apiContext.post(`${BASE_URL}/wp-json/wc/v3/orders`, {
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
      },
      data: {
        status: 'processing',
        payment_method: 'shopline_payment_redirect',
        payment_method_title: 'Shopline Payment 線上付款',
        billing: {
          first_name: '[E2E]',
          last_name: 'ManualRefund',
          email: 'e2e-manual-refund@example.com',
          address_1: '[E2E] Test Address',
          city: 'Taipei',
          country: 'TW',
        },
        line_items: [
          {
            name: '[E2E] Manual Refund Product',
            quantity: 1,
            total: '500',
          },
        ],
      },
    })

    if (orderRes2.ok()) {
      const order2 = await orderRes2.json()
      testIds.orderIdForManualRefund = order2.id
      console.log(`  ✅ 手動退款測試訂單已建立: #${order2.id}`)
    } else {
      console.warn('  ⚠️ 建立手動退款測試訂單失敗:', orderRes2.status())
    }

    await browser.close()
  } catch (e) {
    console.warn('⚠️ 建立測試資料時出錯（非致命）:', (e as Error).message)
  }

  // ── 4. Save test IDs ──
  fs.writeFileSync(TEST_IDS_FILE, JSON.stringify(testIds, null, 2))
  console.log('💾 Test IDs 已儲存:', JSON.stringify(testIds))

  console.log('🎉 Global Setup 完成\n')
}

export default globalSetup
