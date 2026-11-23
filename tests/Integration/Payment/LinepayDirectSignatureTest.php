<?php
/**
 * LINE Pay 直連簽章 / BigInt 解碼測試（P3）
 *
 * - X-LINE-Authorization = Base64(HMAC-SHA256(key=ChannelSecret, ChannelSecret + apiPath + (POST body | GET query) + nonce))
 * - golden 向量只取 .claude/skills/linepay/references/authentication.md §3「交叉驗證向量」
 * - 19 位 transactionId（R4）：回應解碼後必須是字串且逐字相同，不可經 float
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectSignature --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepaySignature;
use Tests\Integration\TestCase;

/**
 * LINE Pay 簽章測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectSignatureTest extends TestCase {

	/** @var string authentication.md §3 ChannelSecret */
	private const SECRET = 'a917ab6a2367b536f8e5a6e2977e06f4';

	/** @var string authentication.md §3 nonce */
	private const NONCE = 'c6b6a6a2-0c62-4b5a-9a43-4f4b8b1b6f3e';

	/**
	 * Happy：POST /v4/payments/request（authentication.md §3 第一組）
	 *
	 * @group happy
	 */
	public function test_POST_body簽章符合golden向量(): void {
		$signature = LinepaySignature::sign(
			self::SECRET,
			'/v4/payments/request',
			'{"amount":100,"currency":"TWD","orderId":"ORDER_1"}',
			self::NONCE
		);

		$this->assertSame( 'cv91+KhazyLoyDqa5g0EAFvmjwMj27l14nd+xcCJzUA=', $signature );
	}

	/**
	 * Happy：GET /v4/payments?orderId=ORDER_1（authentication.md §3 第二組；query 不含 ?）
	 *
	 * @group happy
	 */
	public function test_GET_query簽章符合golden向量(): void {
		$signature = LinepaySignature::sign( self::SECRET, '/v4/payments', 'orderId=ORDER_1', self::NONCE );

		$this->assertSame( 'FDyiT1trsOcYZoeO5gPEChdAaC5/7dFuYDyuo9S66Bk=', $signature );
	}

	/**
	 * Security：nonce / secret / body 任一不同 → 簽章不同；輸出為 Base64（44 字元），不是 hex
	 *
	 * @group security
	 */
	public function test_任一輸入不同簽章即不同且為Base64(): void {
		$base = LinepaySignature::sign( self::SECRET, '/v4/payments/request', '{"amount":100}', self::NONCE );

		$this->assertSame( 44, \strlen( $base ) );
		$this->assertNotSame( $base, LinepaySignature::sign( self::SECRET, '/v4/payments/request', '{"amount":100}', 'other-nonce' ) );
		$this->assertNotSame( $base, LinepaySignature::sign( 'b917ab6a2367b536f8e5a6e2977e06f4', '/v4/payments/request', '{"amount":100}', self::NONCE ) );
		$this->assertNotSame( $base, LinepaySignature::sign( self::SECRET, '/v4/payments/request', '{"amount": 100}', self::NONCE ) );
	}

	/**
	 * Security：nonce 為 UUID v4 且每次不同
	 *
	 * @group security
	 */
	public function test_nonce為UUIDv4且每次不同(): void {
		$a = LinepaySignature::nonce();
		$b = LinepaySignature::nonce();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * Edge：19 位 transactionId（含巢狀 refundList / originalTransactionId）解碼為字串且逐字相同；金額仍為 int
	 *
	 * @group edge
	 */
	public function test_19位transactionId解碼為字串不失真(): void {
		$raw = '{"returnCode":"0000","returnMessage":"success","info":[{"transactionId":2026111900000000001,'
			. '"originalTransactionId":9223372036854775807,"payInfo":[{"method":"CREDIT_CARD","amount":1000}],'
			. '"refundList":[{"refundTransactionId":2026111900000000999,"refundAmount":-300}]}]}';

		$json = LinepaySignature::decode_json( $raw );

		$this->assertIsArray( $json );
		$this->assertSame( '0000', $json['returnCode'] );
		$this->assertSame( '2026111900000000001', $json['info'][0]['transactionId'] );
		$this->assertSame( '9223372036854775807', $json['info'][0]['originalTransactionId'] );
		$this->assertSame( '2026111900000000999', $json['info'][0]['refundList'][0]['refundTransactionId'] );
		$this->assertSame( 1000, $json['info'][0]['payInfo'][0]['amount'] );
		$this->assertSame( -300, $json['info'][0]['refundList'][0]['refundAmount'] );
	}

	/**
	 * Edge：非 JSON（維護頁 / WAF HTML）→ null
	 *
	 * @group edge
	 */
	public function test_非JSON回傳null(): void {
		$this->assertNull( LinepaySignature::decode_json( '<html>maintenance</html>' ) );
		$this->assertNull( LinepaySignature::decode_json( '' ) );
	}
}
