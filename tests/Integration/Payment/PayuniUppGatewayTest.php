<?php
/**
 * PAYUNi UPP V2 Gateway 整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway
 *
 * 規格依據：
 *   - specs/features/payment/payuni-upp-checkout.feature
 *   - payuni-upp-v2 SKILL.md §外層請求格式 / §端點
 *   - inc/classes/Domains/Payment/Shared/Abstracts/AbstractPaymentGateway.php
 *   - inc/classes/Domains/Payment/Shared/Interfaces/IPaymentProvider.php
 *
 * 仿照 MpgRedirectGateway / AioRedirectGateway 的導轉式金流模式：
 *   - before_process_payment() 僅回傳 order-received URL（不發 PAYUNi API）
 *   - before_order_received() 組裝 PayuniRequestParams → render auto-form POST 至 PAYUNi UPP
 *
 * 建立 Gateway 方式：直接 new PayuniUppGateway()（仿 EcpayAioRequestParamsTest / EcpgGatewayTest）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 Gateway 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniUppGatewayTest extends TestCase {

	/** 每次測試前啟用 payuni_upp（test 模式 + 官方測試向量金鑰） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'          => 'yes',
				'mode'             => 'test',
				'merchant_id'      => 'TEST_MER',
				'hash_key'         => '12345678901234567890123456789012',
				'hash_iv'          => '1234567890123456',
				'allowed_payments' => [ 'Credit', 'ATM', 'CVS' ],
			]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立帶有 payuni_upp 付款方式的測試訂單
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_payuni_order( float $total = 1000 ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniSettingsDTO::ID,
				'total'          => $total,
			]
		);
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniUppGateway::ID 常數等於 'payuni_upp'
	 * Provider ID 對齊既有命名慣例 ecpay_aio / newebpay_mpg（已拍板）
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniUppGateway_ID常數等於payuni_upp(): void {
		$this->assertSame( 'payuni_upp', PayuniUppGateway::ID );
	}

	/**
	 * PayuniUppGateway 是 AbstractPaymentGateway 的子類別
	 * 確保所有 AbstractPaymentGateway 的 final method 可用（process_payment / before_page_render）
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniUppGateway繼承AbstractPaymentGateway(): void {
		$gateway = new PayuniUppGateway();
		$this->assertInstanceOf( AbstractPaymentGateway::class, $gateway );
	}

	/**
	 * PayuniUppGateway 實作 IPaymentProvider 介面
	 * 對齊 Phase 1 要求：所有金流 implements IPaymentProvider
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniUppGateway實作IPaymentProvider介面(): void {
		$gateway = new PayuniUppGateway();
		$this->assertInstanceOf( IPaymentProvider::class, $gateway );
	}

	// ========== Happy Path ==========

	/**
	 * process_payment() 回傳 result=success 且含 redirect（order-received URL）
	 * 導轉式金流不在 process_payment 階段呼叫 PAYUNi API；僅回傳重導向 URL
	 * 仿 MpgRedirectGateway / AioRedirectGateway::before_process_payment 行為
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_process_payment_回傳success與redirect_URL(): void {
		$gateway  = new PayuniUppGateway();
		$order    = $this->create_payuni_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
		$this->assertNotEmpty( $result['redirect'] );
	}

	/**
	 * process_payment() 回傳的 redirect URL 包含 order-received（訂單完成頁）
	 * 確保導轉至正確的 WC 結帳頁，再由 before_order_received 觸發 auto-form POST 至 PAYUNi
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_process_payment_redirect_URL指向order_received(): void {
		$gateway  = new PayuniUppGateway();
		$order    = $this->create_payuni_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertStringContainsString( 'order-received', $result['redirect'] );
	}

	/**
	 * get_settings() 回傳非空陣列（靜態方法）
	 * 提供 WC 後台設定頁所需欄位定義
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_get_settings_回傳非空陣列(): void {
		$settings = PayuniUppGateway::get_settings();

		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings );
	}

	/**
	 * get_supported_payment_methods() 回傳 allowed_payments 設定值
	 * 依 IPaymentProvider 介面定義；值來自 PayuniSettingsDTO::allowed_payments
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_get_supported_payment_methods_回傳allowed_payments(): void {
		$gateway = new PayuniUppGateway();
		$methods = $gateway->get_supported_payment_methods();

		$this->assertIsArray( $methods );
		$this->assertNotEmpty( $methods );
		$this->assertContains( 'Credit', $methods );
		$this->assertContains( 'ATM', $methods );
		$this->assertContains( 'CVS', $methods );
	}

	/**
	 * get_supported_payment_methods() 對齊設定，只回傳 allowed_payments 允許的值
	 * 重新設定 allowed_payments=['Credit'] 後，回傳值不含 ATM / CVS
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_get_supported_payment_methods_僅含設定允許的付款方式(): void {
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[ 'allowed_payments' => [ 'Credit' ] ]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}

		$gateway = new PayuniUppGateway();
		$methods = $gateway->get_supported_payment_methods();

		$this->assertContains( 'Credit', $methods );
		$this->assertNotContains( 'ATM', $methods );
		$this->assertNotContains( 'CVS', $methods );
	}

	// ========== 冪等鍵寫入（Happy / Edge） ==========

	/**
	 * process_payment() 只標記「待導轉」，不預寫 MerTradeNo（編號在 order-received 導轉時才產生，每次付款嘗試都換新）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_process_payment只標記待導轉不預寫trade_no(): void {
		$gateway  = new PayuniUppGateway();
		$order    = $this->create_payuni_order();
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$meta_keys = new PayuniMetaKeys( \wc_get_order( $order_id ) );
		$this->assertTrue( $meta_keys->is_redirect_pending(), 'process_payment 後應標記待導轉' );
		$this->assertSame( '', $meta_keys->get_trade_no(), 'process_payment 不應預寫 MerTradeNo' );
	}

	/**
	 * 重複呼叫 process_payment（order-pay 重新付款）只會重新標記待導轉，不會動到既有編號
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_重複呼叫process_payment不更動既有trade_no(): void {
		$gateway  = new PayuniUppGateway();
		$order    = $this->create_payuni_order();
		$order_id = $order->get_id();
		( new PayuniMetaKeys( $order ) )->update_trade_no( 'PCU' . $order_id . '_1700000000' );

		$gateway->process_payment( $order_id );
		$gateway->process_payment( $order_id );

		$meta_keys = new PayuniMetaKeys( \wc_get_order( $order_id ) );
		$this->assertSame( 'PCU' . $order_id . '_1700000000', $meta_keys->get_trade_no() );
		$this->assertTrue( $meta_keys->is_redirect_pending() );
	}

	// ========== Error Handling ==========

	/**
	 * process_payment() 訂單不存在時回傳 failure array（不暴露 raw exception 至前端）
	 *
	 * AbstractPaymentGateway::process_payment（final）全程 try-catch，對無效訂單
	 * catch 後回 ProcessResult::FAILED + wc_add_notice，與既有 4 支金流行為一致
	 * （CLAUDE.md 鐵律：絕不暴露內部錯誤至前端）。
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_process_payment_訂單不存在時回傳失敗結果(): void {
		$gateway = new PayuniUppGateway();

		$result = $gateway->process_payment( 999999999 );
		$this->assertSame( 'failure', $result['result'] );
	}

	// ========== Settings / ID 屬性（Happy） ==========

	/**
	 * Gateway 實例的 $id 屬性等於 PayuniUppGateway::ID 常數
	 * WC_Payment_Gateway 要求 $id 屬性（非靜態）與 ID 常數一致
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_實例_id屬性等於ID常數(): void {
		$gateway = new PayuniUppGateway();
		$this->assertSame( PayuniUppGateway::ID, $gateway->id );
	}

	/**
	 * Gateway 在 test 模式下 title / description 非空
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_實例_title與description非空(): void {
		$gateway = new PayuniUppGateway();
		$this->assertNotEmpty( $gateway->title );
	}
}
