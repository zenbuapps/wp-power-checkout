/**
 * 物流共用 — 傳統短碼結帳選店（給實作 ISupportsCheckoutStoreSelection 的 provider）
 *
 * 與 Blocks 版（inc/assets/blocks/pc_logistics.tsx）行為一致：
 *  - 選到的運送方式 rate 若屬於共用整合的 provider，且尾碼子類型是超商 → 在該選項下方顯示「選擇門市」。
 *  - 按鈕 → POST store-selection（provider + sub_type）→ 整頁開啟門市地圖；選完由後端導回結帳頁。
 *  - 已選門市由 wp_localize_script 的 pcLogistics.selected_store 帶入。
 *
 * window.pcLogistics：store_selection_url / nonce / providers[] / selected_store / i18n
 */
;(function ($) {
	'use strict'

	var data = window.pcLogistics || {}
	var providers = Array.isArray(data.providers) ? data.providers : []
	var i18n = data.i18n || {}

	/**
	 * 解析選定的 rate（{method_id}[:{instance_id}]:{sub_type}）
	 * @param {string} rateId
	 * @return {{provider: string, subType: string}|null}
	 */
	function parseRate(rateId) {
		var parts = String(rateId || '').split(':')
		if (parts.length < 2) {
			return null
		}
		var methodId = parts[0]
		var subType = parts[parts.length - 1]
		for (var i = 0; i < providers.length; i++) {
			var p = providers[i]
			if (p.method_id === methodId && (p.cvs_sub_types || []).indexOf(subType) !== -1) {
				return { provider: p.provider, subType: subType }
			}
		}
		return null
	}

	function escapeHtml(text) {
		return $('<div/>').text(String(text || '')).html()
	}

	function render() {
		$('.pc-logistics-classic-selector').remove()

		var $checked = $(
			'input[name^="shipping_method"]:checked, input[name^="shipping_method"][type="hidden"]'
		).first()
		if (!$checked.length) {
			return
		}
		var chosen = parseRate($checked.val())
		if (!chosen) {
			return
		}

		var store = data.selected_store || {}
		var hasStore = store.store_id && store.sub_type === chosen.subType

		var html = '<div class="pc-logistics-classic-selector" style="margin-top:8px">'
		if (hasStore) {
			html +=
				'<div class="pc-logistics-selected-store" style="margin-bottom:6px">' +
				escapeHtml(i18n.pickup || '取貨門市') +
				'：<strong>' +
				escapeHtml(store.store_name) +
				'</strong><br><small>' +
				escapeHtml(store.store_addr) +
				'</small></div>'
		}
		html +=
			'<button type="button" class="button pc-logistics-select-store">' +
			escapeHtml(hasStore ? i18n.reselect : i18n.select) +
			'</button>' +
			'<div class="pc-logistics-notice" role="alert" style="color:#cc1818;margin-top:6px"></div>' +
			'</div>'

		var $li = $checked.closest('li')
		;($li.length ? $li : $checked.parent()).append(html)

		$('.pc-logistics-select-store').on('click', function () {
			var $btn = $(this)
			var original = $btn.text()
			$btn.prop('disabled', true).text(i18n.loading || '處理中…')
			$('.pc-logistics-notice').text('')

			fetch(data.store_selection_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce || '',
				},
				credentials: 'same-origin',
				body: JSON.stringify({ provider: chosen.provider, sub_type: chosen.subType }),
			})
				.then(function (resp) {
					return resp.json().then(function (json) {
						return { ok: resp.ok, json: json }
					})
				})
				.then(function (res) {
					var target = res.json && res.json.data && res.json.data.redirect_target
					if (!res.ok || !target) {
						throw new Error('no redirect target')
					}
					document.open()
					document.write(target)
					document.close()
				})
				.catch(function () {
					$btn.prop('disabled', false).text(original)
					$('.pc-logistics-notice').text(i18n.error || '')
				})
		})
	}

	$(document.body).on('updated_checkout', render)
	$(document.body).on('change', 'input[name^="shipping_method"]', render)
	$(render)
})(jQuery)
