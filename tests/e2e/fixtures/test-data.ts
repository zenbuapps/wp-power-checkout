/**
 * Test Data — power-checkout E2E 測試常數與測試資料
 */

// ─── Base URL ────────────────────────────────────────────────
export const BASE_URL = 'http://localhost:8891'

// ─── API Endpoints ───────────────────────────────────────────
export const EP = {
  // Settings (namespace: power-checkout/v1)
  SETTINGS_ALL: 'power-checkout/v1/settings',
  SETTINGS_SINGLE: (id: string) => `power-checkout/v1/settings/${id}`,
  SETTINGS_TOGGLE: (id: string) => `power-checkout/v1/settings/${id}/toggle`,
  SETTINGS_UPDATE: (id: string) => `power-checkout/v1/settings/${id}`,

  // Refund
  REFUND: 'power-checkout/v1/refund',
  REFUND_MANUAL: 'power-checkout/v1/refund/manual',

  // Invoice (namespace: power-checkout/v1/invoices)
  INVOICE_ISSUE: (orderId: number | string) =>
    `power-checkout/v1/invoices/issue/${orderId}`,
  INVOICE_CANCEL: (orderId: number | string) =>
    `power-checkout/v1/invoices/cancel/${orderId}`,

  // Webhook (namespace: power-checkout/slp)
  WEBHOOK: 'power-checkout/slp/webhook',

  // WooCommerce built-in (for creating test orders)
  WC_ORDERS: 'wc/v3/orders',
  WC_ORDER: (id: number | string) => `wc/v3/orders/${id}`,
} as const

// ─── Known Provider IDs ──────────────────────────────────────
export const PROVIDERS = {
  SLP: 'shopline_payment_redirect',
  AMEGO: 'amego',
} as const

// ─── Invoice Types ───────────────────────────────────────────
export const INVOICE_TYPE = {
  INDIVIDUAL: 'individual',
  COMPANY: 'company',
  DONATE: 'donate',
} as const

export const INDIVIDUAL_TYPE = {
  CLOUD: 'cloud',
  BARCODE: 'barcode',
  MOICA: 'moica',
  PAPER: 'paper',
} as const

// ─── Payment Methods ─────────────────────────────────────────
export const PAYMENT_METHODS = [
  'CreditCard',
  'VirtualAccount',
  'JKOPay',
  'ApplePay',
  'LinePay',
  'ChaileaseBNPL',
] as const

// ─── SLP Response Statuses ───────────────────────────────────
export const SLP_STATUS = {
  CREATED: 'CREATED',
  CUSTOMER_ACTION: 'CUSTOMER_ACTION',
  PROCESSING: 'PROCESSING',
  PENDING: 'PENDING',
  SUCCEEDED: 'SUCCEEDED',
  EXPIRED: 'EXPIRED',
  FAILED: 'FAILED',
  CANCELLED: 'CANCELLED',
} as const

// ─── Provider Mode ───────────────────────────────────────────
export const PROVIDER_MODE = {
  TEST: 'test',
  PROD: 'prod',
} as const

// ─── Edge Case Strings ───────────────────────────────────────
export const EDGE = {
  XSS_SCRIPT: '<script>alert("xss")</script>',
  XSS_IMG: '<img src=x onerror=alert(1)>',
  XSS_SVG: '<svg/onload=alert(1)>',
  SQL_INJECTION_1: "'; DROP TABLE wp_options; --",
  SQL_INJECTION_2: "1' OR '1'='1",
  SQL_INJECTION_3: "1; SELECT * FROM wp_users --",
  UNICODE_CJK: '測試中文字串',
  UNICODE_JAPANESE: 'テスト',
  UNICODE_KOREAN: '테스트',
  EMOJI: '🎉🚀💰',
  EMOJI_COMPLEX: '👨‍👩‍👧‍👦🏳️‍🌈',
  EMPTY_STRING: '',
  WHITESPACE_ONLY: '   ',
  VERY_LONG_STRING: 'A'.repeat(10_000),
  NULL_BYTE: 'test\x00null',
  SPECIAL_CHARS: '!@#$%^&*()_+-=[]{}|;:\'",.<>?/`~',
  HTML_ENTITIES: '&amp;&lt;&gt;&quot;',
  NEGATIVE_NUMBER: -1,
  ZERO: 0,
  FLOAT_NUMBER: 0.001,
  MAX_INT: 2_147_483_647,
  HUGE_NUMBER: 999_999_999_999,
} as const

// ─── Test Order Constants ────────────────────────────────────
export const TEST_ORDER = {
  TOTAL: '1000',
  STATUS_PROCESSING: 'processing',
  STATUS_PENDING: 'pending',
  NONEXISTENT_ID: 9999999,
} as const

// ─── Test IDs File ───────────────────────────────────────────
import * as path from 'path'
export const TEST_IDS_FILE = path.resolve(
  import.meta.dirname,
  '../.auth/test-ids.json',
)

export interface TestIds {
  orderId?: number
  orderIdForManualRefund?: number
  [key: string]: unknown
}

import * as fs from 'fs'

export function loadTestIds(): TestIds {
  try {
    return JSON.parse(fs.readFileSync(TEST_IDS_FILE, 'utf-8'))
  } catch {
    return {}
  }
}
