/**
 * Power Checkout — 新版兩欄式結帳頁（傳統結帳）
 *
 * 只「搬移」WooCommerce 既有節點，不複製、不改 name、不攔截送出：
 * - 左欄 .pc-tc-main：#customer_details（含前後 hook 輸出）+ #payment（付款方式）
 * - 右欄 .pc-tc-aside：#order_review_heading + #order_review（訂單摘要表格）+ 下單按鈕（#payment 內的 .place-order）
 *
 * update_order_review 以 `$( selector ).replaceWith( html )` 原地替換 fragment：
 * - .woocommerce-checkout-review-order-table 在右欄原地替換
 * - .woocommerce-checkout-payment（#payment）在左欄原地替換；新的 #payment 會帶回新的 .place-order，
 *   於 updated_checkout（與替換同一個同步 callback）把它搬回右欄，舊的移除，DOM 中永遠只有一份
 *
 * 找不到預期結構（佈景主題改寫模板等）→ 放棄，維持舊版版面（CSS 全掛在 .pc-tc-layout 下，不會套用）。
 */
;(function ($) {
	'use strict'

	if (!$) {
		return
	}

	var PENDING_CLASS = 'pc-tc-pending'
	var ACTIVE_CLASS = 'pc-tc-active'
	var BAIL_CONTAINERS = '.elementor-widget-woocommerce-checkout-page, .fc-wrapper, .fc-checkout-steps, #cfw, .cfw-container'
	var BAIL_BODY_CLASSES = '.has-fluid-checkout, .checkout-wc, .cfw-grid'
	var STICKY_CLASS = 'pc-tc-sticky'
	var STICKY_GAP = 48

	var $shell = null
	var $layout = null
	// 兩欄門檻：依結帳區塊本身寬度（佈景有側邊欄時內容區可能只有 600px），與 CSS @container pc-tc 一致
	var TWO_COLUMN_MIN_WIDTH = 860
	var $aside = null
	var $slot = null

	function reveal() {
		$(document.body).removeClass(PENDING_CLASS)
	}

	/**
	 * 把 #payment 內的 .place-order 搬到右欄（只保留最新一份）
	 */
	function relocatePlaceOrder() {
		if (!$slot) {
			return
		}
		var $fresh = $layout.find('.pc-tc-main #payment .place-order').first()
		if (!$fresh.length) {
			return
		}
		$slot.children('.place-order').remove()
		$slot.append($fresh)
	}

	function isDesktop() {
		return !!$shell && $shell.innerWidth() >= TWO_COLUMN_MIN_WIDTH
	}

	/**
	 * 桌機：下單按鈕在右欄摘要卡底部；手機（單欄）：搬到 .pc-tc-layout 最後（付款方式之後）
	 */
	function placeSlot() {
		if (!$slot || !$aside) {
			return
		}
		if (isDesktop()) {
			var $inner = $aside.children('.pc-tc-aside-inner')
			if (!$slot.parent().is($inner)) {
				$inner.append($slot)
			}
		} else if (!$slot.parent().is($layout)) {
			$layout.append($slot)
		}
	}

	/**
	 * 右欄比視窗矮才 sticky（太高時 sticky 會讓下單按鈕卡在視窗外）
	 */
	function updateSticky() {
		if (!$aside) {
			return
		}
		var inner = $aside.children('.pc-tc-aside-inner').get(0)
		if (!inner) {
			return
		}
		$aside.removeClass(STICKY_CLASS)
		var fits = inner.offsetHeight + STICKY_GAP < window.innerHeight
		$aside.toggleClass(STICKY_CLASS, fits && isDesktop())
	}

	function build() {
		var $form = $('form.checkout.woocommerce-checkout').first()
		if (!$form.length || $form.find('.pc-tc-layout').length) {
			return false
		}

		// 其他結帳外掛 / 頁面建構器已自行排版的結帳頁：不介入，維持它們的版面
		// - Elementor Pro「結帳」widget（.elementor-widget-woocommerce-checkout-page）
		// - Fluid Checkout（.fc-wrapper / body.has-fluid-checkout）、CheckoutWC（#cfw / body.checkout-wc）
		// - woomp「一頁式 / 兩頁式結帳」（woomp-checkout.js 自行搬移 #payment / #place_order；PHP 端已不載入本腳本，這裡再防一次）
		var woompParams = window.woomp_checkout_params
		if (woompParams && woompParams.enableWoomp === 'yes') {
			return false
		}
		if (
			$form.closest(BAIL_CONTAINERS).length ||
			$form.find(BAIL_CONTAINERS).length ||
			$(document.body).is(BAIL_BODY_CLASSES)
		) {
			return false
		}

		var $details = $form.find('#customer_details').first()
		var $review = $form.find('#order_review').first()
		var $payment = $review.find('#payment').first()

		// 必要結構：三者都在 form.checkout 內（#payment 在 #order_review 內 = 標準 review-order 結構）
		if (!$details.length || !$review.length || !$payment.length) {
			return false
		}

		// 最近共同祖先：標準模板為 form 本身；佈景主題可能各自再包一層（例：Blocksy 的 .ct-customer-details / .ct-order-review）
		var $parent = $details.parent()
		while ($parent.length && !$parent.is($form) && !$.contains($parent.get(0), $review.get(0))) {
			$parent = $parent.parent()
		}
		if (!$parent.length || !$parent.closest('form').is($form) || !$.contains($parent.get(0), $review.get(0))) {
			return false
		}

		var $siblings = $parent.children()
		var branchOf = function ($el) {
			return $siblings.filter(function () {
				return this === $el.get(0) || $.contains(this, $el.get(0))
			}).first()
		}
		var $detailsBranch = branchOf($details)
		var $reviewBranch = branchOf($review)
		if (!$detailsBranch.length || !$reviewBranch.length || $detailsBranch.is($reviewBranch)) {
			return false
		}
		var reviewIndex = $siblings.index($reviewBranch)
		if ($siblings.index($detailsBranch) > reviewIndex) {
			return false
		}
		var $heading = $parent.children('#order_review_heading')
		var headingIndex = $heading.length ? $siblings.index($heading) : reviewIndex
		var splitIndex = Math.min(reviewIndex, headingIndex)

		var mainNodes = []
		var asideNodes = []
		$siblings.each(function (index) {
			var $node = $(this)
			// 錯誤訊息群組留在原位（WC 會 prepend 到 form 頂端）
			if ($node.hasClass('woocommerce-NoticeGroup')) {
				return
			}
			// 隱藏欄位、script 等非視覺節點留在原位
			if ($node.is('input[type="hidden"], script, style, template, noscript')) {
				return
			}
			if (index < splitIndex) {
				mainNodes.push(this)
			} else {
				asideNodes.push(this)
			}
		})

		$shell = $('<div class="pc-tc-shell"></div>')
		$layout = $('<div class="pc-tc-layout"></div>')
		var $main = $('<div class="pc-tc-main"></div>')
		$aside = $('<div class="pc-tc-aside"></div>')
		var $inner = $('<div class="pc-tc-aside-inner"></div>')
		$slot = $('<div class="pc-tc-place-order-slot"></div>')

		$shell.insertBefore(mainNodes.length ? mainNodes[0] : $reviewBranch)
		$shell.append($layout)
		$main.append(mainNodes)
		var i18n = window.pcTwoColumn || {}
		var $paymentSection = $('<div class="pc-tc-payment-section"></div>')
		if (i18n.i18n_payment) {
			$paymentSection.append($('<h3 class="pc-tc-section-title"></h3>').text(i18n.i18n_payment))
		}
		$main.append($paymentSection.append($payment))
		$inner.append(asideNodes).append($slot)
		$aside.append($inner)
		$layout.append($main).append($aside)
		$form.addClass('pc-tc-form')
		$(document.body).addClass(ACTIVE_CLASS)

		// 佈景把 form 設成收縮包覆的 display（例：Twenty Twenty-One 的 display:table 清除浮動）時，
		// 寬度會由內容決定；.pc-tc-shell 是 inline-size container（寬度不受內容影響）→ 整個版面寬度歸零。改為 block。
		var formDisplay = window.getComputedStyle ? window.getComputedStyle($form.get(0)).display : 'block'
		if (/^(table|inline|inline-block|inline-table|inline-flex|inline-grid|table-cell)$/.test(formDisplay)) {
			$form.addClass('pc-tc-form--block')
		}

		// 「建立帳號？」（.woocommerce-account-fields）在 .col-1 內、帳單欄位之後：整個 .col-1 當帳單卡片
		$details.children('.col-1').each(function () {
			var $col = $(this)
			if ($col.children('.woocommerce-billing-fields').length) {
				$col.addClass('pc-tc-billing-card')
			}
		})

		relocatePlaceOrder()
		return true
	}

	function init() {
		var built = false
		try {
			built = build()
		} catch (e) {
			built = false
			if (window.console && window.console.error) {
				window.console.error('[power-checkout] two-column layout skipped', e)
			}
		}
		reveal()

		if (!built) {
			return
		}

		$(document.body).on('updated_checkout', function () {
			relocatePlaceOrder()
			updateSticky()
		})
		$(window).on('resize', function () {
			placeSlot()
			updateSticky()
		})
		placeSlot()
		updateSticky()
	}

	$(init)
})(window.jQuery)
