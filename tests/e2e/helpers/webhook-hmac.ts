/**
 * Webhook HMAC 簽章工具
 *
 * 依據 specs/api.yml Webhook 章節：
 *   簽章計算：hash_hmac("sha256", "{timestamp}.{body}", signKey)
 *
 * 在 Node.js 中以 crypto 模組實作相同邏輯，
 * 供 E2E 測試正確構造 Webhook 請求的 sign header。
 */
import { createHmac } from 'crypto'

/**
 * 計算 SLP Webhook HMAC-SHA256 簽章
 *
 * @param timestamp - 毫秒級時間戳字串
 * @param body      - 原始 JSON body 字串（順序不可改動）
 * @param signKey   - Webhook 簽名密鑰（woocommerce_shopline_payment_redirect_settings.signKey）
 * @returns         - 十六進位 HMAC-SHA256 字串
 */
export function calcWebhookSign(
  timestamp: string,
  body: string,
  signKey: string,
): string {
  const message = `${timestamp}.${body}`
  return createHmac('sha256', signKey).update(message).digest('hex')
}

/**
 * 建立完整的 Webhook 請求 headers 與 body
 *
 * @param payload  - Webhook payload 物件（將被序列化為 JSON）
 * @param signKey  - 簽名密鑰
 * @param options  - 可選：覆蓋 timestamp 或強制使用錯誤簽章
 */
export function buildWebhookRequest(
  payload: Record<string, unknown>,
  signKey: string,
  options?: {
    overrideTimestamp?: string
    invalidSign?: boolean
    apiVersion?: string
  },
): {
  body: string
  headers: Record<string, string>
} {
  const timestamp = options?.overrideTimestamp ?? String(Date.now())
  const body = JSON.stringify(payload)
  const sign = options?.invalidSign
    ? 'invalid_sign_this_should_fail'
    : calcWebhookSign(timestamp, body, signKey)

  return {
    body,
    headers: {
      'Content-Type': 'application/json',
      timestamp,
      sign,
      apiVersion: options?.apiVersion ?? 'V1',
    },
  }
}

/**
 * 非常舊的時間戳（超過 5 分鐘容許範圍，非本地環境下應觸發 Invalid timestamp 錯誤）
 * year 2001 = 1_000_000_000_000 ms
 */
export const STALE_TIMESTAMP = '1000000000000'

/**
 * 5 分鐘前的時間戳（剛好超過容許範圍）
 */
export function expiredTimestamp(): string {
  return String(Date.now() - 6 * 60 * 1000)
}
