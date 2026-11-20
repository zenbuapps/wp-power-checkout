<?php
/**
 * SLP 嵌入式同一訂單重付測試（P8 fix #1）
 *
 * P8 驗收（訂單 #6664）：第一次付款失敗（PCI 1005 / 拒卡 / 3DS 放棄）後同一訂單再付，
 * SLP 回 1001「重複下單，交易已存在」，前台只看到 500 泛用錯誤 —— 因為 referenceOrderId 固定為 WC 訂單 ID。
 *
 * 修法契約：
 *  - 每次 confirm 產生新的 referenceOrderId，格式 `{order_id}-{attempt}-{6 碼大寫 hex}`（≤ 32 字元，SLP String(32)）
 *  - 每次送出的 reference 先寫入訂單 meta `_pc_slp_reference_history`（送出前即落盤，API 逾時也查得到單）
 *  - 歸屬判定：新格式 reference 只有「確實存在於該訂單 reference 歷史」才算屬於該訂單；
 *    不在歷史 / 指向別張訂單 → 拒絕（webhook OrderResolver、order-received 主動查詢、導轉式 return sync 一致）
 *  - 舊嘗試的通知：SUCCEEDED 仍可認列（並把 _pc_payment_identity 指回已付款那筆）；
 *    非 SUCCEEDED（FAILED / EXPIRED）不得變更訂單狀態（不可把重付中的訂單取消）
 *  - 1001 / 1006 → 409 CONFLICT 可讀訊息（不外洩 SLP 原始訊息）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedRetry --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Exceptions\DispatchException;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式重付測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedRetryTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string reference 歷史 meta key */
	private const HISTORY_KEY = '_pc_slp_reference_history';

	/** @var string 測試用 paySession */
	private const PAY_SESSION = '{"sdkVersion":"1.0.0","payload":"opaque-pay-session-retry=="}';

	/** @var string SLP webhook 路由 */
	private const WEBHOOK_ROUTE = '/power-checkout/slp/webhook';

	/** @var string|null 原始環境 */
	private ?string $original_env = null;

	/** 每個測試前：嵌入式啟用；env=production 以實際驗簽 webhook */
	protected function configure_dependencies(): void {
		$this->original_env = Plugin::$env;
		Plugin::$env        = 'production';
		$this->setup_slp_embedded();
		ProviderUtils::$container[ EmbeddedGateway::ID ] = new EmbeddedGateway();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		if ( null !== $this->original_env ) {
			Plugin::$env = $this->original_env;
		}
		parent::tear_down();
	}

	// region helpers

	/**
	 * 送出 dispatch confirm
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WP_REST_Response
	 */
	private function confirm( \WC_Order $order ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/pay/{$order->get_id()}/dispatch" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'order_key'   => $order->get_order_key(),
					'pay_session' => self::PAY_SESSION,
				]
			)
		);
		$response = \rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * 建單成功假回應（nextAction 有值）
	 *
	 * @param string $trade_order_id tradeOrderId
	 * @return void
	 */
	private function fake_create_ok( string $trade_order_id ): void {
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_raw_json_response(
				'{"acquirerType":"SDK","tradeOrderId":"' . $trade_order_id . '","status":"CREATED","nextAction":{"type":"3DS","url":"https:\/\/sandbox.example\/3ds"}}'
			)
		);
	}

	/**
	 * 所有打到 /trade/payment/create 的 referenceOrderId
	 *
	 * @return array<int, string>
	 */
	private function sent_references(): array {
		$refs = [];
		foreach ( $this->slp_api_requests() as $req ) {
			if ( \str_contains( $req['url'], '/trade/payment/create' ) ) {
				$body   = \json_decode( $req['body'], true );
				$refs[] = \is_array( $body ) ? (string) ( $body['referenceOrderId'] ?? '' ) : '';
			}
		}
		return $refs;
	}

	/**
	 * 讀訂單 reference 歷史
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	private function history( \WC_Order $order ): array {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$value = $fresh->get_meta( self::HISTORY_KEY );
		return \is_array( $value ) ? \array_values( \array_map( 'strval', $value ) ) : [];
	}

	/**
	 * 寫入 reference 歷史（模擬先前的付款嘗試）
	 *
	 * @param \WC_Order          $order 訂單
	 * @param array<int, string> $refs  references
	 * @return void
	 */
	private function seed_history( \WC_Order $order, array $refs ): void {
		$order->update_meta_data( self::HISTORY_KEY, $refs );
		$order->save();
	}

	/**
	 * 已簽章 webhook request
	 *
	 * @param array<string, mixed> $data 事件 data
	 * @param string               $type 事件類型
	 * @return \WP_REST_Request<array<string, mixed>>
	 */
	private function signed_request( array $data, string $type = 'trade.succeeded' ): \WP_REST_Request {
		$json      = (string) \wp_json_encode(
			[
				'id'      => 'EVT_RETRY_' . $data['tradeOrderId'] . '_' . $data['status'],
				'type'    => $type,
				'created' => \time(),
				'data'    => $data,
			]
		);
		$timestamp = (string) ( \time() * 1000 );

		$request = new \WP_REST_Request( 'POST', self::WEBHOOK_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'timestamp', $timestamp );
		$request->set_header( 'sign', \hash_hmac( 'sha256', "{$timestamp}.{$json}", self::$slp_sign_key ) );
		$request->set_body( $json );
		return $request;
	}

	/**
	 * 送 webhook
	 *
	 * @param string $trade_order_id tradeOrderId
	 * @param string $reference      referenceOrderId
	 * @param string $status         付款狀態
	 * @return \WP_REST_Response
	 */
	private function webhook( string $trade_order_id, string $reference, string $status = 'SUCCEEDED' ): \WP_REST_Response {
		$type = match ( $status ) {
			'SUCCEEDED' => 'trade.succeeded',
			'EXPIRED'   => 'trade.expired',
			default     => 'trade.failed',
		};
		return WebHook::instance()->post_webhook_callback(
			$this->signed_request( $this->slp_payment_payload( $trade_order_id, $reference, $status ), $type )
		);
	}

	/**
	 * 重新讀訂單
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WC_Order
	 */
	private function fresh( \WC_Order $order ): \WC_Order {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return $fresh;
	}

	// endregion

	// region 重付送出不同 reference

	/**
	 * Happy：同一訂單兩次 confirm 送出不同 referenceOrderId，格式合法且皆記入歷史
	 *
	 * @group happy
	 */
	public function test_重付送出不同referenceOrderId且記入歷史(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_create_ok( 'TRADE_RETRY_A' );

		$first = $this->confirm( $order );
		\delete_transient( EmbeddedGateway::LOCK_PREFIX . $order->get_id() ); // 模擬 30 秒鎖過期
		$second = $this->confirm( $order );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		$refs = $this->sent_references();
		$this->assertCount( 2, $refs );
		$this->assertNotSame( $refs[0], $refs[1], '重付必須換 referenceOrderId（SLP 1001 重複下單）' );
		foreach ( $refs as $ref ) {
			$this->assertMatchesRegularExpression( '/^' . $order->get_id() . '-\d{1,4}-[0-9A-F]{6}$/', $ref );
			$this->assertLessThanOrEqual( 32, \strlen( $ref ), 'SLP referenceOrderId 為 String(32)' );
		}
		$this->assertSame( $refs, $this->history( $order ) );
	}

	/**
	 * Error：API 逾時時 reference 仍已寫入歷史（SLP 可能已建單，webhook 要找得到單）
	 *
	 * @group error
	 */
	public function test_API逾時時reference仍已寫入歷史(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http( '/trade/payment/create', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$this->confirm( $order );

		$refs = $this->sent_references();
		$this->assertCount( 1, $refs );
		$this->assertSame( $refs, $this->history( $order ) );
	}

	/**
	 * Error：SLP 回 1001 重複下單 → 409 CONFLICT 可讀訊息（不外洩 SLP 原始訊息），鎖釋放
	 *
	 * @group error
	 */
	public function test_SLP回1001時回409可讀訊息(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_json_response(
				[
					'code' => '1001',
					'msg'  => '重複下單，交易已存在',
				]
			)
		);

		$response = $this->confirm( $order );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'CONFLICT', $data['error_code'] );
		$this->assertNotSame( '', (string) $data['message'] );
		$this->assertStringNotContainsString( '1001', (string) $data['message'] );
		$this->assertStringNotContainsString( '重複下單', (string) $data['message'], '不得外洩金流原始訊息' );
		$this->assertFalse( \get_transient( EmbeddedGateway::LOCK_PREFIX . $order->get_id() ) );
	}

	/**
	 * Security：已付款訂單不可再發起付款（gateway 層防線，不依賴 REST 層）
	 *
	 * @group security
	 */
	public function test_已付款訂單gateway層拒絕confirm且不打API(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order( 1000.0, 'processing' );

		try {
			( new EmbeddedGateway() )->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );
			$this->fail( '已付款訂單應拋 DispatchException' );
		} catch ( DispatchException $e ) {
			$this->assertSame( 409, $e->http_status );
		}

		$this->assertSame( [], $this->slp_api_requests() );
		$this->assertSame( [], $this->history( $order ) );
	}

	// endregion

	// region webhook 歸屬

	/**
	 * Happy：舊嘗試（reference 在歷史內）的 trade.succeeded 仍可認列，identity 指回已付款那筆
	 *
	 * @group happy
	 */
	public function test_舊嘗試webhook成功仍可認列(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_ATTEMPT_2' );
		$ref1  = "{$order->get_id()}-1-AAAAAA";
		$ref2  = "{$order->get_id()}-2-BBBBBB";
		$this->seed_history( $order, [ $ref1, $ref2 ] );

		$response = $this->webhook( 'TRADE_ATTEMPT_1', $ref1 );

		$fresh = $this->fresh( $order );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 'TRADE_ATTEMPT_1', $fresh->get_transaction_id() );
		$this->assertSame( 'TRADE_ATTEMPT_1', ( new MetaKeys( $fresh ) )->get_payment_identity(), '退款 / 查詢要對到實際付款的那筆' );
	}

	/**
	 * Happy：目前嘗試（identity 命中 + reference 在歷史內）的 trade.succeeded 正常認列
	 *
	 * @group happy
	 */
	public function test_目前嘗試webhook成功認列(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_CURRENT' );
		$ref   = "{$order->get_id()}-1-C0FFEE";
		$this->seed_history( $order, [ $ref ] );

		$this->webhook( 'TRADE_CURRENT', $ref );

		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
	}

	/**
	 * Edge：舊嘗試的 FAILED / EXPIRED 通知不得變更重付中訂單的狀態，也不得改 identity
	 *
	 * @group edge
	 */
	public function test_舊嘗試失敗或逾時通知不變更訂單狀態(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_ATTEMPT_2' );
		$ref1  = "{$order->get_id()}-1-AAAAAA";
		$ref2  = "{$order->get_id()}-2-BBBBBB";
		$this->seed_history( $order, [ $ref1, $ref2 ] );

		$this->webhook( 'TRADE_ATTEMPT_1', $ref1, 'EXPIRED' );
		$this->webhook( 'TRADE_ATTEMPT_1', $ref1, 'FAILED' );

		$fresh = $this->fresh( $order );
		$this->assertSame( 'pending', $fresh->get_status(), '舊嘗試逾時不可把重付中的訂單取消' );
		$this->assertSame( 'TRADE_ATTEMPT_2', ( new MetaKeys( $fresh ) )->get_payment_identity() );
	}

	/**
	 * Edge：已由某次嘗試付款後，另一次嘗試也成功 → 不覆寫、留「疑似重複付款」note
	 *
	 * @group edge
	 */
	public function test_已付款後另一嘗試成功留重複付款note(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_ATTEMPT_2' );
		$ref1  = "{$order->get_id()}-1-AAAAAA";
		$ref2  = "{$order->get_id()}-2-BBBBBB";
		$this->seed_history( $order, [ $ref1, $ref2 ] );

		$this->webhook( 'TRADE_ATTEMPT_2', $ref2 );
		$this->webhook( 'TRADE_ATTEMPT_1', $ref1 );

		$fresh = $this->fresh( $order );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 'TRADE_ATTEMPT_2', $fresh->get_transaction_id() );
		$notes = \array_map(
			static fn( $note ): string => (string) $note->content,
			\wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
		$this->assertNotEmpty(
			\array_filter( $notes, static fn( string $n ): bool => \str_contains( $n, '疑似重複付款' ) && \str_contains( $n, 'TRADE_ATTEMPT_1' ) )
		);
	}

	/**
	 * Security：偽造 reference（格式正確、指向本訂單，但不在歷史）→ 不認列
	 *
	 * @group security
	 */
	public function test_不在歷史的reference被拒絕(): void {
		$order = $this->make_slp_embedded_order();
		$this->seed_history( $order, [ "{$order->get_id()}-1-AAAAAA" ] );

		$this->webhook( 'TRADE_FORGED', "{$order->get_id()}-9-DEADBE" );

		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
		$this->assertSame( '', ( new MetaKeys( $this->fresh( $order ) ) )->get_payment_identity() );
	}

	/**
	 * Security：identity 命中本訂單，但 reference 不在本訂單歷史（疑似 meta 汙染）→ 不認列
	 *
	 * @group security
	 */
	public function test_identity命中但reference不在歷史時拒絕(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_POLLUTED' );
		$this->seed_history( $order, [ "{$order->get_id()}-1-AAAAAA" ] );

		$this->webhook( 'TRADE_POLLUTED', "{$order->get_id()}-2-FFFFFF" );

		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
	}

	/**
	 * Security：reference 屬於別張訂單（在別張訂單歷史內）→ 本訂單不認列
	 *
	 * @group security
	 */
	public function test_reference屬於別張訂單時本訂單不被認列(): void {
		$victim   = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_SHARED' );
		$attacker = $this->make_slp_embedded_order();
		$this->seed_history( $victim, [ "{$victim->get_id()}-1-AAAAAA" ] );
		// 歷史被塞進格式指向別張訂單的 reference（不可能由本外掛產生）也不得放行
		$this->seed_history( $attacker, [ "{$victim->get_id()}-2-BBBBBB" ] );

		$this->webhook( 'TRADE_SHARED', "{$victim->get_id()}-2-BBBBBB" );

		$this->assertSame( 'pending', $this->fresh( $victim )->get_status(), 'reference 不在 victim 歷史' );
		$this->assertSame( 'pending', $this->fresh( $attacker )->get_status(), 'reference 的訂單 ID 不是 attacker' );
	}

	/**
	 * Security：新格式 reference 指向導轉式訂單（無歷史）→ 不認列
	 *
	 * @group security
	 */
	public function test_新格式reference指向導轉式訂單時拒絕(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', null, 'shopline_payment_redirect' );

		$this->webhook( 'TRADE_REDIRECT_FORGED', "{$order->get_id()}-1-AAAAAA" );

		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
	}

	// endregion

	// region order-received / 後台主動查詢

	/**
	 * Happy：主動查詢結果 reference 在歷史內 → 認列
	 *
	 * @group happy
	 */
	public function test_主動查詢reference在歷史內時認列(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_QUERY_OK' );
		$ref   = "{$order->get_id()}-1-ABC123";
		$this->seed_history( $order, [ $ref ] );
		$this->fake_slp_http( '/trade/payment/get', $this->slp_json_response( $this->slp_payment_payload( 'TRADE_QUERY_OK', $ref ) ) );

		$result = ( new EmbeddedGateway() )->query_trade( $order );

		$this->assertSame( 'updated', $result['result'] );
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
	}

	/**
	 * Security：主動查詢結果 reference 不在歷史 → 不認列
	 *
	 * @group security
	 */
	public function test_主動查詢reference不在歷史時不認列(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_QUERY_BAD' );
		$this->seed_history( $order, [ "{$order->get_id()}-1-ABC123" ] );
		$this->fake_slp_http(
			'/trade/payment/get',
			$this->slp_json_response( $this->slp_payment_payload( 'TRADE_QUERY_BAD', "{$order->get_id()}-7-ABC123" ) )
		);

		$result = ( new EmbeddedGateway() )->query_trade( $order );

		$this->assertSame( 'mismatched_order', $result['result'] );
		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
	}

	/**
	 * Edge：升級前建立的交易（數字型 reference = 訂單 ID）仍可主動查詢認列（向下相容）
	 *
	 * @group edge
	 */
	public function test_舊版數字reference主動查詢仍可認列(): void {
		$order = $this->make_slp_embedded_order( 1000.0, 'pending', 'TRADE_QUERY_LEGACY' );
		$this->fake_slp_http(
			'/trade/payment/get',
			$this->slp_json_response( $this->slp_payment_payload( 'TRADE_QUERY_LEGACY', (string) $order->get_id() ) )
		);

		( new EmbeddedGateway() )->query_trade( $order );

		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
	}

	// endregion
}
