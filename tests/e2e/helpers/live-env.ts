/**
 * Live Env — 錄影驗收（04-live）憑證與站點設定來源
 *
 * 規則（藍圖 P8 §7.1 / §7.2）：
 * - 憑證只從環境變數讀；另可用 LIVE_ENV_FILES 指定本機未追蹤的 env 檔（以 `;` 分隔），
 *   由本 helper 解析 KEY=VALUE（不經 shell source，避免密碼含 `$` / `!` 被展開）。
 *   repo 根目錄 `.env`（已 gitignore）預設會被讀取。
 * - 已存在於 process.env 的值優先，不會被檔案覆蓋。
 * - 本檔與 spec 皆不得寫入任何憑證值；缺值時 test.skip 並列出缺少的鍵名。
 * - 順豐公開沙箱憑證：官方「測試環境授權信息」公開文件（skill sf-express onboarding-and-faq.md §8），
 *   執行時從該文件解析，不複製進 repo 其他位置。
 */
import { test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

/** 外掛根目錄（tests/e2e/helpers → ../../..） */
export const PLUGIN_ROOT = path.resolve(import.meta.dirname, '../../..')

/** 驗收影片整理目錄（已 gitignore） */
export const ACCEPTANCE_VIDEO_DIR = path.resolve(import.meta.dirname, '../acceptance-videos')

/** 本次執行是否為 live-acceptance project（globalSetup / globalTeardown 據此略過） */
export function isLiveAcceptanceRun(): boolean {
  if (process.env.PC_LIVE_ACCEPTANCE === '1') {
    return true
  }
  return process.argv.some((arg) => arg === 'live-acceptance' || arg === '--project=live-acceptance')
}

let loaded = false

/** 解析 KEY=VALUE 檔（忽略註解、空行；去除成對引號），不輸出任何值 */
function parseEnvFile(file: string): Record<string, string> {
  const result: Record<string, string> = {}
  if (!fs.existsSync(file)) {
    return result
  }
  for (const raw of fs.readFileSync(file, 'utf-8').split(/\r?\n/)) {
    const line = raw.trim()
    if (!line || line.startsWith('#')) {
      continue
    }
    const match = /^(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/.exec(line)
    if (!match) {
      continue
    }
    let value = match[2]
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1)
    }
    result[match[1]] = value
  }
  return result
}

/** 載入 repo `.env` 與 LIVE_ENV_FILES（只補 process.env 沒有的鍵） */
function ensureLoaded(): void {
  if (loaded) {
    return
  }
  loaded = true
  const files = [path.join(PLUGIN_ROOT, '.env')]
  for (const extra of (process.env.LIVE_ENV_FILES || '').split(';')) {
    if (extra.trim()) {
      files.push(extra.trim())
    }
  }
  for (const file of files) {
    if (/\.env\.production$/i.test(file)) {
      continue // 禁用正式環境憑證檔
    }
    for (const [key, value] of Object.entries(parseEnvFile(file))) {
      if (process.env[key] === undefined || process.env[key] === '') {
        process.env[key] = value
      }
    }
  }
}

/**
 * 取第一個有值的環境變數
 *
 * @param names 鍵名（依序 fallback）
 * @returns 值；皆無則空字串
 */
export function liveEnv(...names: string[]): string {
  ensureLoaded()
  for (const name of names) {
    const value = process.env[name]
    if (value !== undefined && value !== '' && !/^<.*>$/.test(value)) {
      return value
    }
  }
  return ''
}

/**
 * 取得必要憑證；任一缺少（或為 `<...>` 佔位）→ test.skip 並列出鍵名
 *
 * @param spec 鍵 → 候選環境變數名稱
 * @returns 鍵 → 值
 */
export function requireLiveEnv<K extends string>(spec: Record<K, string[]>): Record<K, string> {
  const result = {} as Record<K, string>
  const missing: string[] = []
  for (const key of Object.keys(spec) as K[]) {
    const value = liveEnv(...spec[key])
    if (!value) {
      missing.push(spec[key][0])
    }
    result[key] = value
  }
  test.skip(missing.length > 0, `缺 ${missing.join(', ')} 憑證`)
  return result
}

/** 站點 base URL（預設 tunnel；離線時可設 E2E_BASE_URL） */
export const LIVE_BASE_URL = (process.env.E2E_BASE_URL || 'https://local-turbo.powerhouse.tw').replace(/\/$/, '')

/** 管理員帳密（E2E_ADMIN_* 優先，其次 repo .env 的 TEST_*） */
export function adminCredentials(): { user: string, pass: string } {
  return requireLiveEnv({
    user: ['E2E_ADMIN_USER', 'TEST_USERNAME'],
    pass: ['E2E_ADMIN_PASS', 'TEST_PASSWORD'],
  })
}

/**
 * 順豐公開沙箱（SIT）憑證：環境變數優先，否則解析官方公開文件 §8 表格
 *
 * @returns appKey / appSecret / encodingAesKey / customerCode / monthCard（缺任一 → skip）
 */
export function sfPublicSandboxCredentials(): Record<'app_key' | 'app_secret' | 'encoding_aes_key' | 'customer_code' | 'month_card', string> {
  const doc = path.join(PLUGIN_ROOT, '.claude/skills/sf-express/references/onboarding-and-faq.md')
  const section = fs.existsSync(doc) ? (fs.readFileSync(doc, 'utf-8').split(/^## 8\./m)[1] || '').split(/^## 9\./m)[0] : ''
  const cell = (label: RegExp): string => {
    for (const line of section.split(/\r?\n/)) {
      const cols = line.split('|').map((c) => c.trim())
      if (cols.length >= 3 && label.test(cols[1])) {
        return cols[2]
      }
    }
    return ''
  }
  const pick = (envName: string, label: RegExp): string => liveEnv(envName) || cell(label)
  const creds = {
    app_key: pick('SF_SANDBOX_APP_KEY', /^appKey$/),
    app_secret: pick('SF_SANDBOX_APP_SECRET', /^appSecret$/),
    encoding_aes_key: pick('SF_SANDBOX_ENCODING_AES_KEY', /^encodingAesKey$/),
    customer_code: pick('SF_SANDBOX_CUSTOMER_CODE', /國際件API\*\*CustomerCode/),
    month_card: pick('SF_SANDBOX_MONTH_CARD', /國際件API\*\*月結卡號/),
  }
  const missing = Object.entries(creds).filter(([, v]) => !v).map(([k]) => k)
  test.skip(missing.length > 0, `缺順豐公開沙箱憑證：${missing.join(', ')}`)
  return creds
}
