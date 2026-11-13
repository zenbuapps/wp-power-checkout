<?php
/**
 * F5：全虛擬商品訂單轉處理中後自動完成（VirtualOrderAutoCompleteService）整合測試
 *
 * 對應規格：
 *   - specs/features/checkout/virtual-order-auto-complete.feature
 *   - specs/open-issue/checkout-enhancement-implementation-plan.md 第 5 階段第 2 點、R1、R2、DF4
 *
 * 設計重點（R1）：
 *   woocommerce_order_status_changed 轉 processing 時只把訂單 ID 放進佇列，
 *   請求結束（shutdown）才由 flush_queue() 重讀訂單，確認「最終狀態仍是 processing」、全虛擬、
 *   filter power_checkout_virtual_auto_complete_should_complete 回 true，才 update_status('completed', 備註)。
 *   -> 每個例子在 When 之後都要呼叫 VirtualOrderAutoCompleteService::flush_queue()（測試環境不會觸發 shutdown）。
 *
 * 執行指令（只跑本檔）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/VirtualOrderAutoCompleteTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/VirtualOrderAutoCompleteTest.php --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use J7\PowerCheckout\Domains\Checkout\Services\VirtualOrderAutoCompleteService;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\ProviderRegister as InvoiceProviderRegister;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys as InvoiceMetaKeys;

/**
 * Class VirtualOrderAutoCompleteTest
 *
 * @group checkout
 */
final class VirtualOrderAutoCompleteTest extends CheckoutTestCase {

	/** 自動完成的訂單備註（以 feature 文案為準） */
	private const AUTO_COMPLETE_NOTE = 'Power Checkout：全虛擬商品訂單，自動完成';

	/** 結帳強化設定鍵 */
	private const SETTING_KEY = 'virtual_auto_complete';

	/** 供 filter 丟例外用的 hook 名稱 */
	private const SHOULD_COMPLETE_FILTER = 'power_checkout_virtual_auto_complete_should_complete';

	/**
	 * 測試前的 API_MODE 環境變數（false 代表原本未設定）；tear_down 還原
	 *
	 * @var string|false
	 */
	private string|false $original_api_mode = false;

	/**
	 * 發票測試中攔截到的 amego f0401（開立發票）請求次數
	 *
	 * @var int
	 */
	private int $amego_issue_calls = 0;

	/** 設定（每個測試前） */
	public function set_up(): void {
		parent::set_up();

		$this->original_api_mode = \getenv( 'API_MODE' );
		$this->amego_issue_calls = 0;
	}

	/** 清理（每個測試後）：重置佇列、還原 API_MODE、卸除發票 hook */
	public function tear_down(): void {
		try {
			VirtualOrderAutoCompleteService::reset_queue();
		} finally {
			// 還原環境變數
			if ( false === $this->original_api_mode ) {
				\putenv( 'API_MODE' );
			} else {
				\putenv( 'API_MODE=' . $this->original_api_mode );
			}

			// 卸除本測試掛過的發票自動開立 hook 與 pre_http_request 攔截
			foreach ( [ 'processing', 'completed' ] as $status ) {
				\remove_action( "woocommerce_order_status_{$status}", [ InvoiceProviderRegister::class, 'auto_issue_wrapper' ] );
			}
			\remove_filter( 'pre_http_request', [ $this, 'intercept_amego_http' ], 10 );
			\remove_all_filters( self::SHOULD_COMPLETE_FILTER );
			$this->reset_amego_settings_instance();

			parent::tear_down();
		}
	}

	// ========================================================================
	// 資料提供者
	// ========================================================================

	/**
	 * Outline：經由<來源>轉為處理中的全虛擬訂單自動完成
	 *
	 * @return array<string, array{0: string, 1: string}> [原狀態, 觸發來源]
	 */
	public static function 轉處理中來源提供者(): array {
		return [
			'金流付款通知（等待付款 → 付款成功）' => [ 'pending', 'payment' ],
			'管理員手動改狀態（保留 → 處理中）'   => [ 'on-hold', 'admin' ],
		];
	}

	/**
	 * Outline：訂單<情境>時維持處理中
	 *
	 * @return array<string, array{0: string, 1: string}> [訂單型態, 觸發來源]
	 */
	public static function 不處理情境提供者(): array {
		return [
			'含實體商品'       => [ 'physical', 'payment' ],
			'商品已被刪除'     => [ 'deleted', 'gateway' ],
			'混合訂單'         => [ 'mixed', 'payment' ],
			'只有運費品項'     => [ 'no_items', 'admin' ],
		];
	}

	// ========================================================================
	// Smoke
	// ========================================================================

	/**
	 * 規則：前置（狀態）- 開關為 no 時不處理 / 場景：關閉時全虛擬訂單維持處理中
	 *
	 * @group smoke
	 */
	public function test_關閉時全虛擬訂單維持處理中(): void {
		// Given 開關為 no；訂單 1001 等待付款，品項全虛擬
		$this->set_checkout_settings( [ self::SETTING_KEY => 'no' ] );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );

		// When 第三方金流通知付款成功
		$this->trigger_processing( $order, 'payment' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 狀態應為處理中
		$this->assertSame( 'processing', $this->fresh_status( $order ) );
	}

	// ========================================================================
	// Happy
	// ========================================================================

	/**
	 * 規則：後置（狀態）- 轉為處理中且所有品項皆虛擬時，轉為已完成並加備註
	 * 場景大綱：經由<來源>轉為處理中的全虛擬訂單自動完成（含 Q7=A：管理員手動改處理中也會完成）
	 *
	 * @group happy
	 * @dataProvider 轉處理中來源提供者
	 *
	 * @param string $original_status 原狀態
	 * @param string $source          觸發來源（payment | admin）
	 */
	public function test_全虛擬訂單轉處理中後自動完成並加備註( string $original_status, string $source ): void {
		// Given 開關 yes；訂單 1001 為原狀態，品項 線上課程A x1、電子書B x2 皆虛擬
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_pending_order(
			[
				[ $this->create_product( '線上課程A', 100.0, true ), 1 ],
				[ $this->create_product( '電子書B', 50.0, true ), 2 ],
			]
		);
		if ( 'pending' !== $original_status ) {
			$order->update_status( $original_status );
			$this->assertSame( $original_status, $this->fresh_status( $order ), '前置：原狀態應已就位' );
		}

		// When 觸發轉為處理中
		$this->trigger_processing( $order, $source );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 狀態應為已完成，且備註包含自動完成文案
		$this->assertSame( 'completed', $this->fresh_status( $order ) );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), self::AUTO_COMPLETE_NOTE );
	}

	// ========================================================================
	// Edge
	// ========================================================================

	/**
	 * 規則：前置（狀態）- 含任一實體商品、無商品品項、或商品已刪除無法判定時不處理
	 * 場景大綱 / 場景：含實體商品、商品已被刪除、混合訂單、只有運費品項的訂單
	 *
	 * @group edge
	 * @dataProvider 不處理情境提供者
	 *
	 * @param string $kind   訂單型態（physical | deleted | mixed | no_items）
	 * @param string $source 觸發來源（payment | gateway | admin）
	 */
	public function test_含實體_商品已刪除_混合_無品項時維持處理中( string $kind, string $source ): void {
		// Given 開關 yes；訂單 1002/1003/1004 等待付款
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_order_by_kind( $kind );

		// When 觸發轉為處理中
		$this->trigger_processing( $order, $source );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 狀態應為處理中（不自動完成）
		$this->assertSame( 'processing', $this->fresh_status( $order ), "「{$kind}」不應自動完成" );
	}

	/**
	 * 規則：前置（狀態）- 只有「轉為處理中」觸發；轉為其他狀態不處理
	 * 場景：轉為保留不自動完成
	 *
	 * @group edge
	 */
	public function test_轉為保留不自動完成(): void {
		// Given 訂單 1005 等待付款，品項全虛擬
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );

		// When 管理員將訂單狀態改為保留
		$order->update_status( 'on-hold' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 狀態應為保留
		$this->assertSame( 'on-hold', $this->fresh_status( $order ) );
	}

	/**
	 * R1 回歸：模擬金流 payment_complete() 後再 update_status('processing')，
	 * flush 後只完成一次（不得 processing -> completed -> processing -> completed），
	 * 已完成 hook（會寄完成信）只觸發 1 次。
	 *
	 * @group edge
	 */
	public function test_金流付款後再設處理中_只完成一次(): void {
		// Given 開關 yes；訂單等待付款，品項全虛擬
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );

		$completed_before = \did_action( 'woocommerce_order_status_completed' );

		// When 金流（PayUNi / PayNow / 藍新）的 StatusManager 慣例：payment_complete() 後補 update_status('processing')
		$order->payment_complete();
		$order->update_status( 'processing' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 最終狀態已完成，且已完成 hook 只增加 1 次
		$this->assertSame( 'completed', $this->fresh_status( $order ), 'flush 後最終狀態應為已完成' );
		$this->assertSame(
			1,
			\did_action( 'woocommerce_order_status_completed' ) - $completed_before,
			'woocommerce_order_status_completed 只應觸發 1 次（否則完成信會寄兩次）'
		);
	}

	/**
	 * flush 前狀態已被金流 / 他人改成 on-hold -> 以最終狀態為準，不完成
	 *
	 * @group edge
	 */
	public function test_flush前狀態已改成保留時不完成(): void {
		// Given 開關 yes；全虛擬訂單先轉為處理中（入佇列）
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );
		$this->trigger_processing( $order, 'payment' );

		// When flush 前狀態被改為保留
		$order->update_status( 'on-hold' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 維持保留，不得被拉去完成
		$this->assertSame( 'on-hold', $this->fresh_status( $order ) );
	}

	/**
	 * flush 後佇列已清空：再次 flush 不會重複完成 / 重複觸發完成 hook
	 *
	 * @group edge
	 */
	public function test_重複flush不會重複完成(): void {
		// Given 開關 yes；全虛擬訂單轉處理中並 flush 一次
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );
		$this->trigger_processing( $order, 'payment' );
		VirtualOrderAutoCompleteService::flush_queue();
		$this->assertSame( 'completed', $this->fresh_status( $order ), '前置：第一次 flush 應已完成' );

		$completed_after_first = \did_action( 'woocommerce_order_status_completed' );

		// When 再 flush 一次
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 已完成 hook 次數不變
		$this->assertSame( $completed_after_first, \did_action( 'woocommerce_order_status_completed' ) );
		$this->assertSame( 'completed', $this->fresh_status( $order ) );
	}

	/**
	 * filter power_checkout_virtual_auto_complete_should_complete 回 false -> 不完成
	 *
	 * @group edge
	 */
	public function test_filter回false時不完成(): void {
		// Given 開關 yes；全虛擬訂單；filter 回 false
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		\add_filter( self::SHOULD_COMPLETE_FILTER, '__return_false', 10, 2 );
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );

		// When 付款成功並 flush
		$this->trigger_processing( $order, 'payment' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 維持處理中
		$this->assertSame( 'processing', $this->fresh_status( $order ) );
	}

	// ========================================================================
	// 發票只開 1 張（R2）
	// ========================================================================

	/**
	 * 規則：後置（狀態）- 自動完成不得造成電子發票重複開立
	 * 場景：發票自動開立同時設定處理中與已完成時只開一張
	 *
	 * 陷阱（R2）：
	 *  - ProviderRegister::register_provider_hooks() 在 bootstrap 依當下設定掛 hook，測試 set_up 後才寫設定，
	 *    故比照 InvoiceAutoIssueWrapperTest 以 reflection 呼叫真實掛載路徑。
	 *  - 訂單必須帶合法的 pc_issue_invoice_params，否則 dispatch 驗證回 VALIDATION、0 次 API，測試會假綠。
	 *  - amego 在 API_MODE=mock 時不走 HTTP（無法計數）-> 本測試暫時切 sandbox，改用 pre_http_request 攔截並回成功。
	 *  - 同時斷言最終狀態為已完成：少了這個斷言，功能未實作時「處理中只開 1 張」會假綠。
	 *
	 * @group happy
	 */
	public function test_發票自動開立設定處理中與已完成時只開一張(): void {
		// Given 開關 yes；amego 啟用，自動開立狀態為處理中 + 已完成
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		\putenv( 'API_MODE=sandbox' );
		$this->reset_amego_settings_instance();
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode'                      => 'test',
				'auto_issue_order_statuses' => [ 'wc-processing', 'wc-completed' ],
			]
		);
		$this->register_amego_auto_issue_hooks();
		\add_filter( 'pre_http_request', [ $this, 'intercept_amego_http' ], 10, 3 );

		// And 訂單 1006 等待付款，品項全虛擬，且帶合法發票參數
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();
		( new InvoiceMetaKeys( $order ) )->update_issue_params(
			[
				'provider'    => AmegoProvider::ID,
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);

		// When 第三方金流通知付款成功（processing hook 開立一次）+ 請求結束 flush（completed hook 再觸發一次）
		$this->trigger_processing( $order, 'payment' );
		VirtualOrderAutoCompleteService::flush_queue();

		// Then 最終狀態已完成（避免功能未實作時假綠），且發票只開 1 張
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'completed', $fresh->get_status(), '最終狀態應為已完成' );
		$this->assertNotEmpty( ( new InvoiceMetaKeys( $fresh ) )->get_issued_data(), '前置：發票應已開立' );
		$this->assertSame( 1, $this->amego_issue_calls, 'amego f0401 開立發票 API 只應被呼叫 1 次' );
	}

	/**
	 * pre_http_request 攔截器：計算 amego f0401 呼叫次數，並回傳成功回應
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt 短路值
	 * @param array<string, mixed>                 $args    請求參數
	 * @param string                               $url     請求 URL
	 * @return array<string, mixed>|false|\WP_Error 偽造回應；非 amego 請求不攔截
	 */
	public function intercept_amego_http( $preempt, $args, $url ) {
		if ( ! \str_contains( (string) $url, 'invoice-api.amego.tw' ) ) {
			return $preempt;
		}

		if ( \str_contains( (string) $url, '/json/f0401' ) ) {
			++$this->amego_issue_calls;
		}

		return [
			'headers'  => [],
			'body'     => (string) \wp_json_encode(
				[
					'code'           => 0,
					'msg'            => 'OK',
					'invoice_number' => 'AG00000001',
					'invoice_time'   => \time(),
					'random_number'  => '1234',
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	// ========================================================================
	// Error（never-throw）
	// ========================================================================

	/**
	 * 規則：後置（狀態）- 自動完成過程任何例外皆攔截並記錄 log，不中斷付款回呼（never-throw）
	 * 場景：自動完成時拋出例外，付款回呼仍成功
	 *
	 * @group error
	 */
	public function test_自動完成拋例外時不外拋且狀態維持處理中並記錄log(): void {
		// Given 開關 yes；訂單 1007 等待付款，品項全虛擬；自動完成判斷時會拋出例外
		$this->set_checkout_settings( [ self::SETTING_KEY => 'yes' ] );
		$this->capture_logs();
		\add_filter(
			self::SHOULD_COMPLETE_FILTER,
			static function () {
				throw new \RuntimeException( '測試用：自動完成判斷丟出例外' );
			},
			10,
			2
		);
		$order = $this->create_pending_order( [ [ $this->create_product( '線上課程A', 100.0, true ), 1 ] ] );

		// When 第三方金流通知付款成功，請求結束時 flush
		$thrown   = null;
		$callback = false;
		try {
			$callback = $order->payment_complete();
			VirtualOrderAutoCompleteService::flush_queue();
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		// Then 付款回呼回應成功、flush 不拋出
		$this->assertNull( $thrown, 'flush_queue 絕不可外拋例外（never-throw）：' . ( $thrown?->getMessage() ?? '' ) );
		$this->assertTrue( $callback, '付款回呼（payment_complete）應成功' );

		// And DB 狀態仍為處理中
		$this->assertSame( 'processing', $this->fresh_status( $order ) );

		// And 錯誤 log（level=error）應包含訂單編號
		$this->assert_log_contains( '#' . $order->get_id(), 'error' );
	}

	// ========================================================================
	// Helper
	// ========================================================================

	/**
	 * 建立「等待付款」訂單，並加入指定商品品項
	 *
	 * @param array<int, array{0: \WC_Product, 1: int}> $lines 每列 [商品, 數量]
	 * @return \WC_Order 已儲存、狀態為 pending 的訂單
	 */
	private function create_pending_order( array $lines ): \WC_Order {
		$order = \wc_create_order();
		foreach ( $lines as [ $product, $qty ] ) {
			$order->add_product( $product, $qty );
		}
		$order->calculate_totals();
		$order->save();

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'pending', $fresh->get_status(), '前置：訂單應為等待付款' );

		return $fresh;
	}

	/**
	 * 依型態建立「不應自動完成」的訂單
	 *
	 * @param string $kind physical | deleted | mixed | no_items
	 * @return \WC_Order 等待付款訂單
	 */
	private function create_order_by_kind( string $kind ): \WC_Order {
		switch ( $kind ) {
			case 'physical':
				return $this->create_pending_order( [ [ $this->create_product( '帆布袋C', 100.0, false ), 1 ] ] );

			case 'deleted':
				$product = $this->create_product( '（已刪除商品）', 100.0, true );
				$order   = $this->create_pending_order( [ [ $product, 1 ] ] );
				\wp_delete_post( $product->get_id(), true );
				return $order;

			case 'mixed':
				return $this->create_pending_order(
					[
						[ $this->create_product( '線上課程A', 100.0, true ), 1 ],
						[ $this->create_product( '帆布袋C', 100.0, false ), 1 ],
					]
				);

			case 'no_items':
				$order    = \wc_create_order();
				$shipping = new \WC_Order_Item_Shipping();
				$shipping->set_method_title( '運費' );
				$shipping->set_total( '60' );
				$order->add_item( $shipping );
				$order->calculate_totals();
				$order->save();
				return \wc_get_order( $order->get_id() );
		}

		throw new \InvalidArgumentException( "未知的訂單型態：{$kind}" );
	}

	/**
	 * 觸發「轉為處理中」
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $source payment：第三方金流付款成功（payment_complete）；
	 *                          gateway：金流 StatusManager 慣例（payment_complete 後再補 update_status('processing')）；
	 *                          admin：管理員手動改處理中（update_status）
	 */
	private function trigger_processing( \WC_Order $order, string $source ): void {
		if ( 'payment' === $source ) {
			$order->payment_complete();
			return;
		}

		if ( 'gateway' === $source ) {
			// 商品已刪除時 WC 核心的 needs_processing() 會判成「不需處理」，payment_complete() 直接進 completed；
			// PayUNi / PayNow / 藍新 都會再補 update_status('processing')，所以最終仍是處理中（本功能要擋的就是這種）。
			$order->payment_complete();
			$order->update_status( 'processing' );
			return;
		}

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
		$order->update_status( 'processing' );
	}

	/**
	 * 從 DB 重讀訂單狀態（不信任傳入物件的記憶體狀態）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 去掉 wc- 前綴的狀態
	 */
	private function fresh_status( \WC_Order $order ): string {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh, "找不到訂單 #{$order->get_id()}" );

		return $fresh->get_status();
	}

	/** 重置 AmegoSettingsDTO 的單例（設定改變後 wrapper 才讀得到新的 auto_issue_order_statuses） */
	private function reset_amego_settings_instance(): void {
		$prop = ( new \ReflectionClass( AmegoSettingsDTO::class ) )->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	/** 以 reflection 呼叫 private static register_provider_hooks，掛上真實的自動開立 wrapper */
	private function register_amego_auto_issue_hooks(): void {
		$method = ( new \ReflectionClass( InvoiceProviderRegister::class ) )->getMethod( 'register_provider_hooks' );
		$method->invoke( null, AmegoProvider::ID, AmegoProvider::class );

		$this->assertNotFalse(
			\has_action( 'woocommerce_order_status_processing', [ InvoiceProviderRegister::class, 'auto_issue_wrapper' ] ),
			'前置：處理中的自動開立 hook 應已掛上'
		);
		$this->assertNotFalse(
			\has_action( 'woocommerce_order_status_completed', [ InvoiceProviderRegister::class, 'auto_issue_wrapper' ] ),
			'前置：已完成的自動開立 hook 應已掛上'
		);
	}
}
