/**
 * License Check Bypass — 繞過 Powerhouse 授權檢查（E2E 測試專用）
 *
 * 機制：在 plugin.php 的 init() 陣列中，於 'callback' => [ Bootstrap::class, 'instance' ]
 * 之後注入 'lc' => false 來覆寫硬編的授權碼。注入前會備份原檔，revert 時還原。
 *
 * regex 設計成容忍 'callback' / '=>' / '[' / ',' 之間任意空白，避免 plugin.php
 * 為了對齊欄位而插入的多空格導致 needle 不 match。
 */
import * as fs from 'fs'
import * as path from 'path'

const PLUGIN_FILE = path.resolve(import.meta.dirname, '../../../plugin.php')
const BACKUP_FILE = PLUGIN_FILE + '.e2e-backup'
const MARKER = '/* E2E-LC-BYPASS */'

// 容忍多空格：'callback' <ws> => <ws> [ <ws> Bootstrap::class <ws> , <ws> 'instance' <ws> ] <ws> ,
const CALLBACK_PATTERN =
  /('callback'\s*=>\s*\[\s*Bootstrap::class\s*,\s*'instance'\s*\]\s*,)/

export function applyLcBypass(): void {
  const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')
  if (content.includes(MARKER)) {
    console.log('[lc-bypass] 已套用過，跳過')
    return
  }

  if (!CALLBACK_PATTERN.test(content)) {
    console.warn(
      '[lc-bypass] ⚠️ 找不到 callback line，regex 不 match，跳過注入。' +
      '請檢查 plugin.php 是否仍包含 callback => [ Bootstrap::class, \'instance\' ],',
    )
    return
  }

  fs.copyFileSync(PLUGIN_FILE, BACKUP_FILE)

  const patched = content.replace(
    CALLBACK_PATTERN,
    `$1\n\t\t\t\t'lc'               => false, ${MARKER}`,
  )

  if (patched === content) {
    console.warn('[lc-bypass] ⚠️ replace 後內容無變化，跳過')
    return
  }

  fs.writeFileSync(PLUGIN_FILE, patched)
  console.log('✅ [lc-bypass] 已注入 \'lc\' => false')
}

export function revertLcBypass(): void {
  if (fs.existsSync(BACKUP_FILE)) {
    fs.copyFileSync(BACKUP_FILE, PLUGIN_FILE)
    fs.unlinkSync(BACKUP_FILE)
    console.log('✅ [lc-bypass] 已還原 plugin.php')
  }
}
