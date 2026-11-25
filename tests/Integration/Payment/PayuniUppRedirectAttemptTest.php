<?php
/**
 * PAYUNi UPP 導轉迴圈修正 + ReturnURL 同步 + thank-you 繳費資訊
 *
 * 修正前：order-received 頁只要訂單仍待付款就自動送出 PAYUNi 表單，且 MerTradeNo 固定為 PCU{order_id}。
 *  - ATM / 超商取號後 PAYUNi 導回 → 再送一次同編號（UPP01007 或建第二筆交易），顧客永遠看不到繳費資訊。
 *  - 信用卡付款後 ReturnURL 比 NotifyURL 先到 → 又被導回 PAYUNi。
 * 修正後（比照綠界 AIO / 藍新 MPG）：只有 process_payment 標記待導轉才導轉一次、每次嘗試換新編號、
 * ReturnURL 帶回的已簽章結果走 NotifyURL 同一套驗證同步訂單、thank-you 頁顯示繳費資訊或「前往付款」。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\PayuniCallback;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniUppRedirectAttemptTest extends TestCase {

	private const HASH_KEY    = '12345678901234567890123456789012';
	private const HASH_IV     = '1234567890123456';
	private const MERCHANT_ID = 'TEST_MER';

	/** @var PayuniCrypto */
	private PayuniCrypto $crypto;

	/** 啟用 payuni_upp（test 模式） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'          => 'yes',
				'mode'             => 'test',
				'merchant_id'      => self::MERCHANT_ID,
				'hash_key'         => self::HASH_KEY,
				'hash_iv'          => self::HASH_IV,
				'allowed_payments' => [ 'Credit', 'ATM', 'CVS' ],
			]
		);
		$this->crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$this->reset_redirect_attempts();
	}

	/** 清理 */
	public function tear_down(): void {
		$_POST = [];
		\delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );
		parent::tear_down();
	}

	// ========== helpers ==========

	/** 重置 $redirect_attempts（模擬新的 HTTP 請求） */
	private function reset_redirect_attempts(): void {
		$property = new \ReflectionProperty( PayuniUppGateway::class, 'redirect_attempts' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/** @return \WC_Order pending 的 payuni_upp 訂單 */
	private function create_order(): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniSettingsDTO::ID,
				'total'          => 1000,
			]
		);
	}

	/**
	 * 呼叫 protected before_order_received 並擷取輸出
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function invoke_before_order_received( \WC_Order $order ): string {
		$gateway = new PayuniUppGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );
		\ob_start();
		$method->invoke( $gateway, \wc_get_order( $order->get_id() ) );
		return (string) \ob_get_clean();
	}

	/**
	 * @param int $order_id 訂單 ID
	 * @return string thank-you 頁輸出
	 */
	private function render_thankyou( int $order_id ): string {
		\ob_start();
		PayuniUppGateway::render_thankyou_payment_info( $order_id );
		return (string) \ob_get_clean();
	}

	/**
	 * 組已簽章的外層 Form POST（Notify / ReturnURL 同格式）
	 *
	 * @param array<string, string> $inner 內層 payload
	 * @return array<string, string>
	 */
	private function signed_body( array $inner ): array {
		$encrypt_info = $this->crypto->encrypt( $inner );
		return [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'Version'     => '2.0',
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $this->crypto->hash_info( $encrypt_info ),
		];
	}

	/**
	 * @param string $mer_trade_no MerTradeNo
	 * @return array<string, string> 信用卡已付款內層
	 */
	private function paid_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNI' . \wp_rand( 100000, 999999 ),
			'TradeAmt'    => '1000',
			'TradeStatus' => '1',
			'PaymentType' => '1',
		];
	}

	/**
	 * @param string $mer_trade_no MerTradeNo
	 * @return array<string, string> ATM 取號內層
	 */
	private function atm_inner( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'MerID'       => self::MERCHANT_ID,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'UNIATM001',
			'TradeAmt'    => '1000',
			'TradeStatus' => '0',
			'PaymentType' => '2',
			'BankType'    => '822',
			'PayNo'       => '95012345678901',
			'ExpireDate'  => '2026-12-31',
		];
	}

	// ========== PayuniTradeNo ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_encode_unique產生新格式且可反解訂單ID(): void {
		$trade_no = PayuniTradeNo::encode_unique( 123, [] );
		$this->assertMatchesRegularExpression( '/^PCU123_\d{10}$/', $trade_no );
		$this->assertLessThanOrEqual( 25, \strlen( $trade_no ) );
		$this->assertSame( 123, PayuniTradeNo::parse_order_id( $trade_no ) );
		$this->assertSame( 123, PayuniTradeNo::parse_order_id( 'PCU123' ), '舊格式仍可解析' );
		$this->assertNull( PayuniTradeNo::parse_order_id( 'PCU123_abc' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_encode_unique避開已用過的編號(): void {
		$first  = PayuniTradeNo::encode_unique( 7, [] );
		$second = PayuniTradeNo::encode_unique( 7, [ $first ] );
		$this->assertNotSame( $first, $second );
	}

	// ========== 導轉 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_發起付款後導轉一次並以新編號寫入目前編號與歷史(): void {
		$order = $this->create_order();
		( new PayuniUppGateway() )->process_payment( $order->get_id() );

		$output = $this->invoke_before_order_received( $order );
		$this->assertStringContainsString( 'sandbox-api.payuni.com.tw/api/upp', $output, '應輸出導轉 PAYUNi 的表單' );

		$meta_keys = new PayuniMetaKeys( \wc_get_order( $order->get_id() ) );
		$trade_no  = $meta_keys->get_trade_no();
		$this->assertMatchesRegularExpression( '/^PCU' . $order->get_id() . '_\d+$/', $trade_no );
		$this->assertContains( $trade_no, $meta_keys->get_trade_no_history() );
		$this->assertFalse( $meta_keys->is_redirect_pending(), '導轉後應清除待導轉旗標' );
	}

	/**
	 * 取號後 PAYUNi 導回 / 重新整理：不得再自動導轉（修正前會無限循環）
	 *
	 * @test
	 * @group security
	 */
	public function test_單純重訪完成頁不再自動導轉(): void {
		$order = $this->create_order();
		( new PayuniUppGateway() )->process_payment( $order->get_id() );
		$this->invoke_before_order_received( $order );
		$first = ( new PayuniMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no();

		$this->reset_redirect_attempts();
		$this->assertSame( '', \trim( $this->invoke_before_order_received( $order ) ) );
		$this->assertSame( $first, ( new PayuniMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no(), '重訪不應換編號' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_重新付款換新編號且舊編號的通知仍反查得到訂單(): void {
		$order   = $this->create_order();
		$gateway = new PayuniUppGateway();

		$gateway->process_payment( $order->get_id() );
		$this->invoke_before_order_received( $order );
		$old = ( new PayuniMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no();

		$this->reset_redirect_attempts();
		\sleep( 1 ); // 確保 timestamp 不同（encode_unique 也會自動避開，這裡讓情境更貼近真實）
		$gateway->process_payment( $order->get_id() );
		$this->invoke_before_order_received( $order );
		$new = ( new PayuniMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no();

		$this->assertNotSame( $old, $new, '重新付款必須換新編號（避免 UPP01007）' );
		$found = PayuniMetaKeys::get_order_by_trade_no( $old );
		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * 舊編號的 ATM 取號付款完成：訂單轉 processing，目前編號改寫為付款成功那筆（退款 / 請款要對到它）
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊編號付款成功後目前編號改寫為該筆(): void {
		$order     = $this->create_order();
		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->start_payment_attempt( 'PCU' . $order->get_id() . '_1700000001' );
		$meta_keys->start_payment_attempt( 'PCU' . $order->get_id() . '_1700000002' );

		PayuniCallback::instance()->handle_notify( $this->signed_body( $this->paid_inner( 'PCU' . $order->get_id() . '_1700000001' ) ) );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 'PCU' . $order->get_id() . '_1700000001', ( new PayuniMetaKeys( $fresh ) )->get_trade_no() );
	}

	// ========== ReturnURL 同步 ==========

	/**
	 * PAYUNi ReturnURL 帶已簽章的付款成功結果：當下同步為 processing，且不再導轉
	 *
	 * @test
	 * @group happy
	 */
	public function test_ReturnURL已簽章付款成功即同步為processing(): void {
		$order = $this->create_order();
		( new PayuniUppGateway() )->process_payment( $order->get_id() );
		$this->invoke_before_order_received( $order );
		$trade_no = ( new PayuniMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_trade_no();

		$this->reset_redirect_attempts();
		$_POST  = $this->signed_body( $this->paid_inner( $trade_no ) );
		$output = $this->invoke_before_order_received( $order );

		$this->assertSame( '', \trim( $output ), '付款完成導回不得再導轉' );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * 偽造的 ReturnURL POST（簽章錯）不得改訂單狀態
	 *
	 * @test
	 * @group security
	 */
	public function test_ReturnURL偽造簽章不改訂單狀態(): void {
		$order = $this->create_order();
		( new PayuniMetaKeys( $order ) )->start_payment_attempt( 'PCU' . $order->get_id() . '_1700000003' );

		$body             = $this->signed_body( $this->paid_inner( 'PCU' . $order->get_id() . '_1700000003' ) );
		$body['HashInfo'] = \str_repeat( 'A', 64 );
		$_POST            = $body;
		$this->invoke_before_order_received( $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	// ========== 交易查詢 / 請退款回應（沙盒實測格式） ==========

	/**
	 * 沙盒實測：/api/trade/query 明細在 Result[n]，最上層只有 Status / Message / Result
	 *
	 * @test
	 * @group happy
	 */
	public function test_交易查詢Result陣列攤平到最上層(): void {
		$flat = QueryTradeClient::flatten_result(
			[
				'Status'  => 'SUCCESS',
				'Message' => '查詢成功',
				'Result'  => [
					[
						'MerTradeNo'  => 'PCU1_100',
						'TradeStatus' => '2',
					],
					[
						'MerTradeNo'  => 'PCU1_200',
						'TradeStatus' => '1',
						'DataSource'  => 'A',
						'CloseStatus' => '9',
					],
				],
			],
			'PCU1_200'
		);

		$this->assertSame( 'SUCCESS', $flat['Status'] );
		$this->assertSame( '1', $flat['TradeStatus'], '應取 MerTradeNo 相符的那筆' );
		$this->assertSame( 'A', $flat['DataSource'] );
		$this->assertSame( '9', $flat['CloseStatus'] );
		$this->assertArrayNotHasKey( 'Result', $flat );
	}

	/**
	 * 沙盒實測：未請款就退款 → 外層 Status=CLOSE03001、原因只在已簽章內層（「關帳狀態不符合」）
	 *
	 * @test
	 * @group error
	 */
	public function test_請退款錯誤顯示已簽章內層原因(): void {
		$order = $this->create_order();
		$enc   = $this->crypto->encrypt(
			[
				'Status'  => 'CLOSE03001',
				'Message' => '處理失敗，關帳狀態不符合',
			]
		);

		try {
			( new DoActionClient( $order ) )->parse_response(
				[
					'Status'      => 'CLOSE03001',
					'EncryptInfo' => $enc,
					'HashInfo'    => $this->crypto->hash_info( $enc ),
				]
			);
			$this->fail( '應拋出例外' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '關帳狀態不符合', $e->getMessage() );
		}

		// 簽章不對的內層不採信
		try {
			( new DoActionClient( $order ) )->parse_response(
				[
					'Status'      => 'CLOSE03001',
					'EncryptInfo' => $enc,
					'HashInfo'    => \str_repeat( 'B', 64 ),
				]
			);
		} catch ( \Exception $e ) {
			$this->assertStringNotContainsString( '關帳狀態不符合', $e->getMessage() );
		}
	}

	// ========== thank-you ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_thankyou頁顯示ATM繳費資訊或前往付款(): void {
		$order = $this->create_order();
		( new PayuniMetaKeys( $order ) )->start_payment_attempt( 'PCU' . $order->get_id() . '_1700000004' );

		$this->assertStringContainsString( '前往付款', $this->render_thankyou( $order->get_id() ) );

		PayuniCallback::instance()->handle_notify( $this->signed_body( $this->atm_inner( 'PCU' . $order->get_id() . '_1700000004' ) ) );

		$output = $this->render_thankyou( $order->get_id() );
		$this->assertStringContainsString( '95012345678901', $output, '應顯示 ATM 轉帳帳號' );
		$this->assertStringContainsString( '822', $output, '應顯示銀行代碼' );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status(), '取號後維持待付款' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_thankyou頁已付款訂單不顯示(): void {
		$order = $this->create_order();
		$order->payment_complete( 'UNI1' );
		$this->assertSame( '', \trim( $this->render_thankyou( $order->get_id() ) ) );
	}
}
