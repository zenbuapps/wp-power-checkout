<?php
/**
 * 藍新 NewebPay MPG 取號型付款（ATM 虛擬帳號 / 超商代碼 / 超商條碼）行為鎖定測試
 *
 * 對應 issue：
 *  - #24 ATM 取號回傳 TradeInfo 解密失敗：藍新以 32 bytes 區塊做 PKCS#7 補位，補位值 > 16 時
 *        OpenSSL 標準解補位回 false → TradeInfoCrypto::decrypt 改為自行移除 1～32 補位。
 *  - #25 取號後回完成頁重送付款表單 → MPG03008 無限循環：
 *        改為「process_payment 留待導轉旗標才自動導轉一次」+ 每次付款嘗試換新 MerchantOrderNo（保留歷史），
 *        thank-you 頁改顯示繳費資訊 / 前往付款連結；失敗通知不覆寫既有取號資訊。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/MpgOfflinePaymentTest.php --no-coverage'
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgOrderNo;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 藍新 MPG 取號型付款測試類別
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 * @group edge
 */
final class MpgOfflinePaymentTest extends TestCase {

	/** @var string 藍新 MPG 公開測試帳號 HashKey */
	private const HASH_KEY = 'Vh2Br0kFGSGHA9zXFDJuf9KIVgVxX1pn';

	/** @var string 藍新 MPG 公開測試帳號 HashIV */
	private const HASH_IV = 'IZGViXjMd2gWMtsR';

	/** @var string 藍新 MPG 公開測試帳號 MerchantID */
	private const MERCHANT_ID = 'MS154450763';

	/** 每次測試前啟用 newebpay_mpg（test 模式 + 公開測試憑證），並重置同請求導轉記錄 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		\J7\PowerCheckout\Plugin::$template_page_names = [ 'auto-form' ];

		ProviderUtils::update_option(
			MpgRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => self::MERCHANT_ID,
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);

		$this->reset_redirect_attempts();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		parent::tear_down();
	}

	// region helpers

	/** 重置 MpgRedirectGateway::$redirect_attempts（模擬新的 HTTP 請求） */
	private function reset_redirect_attempts(): void {
		$property = new \ReflectionProperty( MpgRedirectGateway::class, 'redirect_attempts' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * 建立藍新訂單（可選綁定 MerchantOrderNo）
	 *
	 * @param string $order_no MerchantOrderNo（空字串 = 從未送出過）
	 * @param string $status   訂單狀態
	 * @return \WC_Order
	 */
	private function create_mpg_order( string $order_no = '', string $status = 'pending' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => MpgRedirectGateway::ID,
				'total'          => 1000,
			]
		);
		if ( '' !== $order_no ) {
			( new MpgMetaKeys( $order ) )->update_order_no( $order_no );
		}
		return $order;
	}

	/**
	 * 以藍新 32 bytes 區塊 PKCS#7 補位加密（模擬藍新官方 addpadding blocksize=32）
	 *
	 * @param string $plaintext 明文
	 * @return string hex 密文
	 */
	private static function encrypt_like_newebpay( string $plaintext ): string {
		$pad    = 32 - ( \strlen( $plaintext ) % 32 );
		$padded = $plaintext . \str_repeat( \chr( $pad ), $pad );
		$key    = \substr( \str_pad( self::HASH_KEY, 32, '0' ), 0, 32 );
		$iv     = \substr( \str_pad( self::HASH_IV, 16, '0' ), 0, 16 );

		$encrypted = \openssl_encrypt( $padded, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv );
		return \bin2hex( (string) $encrypted );
	}

	/**
	 * 組藍新通知參數（以 32 bytes 區塊補位加密，並保證補位值 > 16）
	 *
	 * @param string               $status 頂層 Status
	 * @param array<string, mixed> $result Result
	 * @return array<string, string> { TradeInfo, TradeSha }
	 */
	private function build_notify_params( string $status, array $result ): array {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		if ( 'SUCCESS' === $status ) {
			$result['CheckCode'] = $crypto->generate_check_code( $result );
		}

		$payload = [
			'Status'  => $status,
			'Message' => 'SUCCESS' === $status ? '取號成功' : '已存在相同的商店訂單編號',
			'Result'  => $result,
		];

		// 調整 Message 長度直到補位值 > 16（OpenSSL 標準解補位必失敗的情境）
		$json = (string) \wp_json_encode( $payload );
		$pad  = 32 - ( \strlen( $json ) % 32 );
		while ( $pad <= 16 ) {
			$payload['Message'] .= ' ';
			$json                = (string) \wp_json_encode( $payload );
			$pad                 = 32 - ( \strlen( $json ) % 32 );
		}

		$trade_info = self::encrypt_like_newebpay( $json );
		return [
			'TradeInfo' => $trade_info,
			'TradeSha'  => $crypto->generate_trade_sha( $trade_info ),
		];
	}

	/**
	 * VACC 取號 Result
	 *
	 * @param string $order_no MerchantOrderNo
	 * @return array<string, mixed>
	 */
	private function vacc_get_code_result( string $order_no ): array {
		return [
			'MerchantID'      => self::MERCHANT_ID,
			'Amt'             => 1000,
			'TradeNo'         => '24101912345678',
			'MerchantOrderNo' => $order_no,
			'PaymentType'     => 'VACC',
			'ExpireDate'      => '2026-10-27',
			'ExpireTime'      => '23:59:59',
			'BankCode'        => '012',
			'CodeNo'          => '9876543210123456',
		];
	}

	/**
	 * 呼叫 protected before_order_received 並擷取輸出
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 渲染輸出（auto-form HTML 或空字串）
	 */
	private function invoke_before_order_received( \WC_Order $order ): string {
		$gateway = new MpgRedirectGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );

		\ob_start();
		$method->invoke( $gateway, $order );
		return (string) \ob_get_clean();
	}

	/**
	 * 擷取 thank-you 頁輸出
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function render_thankyou( \WC_Order $order ): string {
		\ob_start();
		MpgRedirectGateway::render_thankyou_payment_info( $order->get_id() );
		return (string) \ob_get_clean();
	}

	// endregion

	// ========== #24：32 bytes 區塊 PKCS#7 補位 ==========

	/**
	 * 補位值 17～32 的密文可解開（舊寫法 openssl_decrypt 回 false）
	 *
	 * @test
	 * @group edge
	 */
	public function test_32bytes區塊補位值大於16可解密(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		foreach ( [ 1, 10, 15, 16 ] as $length ) { // 補位值 31、22、17、16
			$plaintext = \str_repeat( 'a', $length );
			$this->assertSame( $plaintext, $crypto->decrypt( self::encrypt_like_newebpay( $plaintext ) ), "明文長度 {$length} 應可解密" );
		}
	}

	/**
	 * 標準 16 bytes 區塊補位（本外掛 encrypt 產生）仍可解開
	 *
	 * @test
	 * @group happy
	 */
	public function test_16bytes區塊標準補位仍可解密(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		foreach ( [ 1, 16, 31, 32, 47 ] as $length ) {
			$plaintext = \str_repeat( 'b', $length );
			$this->assertSame( $plaintext, $crypto->decrypt( $crypto->encrypt( $plaintext ) ), "明文長度 {$length} 應可解密" );
		}
	}

	/**
	 * 補位格式不符（最後一 byte 為 0 / 補位 bytes 不一致）→ throw
	 *
	 * @test
	 * @group error
	 */
	public function test_補位格式不符拋例外(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$key    = \substr( \str_pad( self::HASH_KEY, 32, '0' ), 0, 32 );
		$iv     = \substr( \str_pad( self::HASH_IV, 16, '0' ), 0, 16 );

		// 32 bytes 明文、最後一 byte = 0x05 但前 4 byte 不是 0x05
		$bad = \bin2hex( (string) \openssl_encrypt( \str_repeat( 'c', 31 ) . \chr( 5 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv ) );

		$this->expectException( \RuntimeException::class );
		$crypto->decrypt( $bad );
	}

	/**
	 * VACC 取號通知（32 bytes 補位、補位值 > 16）→ 寫入繳費資訊、訂單維持 pending
	 *
	 * @test
	 * @group happy
	 */
	public function test_VACC取號通知寫入繳費資訊且維持pending(): void {
		$order_no = 'PCVACC' . \wp_rand( 100000, 999999 );
		$order    = $this->create_mpg_order( $order_no );

		$result = MpgCallback::instance()->handle_notify( $this->build_notify_params( 'SUCCESS', $this->vacc_get_code_result( $order_no ) ) );

		$this->assertInstanceOf( \WC_Order::class, $result, '解密成功應反查到訂單' );
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assert_order_status( $order, 'pending' );
		$info = ( new MpgMetaKeys( $order ) )->get_payment_info();
		$this->assertSame( '9876543210123456', $info['CodeNo'] ?? '' );
		$this->assertSame( '012', $info['BankCode'] ?? '' );
		$this->assertSame( '23:59:59', $info['ExpireTime'] ?? '' );
	}

	// ========== #25：完成頁不再重送付款表單 ==========

	/**
	 * 已送出過編號、沒有待導轉旗標（取號後導回 / 重新整理 / MPG03008 導回）→ 不輸出導轉表單
	 *
	 * @test
	 * @group error
	 */
	public function test_單純重訪完成頁不重送付款表單(): void {
		$order = $this->create_mpg_order( 'PCREVISIT' . \wp_rand( 100000, 999999 ) );

		$this->assertSame( '', \trim( $this->invoke_before_order_received( $order ) ) );
	}

	/**
	 * 顧客發起付款（待導轉旗標）→ 以新編號導轉一次，舊編號留在歷史、旗標清除；同請求不重複導轉
	 *
	 * @test
	 * @group happy
	 */
	public function test_發起付款以新編號導轉一次且保留舊編號(): void {
		$old_no = 'PCOLD' . \wp_rand( 100000, 999999 );
		$order  = $this->create_mpg_order( $old_no );
		( new MpgMetaKeys( $order ) )->mark_redirect_pending();

		$output = $this->invoke_before_order_received( $order );
		$this->assertStringContainsString( 'newebpay.com/MPG/mpg_gateway', $output );

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new MpgMetaKeys( $order );
		$new_no    = $meta_keys->get_order_no();
		$this->assertNotSame( $old_no, $new_no, '每次付款嘗試須換新 MerchantOrderNo（藍新同編號重送回 MPG03008）' );
		$this->assertSame( (string) $order->get_id(), MpgOrderNo::decode( $new_no ) );
		$this->assertContains( $old_no, $meta_keys->get_order_no_history() );
		$this->assertContains( $new_no, $meta_keys->get_order_no_history() );
		$this->assertFalse( $meta_keys->is_redirect_pending(), '導轉後須清除待導轉旗標' );

		// 同請求再呼叫（其他 gateway 實例的 wp hook）→ 不再輸出
		$this->assertSame( '', \trim( $this->invoke_before_order_received( $order ) ) );

		// 下一個請求（重新整理）→ 沒有旗標，不再導轉
		$this->reset_redirect_attempts();
		$this->assertSame( '', \trim( $this->invoke_before_order_received( $order ) ) );
	}

	/**
	 * 從未送出過編號的訂單（升級前建立）→ 仍會導轉
	 *
	 * @test
	 * @group edge
	 */
	public function test_從未送出過編號的訂單仍導轉(): void {
		$order = $this->create_mpg_order();

		$this->assertStringContainsString( 'newebpay.com/MPG/mpg_gateway', $this->invoke_before_order_received( $order ) );
	}

	/**
	 * 已取號訂單收到 MPG03008 失敗通知 → 繳費資訊 / 付款明細不被覆寫，維持 pending
	 *
	 * @test
	 * @group error
	 */
	public function test_已取號訂單收到MPG03008不覆寫繳費資訊(): void {
		$order_no = 'PCDUP' . \wp_rand( 100000, 999999 );
		$order    = $this->create_mpg_order( $order_no );
		MpgCallback::instance()->handle_notify( $this->build_notify_params( 'SUCCESS', $this->vacc_get_code_result( $order_no ) ) );

		$failure = [
			'MerchantID'      => self::MERCHANT_ID,
			'Amt'             => 1000,
			'MerchantOrderNo' => $order_no,
		];
		MpgCallback::instance()->handle_notify( $this->build_notify_params( 'MPG03008', $failure ) );

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new MpgMetaKeys( $order );
		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( '9876543210123456', $meta_keys->get_payment_info()['CodeNo'] ?? '' );
		$this->assertSame( 'VACC', $meta_keys->get_payment_detail()['PaymentType'] ?? '', '付款明細不得被失敗通知覆寫' );
		$this->assertSame( '24101912345678', $meta_keys->get_trade_no() );
	}

	/**
	 * 已付款訂單收到失敗通知 → 不得被改回 pending
	 *
	 * @test
	 * @group error
	 */
	public function test_已付款訂單收到失敗通知不改回pending(): void {
		$order_no = 'PCPAIDFAIL' . \wp_rand( 100000, 999999 );
		$order    = $this->create_mpg_order( $order_no, 'processing' );

		MpgCallback::instance()->handle_notify(
			$this->build_notify_params(
				'MPG03008',
				[
					'MerchantID'      => self::MERCHANT_ID,
					'MerchantOrderNo' => $order_no,
				]
			)
		);

		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 重試後用舊編號的 ATM 帳號繳費（PayTime、無 RespondCode）→ 反查到訂單、轉 processing、目前編號改寫為付款編號
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊編號ATM繳費完成轉processing並改寫目前編號(): void {
		$old_no = 'PCATMOLD' . \wp_rand( 100000, 999999 );
		$order  = $this->create_mpg_order( $old_no );
		MpgCallback::instance()->handle_notify( $this->build_notify_params( 'SUCCESS', $this->vacc_get_code_result( $old_no ) ) );

		// 顧客改用其他方式重新付款（換新編號），但最後仍用舊 ATM 帳號轉帳
		( new MpgMetaKeys( $order ) )->start_payment_attempt( 'PCATMNEW' . \wp_rand( 100000, 999999 ) );

		$paid   = [
			'MerchantID'        => self::MERCHANT_ID,
			'Amt'               => 1000,
			'TradeNo'           => '24101912345678',
			'MerchantOrderNo'   => $old_no,
			'PaymentType'       => 'VACC',
			'PayTime'           => '2026-10-21 10:00:00',
			'PayBankCode'       => '812',
			'PayerAccount5Code' => '12345',
		];
		$result = MpgCallback::instance()->handle_notify( $this->build_notify_params( 'SUCCESS', $paid ) );

		$this->assertInstanceOf( \WC_Order::class, $result, '舊編號須能從歷史反查到訂單' );
		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
		$this->assertSame( $old_no, ( new MpgMetaKeys( $order ) )->get_order_no(), '目前編號須改寫為實際付款的編號（QueryTradeInfo 用）' );
	}

	/**
	 * thank-you 頁：已取號顯示帳號；未取號待付款顯示「前往付款」；已付款不顯示
	 *
	 * @test
	 * @group happy
	 */
	public function test_thankyou頁顯示繳費資訊或前往付款(): void {
		$order_no = 'PCTY' . \wp_rand( 100000, 999999 );
		$order    = $this->create_mpg_order( $order_no );

		$this->assertStringContainsString( '前往付款', $this->render_thankyou( $order ) );

		MpgCallback::instance()->handle_notify( $this->build_notify_params( 'SUCCESS', $this->vacc_get_code_result( $order_no ) ) );
		$output = $this->render_thankyou( \wc_get_order( $order->get_id() ) );
		$this->assertStringContainsString( '9876543210123456', $output );
		$this->assertStringContainsString( '2026-10-27 23:59:59', $output );
		$this->assertStringNotContainsString( '前往付款', $output );

		$paid_order = $this->create_mpg_order( 'PCTYPAID' . \wp_rand( 100000, 999999 ), 'processing' );
		$this->assertSame( '', \trim( $this->render_thankyou( $paid_order ) ) );
	}
}
