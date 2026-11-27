/**
 * 卡片齒輪 → 廠商設定頁路由解析（純邏輯，無 Vue 依賴，供 pnpm test:js 測試）
 *
 * 卡片清單由 PHP（GET /settings）產生，路由表在前端 bundle。
 * 若瀏覽器仍在跑舊 bundle（主機長效快取），新廠商卡片會找不到路由；
 * 這時不能「點了沒反應」，要告訴使用者重新整理。
 */

/** 前端 bundle 過舊時顯示的提示 */
export const STALE_BUNDLE_MESSAGE = '外掛已更新，請重新整理頁面（Ctrl+Shift+R）'

export type TSettingsRouteResult =
	| { ok: true; path: string }
	| { ok: false; message: string }

/**
 * @param id          廠商 id（卡片的 provider id）
 * @param mapper      ROUTER_MAPPER（provider id → 路由 path）
 * @param routeExists path 是否對應到已註冊的路由
 */
export function resolveSettingsRoute(
	id: string,
	mapper: Readonly<Record<string, string>>,
	routeExists: (path: string) => boolean
): TSettingsRouteResult {
	const path = Object.prototype.hasOwnProperty.call(mapper, id)
		? mapper[id]
		: ''
	if (!path || !routeExists(path)) {
		return { ok: false, message: STALE_BUNDLE_MESSAGE }
	}
	return { ok: true, path }
}
