<?php
/**
 * SLP Requester log 遮罩測試（P8 fix #2，資安）
 *
 * 根因：Shared/Helpers/Requester::post() 以 info 等級把 request_header 原樣寫進 WC log，
 * 其中含 apiKey（商店 API 金鑰）；任何能讀 WC 狀態 → 日誌的人都能取得完整金鑰。
 *
 * 契約：寫進 log（WC logger context / 訊息）的內容不可含完整 apiKey / signKey / clientKey，
 * 只保留後 4 碼（****abcd）供除錯辨識；實際送出的 HTTP header 仍是完整金鑰（不影響串接）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpRequesterLogMask --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP Requester log 遮罩
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpRequesterLogMaskTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string 測試用 apiKey（prod 模式才讀設定值） */
	private const API_KEY = 'sk_live_APIKEY_SECRET_9f8e7d6c5b4a';

	/** @var string 測試用 signKey */
	private const SIGN_KEY = 'SIGNKEY_SECRET_1a2b3c4d5e6f';

	/** @var string 測試用 clientKey */
	private const CLIENT_KEY = 'pk_live_CLIENTKEY_SECRET_77aa88bb';

	/** @var array<int, string> 攔截到的 log（訊息 + context JSON） */
	private array $captured_logs = [];

	/** 每個測試前：prod 模式 + 自訂憑證、攔截 WC log */
	protected function configure_dependencies(): void {
		$this->setup_slp_embedded(
			false,
			[
				'mode'       => 'prod',
				'merchantId' => 'MERCHANT_MASK_TEST',
				'apiKey'     => self::API_KEY,
				'signKey'    => self::SIGN_KEY,
				'clientKey'  => self::CLIENT_KEY,
			]
		);
		ProviderUtils::$container[ EmbeddedGateway::ID ] = new EmbeddedGateway();

		$this->captured_logs = [];
		\add_filter( 'woocommerce_logger_log_message', [ $this, 'capture_log' ], 10, 3 );
	}

	/** 清理 */
	public function tear_down(): void {
		\remove_filter( 'woocommerce_logger_log_message', [ $this, 'capture_log' ], 10 );
		$this->reset_slp_embedded_http();
		parent::tear_down();
	}

	/**
	 * 攔截 log（不改變訊息）
	 *
	 * @param mixed $message 訊息
	 * @param mixed $level   等級
	 * @param mixed $context context
	 * @return mixed
	 */
	public function capture_log( $message, $level = '', $context = [] ) {
		$this->captured_logs[] = (string) $message . ' ' . (string) \wp_json_encode( $context );
		return $message;
	}

	/**
	 * Security：建立交易時 log 不含完整 apiKey，保留後 4 碼；實際 HTTP header 仍為完整金鑰
	 *
	 * @group security
	 */
	public function test_請求log不含完整apiKey(): void {
		$this->assertSame( self::API_KEY, RedirectSettingsDTO::instance()->apiKey, '前置：設定已套用' );
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_raw_json_response( '{"tradeOrderId":"TRADE_MASK","status":"CREATED","nextAction":{"type":"3DS"}}' )
		);

		( new EmbeddedGateway() )->dispatch( $order, [ 'pay_session' => '{"opaque":"session"}' ] );

		$this->assertNotEmpty( $this->captured_logs, '應有請求 log' );
		$all = \implode( "\n", $this->captured_logs );
		foreach ( [ self::API_KEY, self::SIGN_KEY, self::CLIENT_KEY ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $all, 'WC log 不可含完整密鑰' );
		}
		$this->assertStringContainsString( \substr( self::API_KEY, -4 ), $all, '保留後 4 碼供除錯辨識' );

		$requests = $this->slp_api_requests();
		$this->assertNotEmpty( $requests );
		$this->assertSame( self::API_KEY, $requests[0]['headers']['apiKey'] ?? null, '實際送出的 header 必須是完整金鑰' );
	}

	/**
	 * Security：SLP 回錯誤碼時（error log + order note）也不含完整密鑰
	 *
	 * @group security
	 */
	public function test_錯誤回應log與order_note不含完整密鑰(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_json_response(
				[
					'code' => '1003',
					'msg'  => 'apiKey invalid',
				]
			)
		);

		try {
			( new EmbeddedGateway() )->dispatch( $order, [ 'pay_session' => '{"opaque":"session"}' ] );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期失敗
		}

		$notes = \array_map(
			static fn( $note ): string => (string) $note->content,
			\wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
		$all   = \implode( "\n", $this->captured_logs ) . "\n" . \implode( "\n", $notes );
		$this->assertStringNotContainsString( self::API_KEY, $all );
	}
}
