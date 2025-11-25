<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoIntegration;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\InvoiceApiService;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\IntegrationUtils;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\WpUtils\Classes\WP;

/** Loader 載入電子發票方式 */
final class ServiceRegister {

	// 發票 APP 渲染用的 ID
	private const RENDER_ID = 'power_checkout_invoice_metabox_app';

	/** @var array<string, string> $invoice_services [id, class]  */
	private static array $invoice_services = [
		AmegoIntegration::ID => AmegoIntegration::class,
	];


	/** 註冊 hooks */
	public static function register_hooks(): void {
		// 支援傳統訂單和 HPOS
		// 使用 'add_meta_boxes' hook 可以同時支援兩種儲存方式
		\add_action( 'add_meta_boxes', [ __CLASS__, 'add_invoice_meta_box' ] );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'issue_invoice_script' ], 20 );

		$any_enabled = false;
		foreach ( self::$invoice_services as $id => $class ) {
			// 如果電子發票啟用，才實例化放入容器
			if (!IntegrationUtils::is_enabled( $id)) {
				continue;
			}
			IntegrationUtils::$container[ $id ] = \call_user_func([ $class, 'instance' ]);
			$any_enabled                        = true;
		}

		// 有啟用的服務才註冊 API
		if ($any_enabled) {
			InvoiceApiService::instance();
		}

		// TEST ----- ▼ 測試特定 hook 記得刪除 ----- //
		\add_action(
			'init',
			function () {

				$order = \wc_get_order(275);

				// echo '<pre>';
				// var_dump(
				// [
				// 'isset' => isset($_GET['issue']),
				// ]
				// );
				// echo '</pre>';
				if (isset($_GET['issue'])) {
					$client = new ApiClient( $order);
					$client->issue();
				}
			}
			);
		// TEST ---------- END ---------- //
	}


	/**
	 * 新增發票 MetaBox
	 *
	 * @param string $post_type 文章類型
	 */
    public static function add_invoice_meta_box( string $post_type ): void { // phpcs:ignore
		// 支援 HPOS 和傳統訂單
		// HPOS: screen_id 為 'woocommerce_page_wc-orders'
		// 傳統: post_type 為 'shop_order'
		$order_screen_ids = [ 'shop_order' ];

		// 檢查是否啟用 HPOS
		if ( \class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order_screen_ids[] = \wc_get_page_screen_id( 'shop-order' );
			}
		}

		\add_meta_box(
			'power_checkout_invoice_meta_box',
			\__( '電子發票資訊', 'power_checkout' ),
			[ __CLASS__, 'render_invoice_meta_box' ],
			$order_screen_ids,
			'side',
			'high'
		);
	}

	/**
	 * 渲染發票 MetaBox 內容
	 *
	 * @param \WP_Post|\WC_Order $post_or_order 訂單物件 (HPOS) 或文章物件 (傳統)
	 */
	public static function render_invoice_meta_box( \WP_Post|\WC_Order $post_or_order ): void {
		// 取得訂單物件
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );

		if ( !$order ) {
			echo '無法取得訂單資訊';
			return;
		}

		$issued_data = ( new MetaKeys( $order) )->get_issued_data();
		if ($issued_data) {
			echo WP::array_to_html(
				[
					'發票號碼' => $issued_data['invoice_number'] ?? '',
				]
				);
		}

		\printf(
			'<div id="%1$s" data-order-id="%2$s" style="margin-top:1rem;"></div>',
		self::RENDER_ID,
			$order->get_id()
		);
	}


	/**
	 * Enqueue 發票 APP Script
	 *
	 * @param string $hook 後台頁面 hook
	 *
	 * @return void
	 */
	public static function issue_invoice_script( $hook ): void {
		if ( !OrderUtils::is_order_detail( $hook ) ) {
			return;
		}
		SettingTabService::enqueue_vue_app();

		$order_id = OrderUtils::get_order_id( $hook );
		$order    = \wc_get_order( $order_id );
		if ( !$order instanceof \WC_Order ) {
			return;
		}

		// 要額外給前端的資料
		$obj_name = self::RENDER_ID . '_data'; // power_checkout_invoice_metabox_app_data
		\wp_localize_script(
			SettingTabService::$handle,
			$obj_name,
			[
				'render_id' => self::RENDER_ID,
				'order'     => [
					'id' => (string) $order->get_id(),
				],
				'is_admin'  => \is_admin(),

			]
		);
	}


	/** @return BaseSettingsDTO[] 取得 integration 設定 dtos */
	public static function get_registered_integration_dtos(): array {
		return \array_map( static fn( $class_name ) => BaseSettingsDTO::create( $class_name), self::$invoice_services );
	}
}
