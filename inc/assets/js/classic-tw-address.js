/**
 * 結帳強化 F2 — 傳統結帳（classic checkout）台灣鄉鎮市區 / 郵遞區號連動（jQuery，免 build）
 *
 * 行為（billing / shipping 兩組各自獨立）：
 *   1. 國家為 TW 時，把 #{prefix}_city 文字輸入換成 <select id="{prefix}_city">，選項為目前縣市（#{prefix}_state）的鄉鎮
 *   2. 選擇鄉鎮 → 自動帶入 #{prefix}_postcode
 *   3. 切換縣市 → 重建鄉鎮選項並清空郵遞區號
 *   4. 國家非 TW → 還原為 <input type="text" id="{prefix}_city">
 *   資料缺失或縣市不在對照表 → 保留原文字輸入，不阻擋結帳
 *
 * 後端資料來源：window.pc_tw_address_data（wp_localize_script）
 *   - counties：{ 縣市: { 鄉鎮市區: 郵遞區號 } }
 *   - i18n_select_county / i18n_select_city：提示文字
 *
 * @see inc/classes/Domains/Checkout/Services/TwAddressService.php
 */
;(function ($) {
	'use strict'

	if (!$) {
		return
	}

	var data = window.pc_tw_address_data || {}
	var counties = data.counties && typeof data.counties === 'object' ? data.counties : null
	var PREFIXES = ['billing', 'shipping']

	if (!counties) {
		return
	}

	/**
	 * 取得某組欄位目前的國家
	 * @param {string} prefix billing / shipping
	 * @return {string} 國家代碼
	 */
	function getCountry(prefix) {
		var $country = $('#' + prefix + '_country')
		return $country.length ? String($country.val() || '') : ''
	}

	/**
	 * 取得目前縣市對應的鄉鎮對照
	 * @param {string} prefix
	 * @return {Object|null} { 鄉鎮: 郵遞區號 }，查無回 null
	 */
	function getDistricts(prefix) {
		var county = String($('#' + prefix + '_state').val() || '')
		return Object.prototype.hasOwnProperty.call(counties, county) ? counties[county] : null
	}

	/**
	 * 複製 city 欄位共用屬性（name / class / autocomplete / placeholder 以外的 aria 屬性）
	 * @param {jQuery} $from 原欄位
	 * @param {jQuery} $to 新欄位
	 */
	function copyAttrs($from, $to) {
		var attrs = ['name', 'class', 'autocomplete', 'aria-required', 'data-placeholder']
		for (var i = 0; i < attrs.length; i++) {
			var value = $from.attr(attrs[i])
			if (typeof value !== 'undefined') {
				$to.attr(attrs[i], value)
			}
		}
	}

	/**
	 * 把 city 換成鄉鎮下拉（已是下拉則只重建選項）
	 * @param {string} prefix
	 */
	function renderCitySelect(prefix) {
		var $city = $('#' + prefix + '_city')
		if (!$city.length) {
			return
		}

		var districts = getDistricts(prefix)
		var current = String($city.val() || '')
		var $select = $city

		if (!$city.is('select')) {
			$select = $('<select></select>').attr('id', prefix + '_city')
			copyAttrs($city, $select)
			$select.removeClass('input-text')
			$city.replaceWith($select)
		}

		$select.empty()
		$select.append(
			$('<option></option>')
				.attr('value', '')
				.text(districts ? data.i18n_select_city || '' : data.i18n_select_county || '')
		)

		if (districts) {
			$.each(districts, function (name, zip) {
				$select.append($('<option></option>').attr('value', name).attr('data-zip', zip).text(name))
			})
		}

		if (districts && Object.prototype.hasOwnProperty.call(districts, current)) {
			$select.val(current)
		} else {
			$select.val('')
		}
	}

	/**
	 * 還原 city 為文字輸入
	 * @param {string} prefix
	 */
	function renderCityInput(prefix) {
		var $city = $('#' + prefix + '_city')
		if (!$city.length || !$city.is('select')) {
			return
		}

		var $input = $('<input type="text" />').attr('id', prefix + '_city')
		copyAttrs($city, $input)
		$input.addClass('input-text').val('')
		$city.replaceWith($input)
	}

	/**
	 * 依國家同步某組欄位
	 * @param {string} prefix
	 */
	function sync(prefix) {
		if (getCountry(prefix) === 'TW') {
			renderCitySelect(prefix)
		} else {
			renderCityInput(prefix)
		}
	}

	/**
	 * 依目前鄉鎮帶入郵遞區號
	 * @param {string} prefix
	 */
	function fillPostcode(prefix) {
		var $city = $('#' + prefix + '_city')
		var $postcode = $('#' + prefix + '_postcode')
		if (!$city.is('select') || !$postcode.length) {
			return
		}

		var zip = $city.find('option:selected').attr('data-zip') || ''
		$postcode.val(zip).trigger('change')
	}

	$(function () {
		var $body = $(document.body)

		$.each(PREFIXES, function (_, prefix) {
			sync(prefix)

			// 縣市（state）可能被 WC 重建，改用委派事件
			$(document).on('change', '#' + prefix + '_state', function () {
				if (getCountry(prefix) !== 'TW') {
					return
				}
				renderCitySelect(prefix)
				$('#' + prefix + '_postcode').val('').trigger('change')
			})

			$(document).on('change', '#' + prefix + '_city', function () {
				fillPostcode(prefix)
			})
		})

		// WC 切換國家後會重建 state 欄位並觸發此事件
		$body.on('country_to_state_changed', function () {
			$.each(PREFIXES, function (_, prefix) {
				sync(prefix)
			})
		})
	})
})(window.jQuery)
