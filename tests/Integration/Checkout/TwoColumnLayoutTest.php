<?php
/**
 * 新版兩欄式結帳頁（checkout_two_column）整合測試
 *
 * 規格：
 * - 預設 yes：未儲存過設定（含既有用戶升級、option 缺此鍵）時套用
 * - 僅傳統結帳頁（非 order-pay / order-received 端點）載入 CSS / JS 與 body class；區塊結帳不受影響
 * - 關閉時不載入任何資源、不加 body class、review-order 不出現縮圖（= 舊版）
 * - 縮圖 filter 只掛在 review-order 的 before/after_cart_contents 之間
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Services\TwoColumnLayoutService;

/**
 * @group checkout
 */
final class TwoColumnLayoutTest extends CheckoutTestCase {

	private const HANDLE = 'pc-classic-two-column';

	public function tear_down(): void {
		TwoColumnLayoutService::stop_thumbnail();
		unset( $GLOBALS['wp']->query_vars['order-received'] );
		parent::tear_down();
	}

	// ========== Helper ==========

	/** WC CartCheckoutUtils 以靜態屬性快取「是否為結帳頁」，同一 PHP 程序內換頁需重置 */
	private function reset_page_type_cache(): void {
		$class = \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class;
		foreach ( [ 'is_checkout_page', 'is_cart_page' ] as $prop ) {
			if ( \property_exists( $class, $prop ) ) {
				$ref = new \ReflectionProperty( $class, $prop );
				$ref->setValue( null, null );
			}
		}
	}

	private function go_to_checkout_page( string $content ): void {
		$page_id = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => '結帳',
				'post_content' => $content,
			]
		);
		\update_option( 'woocommerce_checkout_page_id', $page_id );

		$this->go_to( (string) \get_permalink( $page_id ) );

		$GLOBALS['post'] = \get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 測試模擬目前頁面
		\setup_postdata( $GLOBALS['post'] );
		$this->reset_page_type_cache();
	}

	/** 觸發 wp_enqueue_scripts（丟棄 powerhouse 的已知 in-footer 通知，見 CheckoutAddressFieldsTest） */
	private function run_wp_enqueue_scripts(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- 測試重置前台資源佇列
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		\do_action( 'wp_enqueue_scripts' );
		$message = $this->caught_doing_it_wrong['wp_enqueue_script'] ?? '';
		if ( \is_string( $message ) && \str_contains( $message, 'in-footer' ) ) {
			unset( $this->caught_doing_it_wrong['wp_enqueue_script'] );
		}
	}

	private function assets_enqueued(): bool {
		return \wp_script_is( self::HANDLE, 'enqueued' ) && \wp_style_is( self::HANDLE, 'enqueued' );
	}

	/** @return array<string, mixed> 購物車項目 */
	private function cart_item( float $quantity = 2 ): array {
		$product = $this->create_product( '兩欄測試商品', 100, false );
		return [
			'data'     => $product,
			'quantity' => $quantity,
		];
	}

	// ========== 冒煙 ==========

	/**
	 * @group smoke
	 */
	public function test_冒煙_預設開啟且option缺鍵時視為開啟(): void {
		$this->assertSame( 'yes', CheckoutEnhancementSettingsDTO::DEFAULTS['checkout_two_column'] );
		$this->assertTrue( CheckoutEnhancementSettingsDTO::is_on( 'checkout_two_column' ), 'option 不存在時應為開啟' );

		// 既有用戶：已儲存過其他鍵、沒有 checkout_two_column → 升級後仍為開啟
		\update_option( self::SETTINGS_OPTION, [ 'tw_address' => 'yes' ] );
		$this->assertTrue( CheckoutEnhancementSettingsDTO::is_on( 'checkout_two_column' ) );
	}

	/**
	 * @group smoke
	 */
	public function test_冒煙_開啟時傳統結帳頁載入資源並加上body_class(): void {
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$this->run_wp_enqueue_scripts();

		$this->assertTrue( $this->assets_enqueued(), '傳統結帳頁應 enqueue 兩欄 CSS / JS' );
		$this->assertContains( TwoColumnLayoutService::BODY_CLASS, \get_body_class() );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @group happy
	 */
	public function test_關閉時不載入任何資源也不加body_class(): void {
		$this->set_checkout_settings( [ 'checkout_two_column' => 'no' ] );
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$this->run_wp_enqueue_scripts();

		$this->assertFalse( \wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertFalse( \wp_style_is( self::HANDLE, 'enqueued' ) );
		$this->assertNotContains( TwoColumnLayoutService::BODY_CLASS, \get_body_class() );
	}

	/**
	 * @group happy
	 */
	public function test_review_order商品列之間加上縮圖_之後移除(): void {
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$item = $this->cart_item( 3 );

		\do_action( 'woocommerce_review_order_before_cart_contents' );
		$inside = (string) \apply_filters( 'woocommerce_cart_item_name', '兩欄測試商品', $item, 'key' );
		\do_action( 'woocommerce_review_order_after_cart_contents' );
		$after = (string) \apply_filters( 'woocommerce_cart_item_name', '兩欄測試商品', $item, 'key' );

		$this->assertStringContainsString( 'class="pc-tc-thumb"', $inside );
		$this->assertStringContainsString( '<span class="pc-tc-thumb-qty">3</span>', $inside );
		$this->assertStringEndsWith( '兩欄測試商品', $inside );
		$this->assertSame( '兩欄測試商品', $after, 'review-order 之外（購物車頁 / 迷你購物車）不加縮圖' );
	}

	/**
	 * @group happy
	 */
	public function test_關閉時review_order不加縮圖(): void {
		$this->set_checkout_settings( [ 'checkout_two_column' => 'no' ] );
		$this->go_to_checkout_page( '[woocommerce_checkout]' );

		\do_action( 'woocommerce_review_order_before_cart_contents' );
		$name = (string) \apply_filters( 'woocommerce_cart_item_name', '兩欄測試商品', $this->cart_item(), 'key' );
		\do_action( 'woocommerce_review_order_after_cart_contents' );

		$this->assertSame( '兩欄測試商品', $name );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @group edge
	 */
	public function test_區塊結帳頁不受影響(): void {
		$this->go_to_checkout_page( '<!-- wp:woocommerce/checkout /-->' );
		$this->run_wp_enqueue_scripts();

		$this->assertFalse( \wp_script_is( self::HANDLE, 'enqueued' ), '區塊結帳不載入兩欄腳本' );
		$this->assertFalse( \wp_style_is( self::HANDLE, 'enqueued' ), '區塊結帳不載入兩欄樣式' );
		$this->assertNotContains( TwoColumnLayoutService::BODY_CLASS, \get_body_class() );
	}

	/**
	 * @group edge
	 */
	public function test_order_received端點不套用(): void {
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$GLOBALS['wp']->query_vars['order-received'] = '123';

		$this->assertFalse( TwoColumnLayoutService::should_apply() );
		$this->run_wp_enqueue_scripts();
		$this->assertFalse( \wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	/**
	 * @group edge
	 */
	public function test_非結帳頁不載入(): void {
		$page_id = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
			);
		$this->go_to( (string) \get_permalink( $page_id ) );
		$GLOBALS['post'] = \get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 測試模擬目前頁面（避免前一支測試殘留的結帳頁 $post）
		\setup_postdata( $GLOBALS['post'] );
		$this->reset_page_type_cache();
		if ( \defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			// 全套執行時，先前測試走過 WC_Checkout::process_checkout() 會定義此常數（無法取消），is_checkout() 恆為 true
			$this->markTestSkipped( 'WOOCOMMERCE_CHECKOUT 已被先前測試定義，無法模擬非結帳頁；請單獨執行本測試類別' );
		}
		$this->assertFalse( \is_checkout(), '前提：一般頁面不是結帳頁' );
		$this->run_wp_enqueue_scripts();

		$this->assertFalse( \wp_script_is( self::HANDLE, 'enqueued' ) );
		$this->assertFalse( \wp_style_is( self::HANDLE, 'enqueued' ) );
	}

	/**
	 * @group edge
	 * @dataProvider provide_bad_inputs
	 *
	 * @param mixed $name      商品名稱
	 * @param mixed $cart_item 購物車項目
	 */
	public function test_縮圖filter遇到非預期輸入時原樣回傳( mixed $name, mixed $cart_item ): void {
		$this->assertSame( $name, TwoColumnLayoutService::prepend_thumbnail( $name, $cart_item ) );
	}

	/** @return array<string, array{0: mixed, 1: mixed}> */
	public static function provide_bad_inputs(): array {
		return [
			'cart_item 非陣列'     => [ '商品', null ],
			'name 非字串'          => [ null, [ 'quantity' => 1 ] ],
			'data 非 WC_Product' => [
				'商品',
				[
					'data'     => 'x',
					'quantity' => 1,
				],
			],
		];
	}

	// ========== 安全 ==========

	/**
	 * @group security
	 */
	public function test_設定值只接受yes或no(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid(
			[ 'checkout_two_column' => '<script>alert(1)</script>' ],
			[ 'checkout_two_column' => 'no' ]
		);
		$this->assertSame( 'no', $merged['checkout_two_column'], '不合法值維持原值' );

		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'checkout_two_column' => 'no' ], [] );
		$this->assertSame( 'no', $merged['checkout_two_column'] );
	}
}
