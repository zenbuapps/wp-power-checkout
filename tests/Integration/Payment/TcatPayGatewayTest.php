<?php
/**
 * 黑貓PAY gateway 測試（P2）
 *
 * - credit：CocsOrderAppend → 回傳刷卡短網址 url 作為 redirect（api-redirect）
 * - cvs_ibon / atm：CvsOrderAppend → 寫 payment_info、回 order-received；thank-you 頁顯示代碼與期限
 * - 子方式由結帳欄位 pc_tcat_pay_method 決定（classic payment_fields / blocks paymentMethodData 皆放進 $_POST）
 * - 金額超過子方式上限 → 子方式不顯示、前置擋下不打 API
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayGateway --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayAmountLimits;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY gateway 測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayGatewayTest extends TestCase {
	use TcatPayTestTrait;

	/** 每個測試前：啟用黑貓PAY */
	protected function configure_dependencies(): void {
		$this->setup_tcat_pay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_pay_http();
		unset( $_POST[ TcatPayGateway::FIELD_NAME ] );
		\wc_clear_notices();
		parent::tear_down();
	}

	/**
	 * 以指定子方式走 process_payment
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $method 子方式
	 * @return array<string, mixed>
	 */
	private function pay( \WC_Order $order, string $method ): array {
		$_POST[ TcatPayGateway::FIELD_NAME ] = $method;
		return ( new TcatPayGateway() )->process_payment( $order->get_id() );
	}

	/**
	 * 冒煙：已註冊為 WC gateway
	 *
	 * @group smoke
	 */
	public function test_冒煙_已註冊為WC付款方式(): void {
		\WC()->payment_gateways()->init();
		$gateways = \WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( TcatPayGateway::ID, $gateways );
		$this->assertInstanceOf( TcatPayGateway::class, $gateways[ TcatPayGateway::ID ] );
	}

	/**
	 * Happy：credit → CocsOrderAppend，redirect 為平台回傳的刷卡短網址
	 *
	 * @group happy
	 */
	public function test_credit建立刷卡訂單並導向短網址(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cocs_order_append', (string) $body['cust_order_no'] )
		);

		$result = $this->pay( $order, 'credit' );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://goo.gl/K616G6', $result['redirect'] );

		$body = $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_APPEND );
		$meta = new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( $meta->get_trade_no(), $body['cust_order_no'] );
		$this->assertMatchesRegularExpression( '/^PCT' . $order->get_id() . '-[A-Z0-9]+$/', (string) $body['cust_order_no'] );
		$this->assertLessThanOrEqual( 25, \strlen( (string) $body['cust_order_no'] ) );
		$this->assertSame( 1000, $body['order_amount'], '金額為整數元' );
		$this->assertSame( 'payuni', $body['acquirer_type'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $body['send_time'] );
		$this->assertStringContainsString( 'order-received', (string) $body['success_url'] );
		$this->assertLessThanOrEqual( 250, \strlen( (string) $body['success_url'] ) );
		$this->assertStringContainsString( 'power-checkout/tcat-pay/apn', (string) $body['apn_url'] );
		$this->assertNotSame( '', (string) $body['order_detail'] );

		$this->assertSame( 'credit', $meta->get_method() );
		$this->assertSame( 1000, $meta->get_charge_amount() );
		$this->assertContains( $meta->get_trade_no(), $meta->get_trade_no_history() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Happy：cvs_ibon → CvsOrderAppend（payment_type=0 + 安源 2），寫 payment_info、回 order-received
	 *
	 * @group happy
	 */
	public function test_cvs_ibon取號寫入繳費資訊並回order_received(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cvs_order_append_ibon', (string) $body['cust_order_no'] )
		);

		$result = $this->pay( $order, 'cvs_ibon' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $fresh->get_checkout_order_received_url(), $result['redirect'] );

		$body = $this->tcat_request_body( TcatPayApiClient::CMD_CVS_ORDER_APPEND );
		$this->assertSame( '0', $body['payment_type'] );
		$this->assertSame( '2', $body['payment_acquirerType'] );
		$this->assertSame( 1000, $body['order_amount'] );
		$this->assertSame( '0912345678', $body['payer_mobile'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) $body['expire_date'] );
		$this->assertStringContainsString( 'power-checkout/tcat-pay/apn', (string) $body['apn_url'] );

		$info = ( new TcatPayMetaKeys( $fresh ) )->get_payment_info();
		$this->assertSame( '909606553021', $info['ibon_code'] ?? null );
		$this->assertSame( '2026-11-24', $info['expire_date'] ?? null );
		$this->assertSame( 'pending', $fresh->get_status() );
	}

	/**
	 * Happy：order-received 頁顯示 ibon 代碼與繳費期限
	 *
	 * @group happy
	 */
	public function test_thankyou頁顯示代碼與期限(): void {
		$order = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', 'PCT1-THANK' );
		( new TcatPayMetaKeys( $order ) )->update_payment_info(
			[
				'ibon_code'   => '909606553021',
				'expire_date' => '2026-11-24',
				'bill_amount' => 1000,
			]
		);

		\ob_start();
		( new TcatPayGateway() )->render_thankyou( $order->get_id() );
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( '909606553021', $html );
		$this->assertStringContainsString( '2026-11-24', $html );
	}

	/**
	 * Happy：atm → CvsOrderAppend（payment_type=1 + 統一金流 3），帶繳款人姓名 / 郵遞區號 / 地址 / email
	 *
	 * @group happy
	 */
	public function test_atm取號帶繳款人資料(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cvs_order_append_atm', (string) $body['cust_order_no'] )
		);

		$result = $this->pay( $order, 'atm' );

		$this->assertSame( 'success', $result['result'] );
		$body = $this->tcat_request_body( TcatPayApiClient::CMD_CVS_ORDER_APPEND );
		$this->assertSame( '1', $body['payment_type'] );
		$this->assertSame( '3', $body['payment_acquirerType'] );
		$this->assertSame( '王小明', $body['payer_name'] );
		$this->assertSame( '100', $body['payer_postcode'] );
		$this->assertStringContainsString( '測試路 1 號', (string) $body['payer_address'] );
		$this->assertSame( 'buyer@example.com', $body['payer_email'] );

		$info = ( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertSame( '98214000000965', $info['virtual_account'] ?? null );
	}

	/**
	 * Edge：金額超過子方式上限 → 子方式不顯示；強行送出 → 前置擋下、不打 API
	 *
	 * @group edge
	 */
	public function test_超過子方式上限不顯示且前置擋下(): void {
		$settings  = TcatPaySettingsDTO::instance();
		$available = TcatPayAmountLimits::available_methods( $settings, 25000.0 );
		$this->assertSame( [ TcatPayMethod::CREDIT, TcatPayMethod::ATM ], $available, 'ibon 上限 20,000 應被排除' );

		$this->block_tcat_http();
		$order  = $this->make_tcat_order( 25000.0 );
		$result = $this->pay( $order, 'cvs_ibon' );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( [], $this->tcat_collect_requests(), '超限不得送平台' );
	}

	/**
	 * Edge：classic 結帳欄位只列出啟用且金額內的子方式
	 *
	 * @group edge
	 */
	public function test_結帳欄位只列啟用的子方式(): void {
		$this->setup_tcat_pay( [ 'enabled_methods' => [ 'credit', 'atm' ] ] );
		$html = TcatPayGateway::render_method_field( TcatPaySettingsDTO::instance(), 1000.0 );

		$this->assertStringContainsString( 'value="credit"', $html );
		$this->assertStringContainsString( 'value="atm"', $html );
		$this->assertStringNotContainsString( 'value="cvs_ibon"', $html );
		$this->assertStringContainsString( TcatPayGateway::FIELD_NAME, $html );
	}

	/**
	 * Edge：order_detail 超長截斷（COCS ≤500、CVS ≤150），不含 HTML tag
	 *
	 * @group edge
	 */
	public function test_order_detail超長截斷(): void {
		$order = $this->make_tcat_order( 1000.0 );
		for ( $i = 0; $i < 30; $i++ ) {
			$item = new \WC_Order_Item_Product();
			$item->set_name( "<b>超長商品名稱</b>{$i}" . \str_repeat( '測', 30 ) );
			$item->set_quantity( 1 );
			$item->set_total( '10' );
			$order->add_item( $item );
		}
		$order->save();
		$order->set_total( '1000' );
		$order->save();

		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cocs_order_append', (string) $body['cust_order_no'] )
		);
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cvs_order_append_ibon', (string) $body['cust_order_no'] )
		);

		$this->pay( $order, 'credit' );
		$cocs = (string) $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_APPEND )['order_detail'];
		$this->assertLessThanOrEqual( 500, \mb_strlen( $cocs ) );
		$this->assertStringNotContainsString( '<b>', $cocs );

		$this->pay( \wc_get_order( $order->get_id() ), 'cvs_ibon' );
		$cvs = (string) $this->tcat_request_body( TcatPayApiClient::CMD_CVS_ORDER_APPEND )['order_detail'];
		$this->assertLessThanOrEqual( 150, \mb_strlen( $cvs ) );
		$this->assertNotSame( '', $cvs );
	}

	/**
	 * Edge：同訂單重新付款產生新的交易編號（平台 cust_order_no 不可重複），舊編號保留於歷史
	 *
	 * @group edge
	 */
	public function test_重新付款產生新編號並保留歷史(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cocs_order_append', (string) $body['cust_order_no'] )
		);

		$this->pay( $order, 'credit' );
		$this->pay( \wc_get_order( $order->get_id() ), 'credit' );

		$first  = (string) $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_APPEND, 0 )['cust_order_no'];
		$second = (string) $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_APPEND, 1 )['cust_order_no'];
		$this->assertNotSame( $first, $second );

		$history = ( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no_history();
		$this->assertContains( $first, $history );
		$this->assertContains( $second, $history );
	}

	/**
	 * Error：平台回 ERROR → 結帳失敗，order note 記錄平台訊息
	 *
	 * @group error
	 */
	public function test_取號失敗結帳失敗並記錄note(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_APPEND, $this->tcat_fixture( 'cvs_order_append_error' ) );

		$result = $this->pay( $order, 'cvs_ibon' );

		$this->assertSame( 'failure', $result['result'] );
		$notes = \implode( "\n", $this->tcat_order_notes( $order ) );
		$this->assertStringContainsString( '新增訂單失敗!!', $notes );
	}

	/**
	 * Error：送出未啟用 / 不存在的子方式 → validate_fields false
	 *
	 * @group error
	 */
	public function test_不合法子方式驗證失敗(): void {
		$this->setup_tcat_pay( [ 'enabled_methods' => [ 'credit' ] ] );

		$_POST[ TcatPayGateway::FIELD_NAME ] = 'atm';
		$this->assertFalse( ( new TcatPayGateway() )->validate_fields() );

		$_POST[ TcatPayGateway::FIELD_NAME ] = 'linepay';
		$this->assertFalse( ( new TcatPayGateway() )->validate_fields() );

		$_POST[ TcatPayGateway::FIELD_NAME ] = 'credit';
		$this->assertTrue( ( new TcatPayGateway() )->validate_fields() );
	}

	/**
	 * Edge：非 TWD / 憑證缺 / 全部子方式超限 → 不可用
	 *
	 * @group edge
	 */
	public function test_非TWD或缺憑證或全超限時不可用(): void {
		$gateway = new TcatPayGateway();
		$this->assertTrue( $gateway->is_available_for_total( 1000.0 ) );
		$this->assertFalse( $gateway->is_available_for_total( 200000.0 ), '全部子方式超限' );

		\update_option( 'woocommerce_currency', 'USD' );
		$this->assertFalse( ( new TcatPayGateway() )->is_available_for_total( 1000.0 ), '非 TWD' );

		\update_option( 'woocommerce_currency', 'TWD' );
		$this->setup_tcat_pay( [ 'api_password' => '' ] );
		$this->assertFalse( ( new TcatPayGateway() )->is_available_for_total( 1000.0 ), '缺憑證' );
	}

	/**
	 * Happy：provider meta 為 api-redirect，COCS 能力全開、不做定期定額（ASM-5）
	 *
	 * @group happy
	 */
	public function test_provider_meta宣告(): void {
		$meta = ( new TcatPayGateway() )->get_provider_meta()->to_array();

		$this->assertSame( 'tcat_pay', $meta['id'] );
		$this->assertSame( 'api-redirect', $meta['payment_mode'] );
		$this->assertSame(
			[
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => true,
				'void'           => true,
				'query'          => true,
				'recurring'      => false,
			],
			$meta['capabilities']
		);
	}
}
