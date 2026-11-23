/**
 * Global Teardown — 測試完成後清理環境
 *
 * 1. 還原 LC bypass（恢復 plugin.php 原始狀態）
 */
import { revertLcBypass } from './helpers/lc-bypass.js'
import { isLiveAcceptanceRun } from './helpers/live-env.js'

async function globalTeardown() {
  if (isLiveAcceptanceRun()) {
    return
  }

  console.log('\n--- E2E Global Teardown ---')
  try {
    revertLcBypass()
  } catch (e) {
    console.warn('[Teardown] LC bypass 還原跳過:', (e as Error).message)
  }
  console.log('[Teardown] Global Teardown 完成\n')
}

export default globalTeardown
