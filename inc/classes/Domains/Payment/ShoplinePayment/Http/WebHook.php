<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\OrderResolver;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\StatusSource;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Exceptions\SignatureException;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;

/**
 * WebHooks 用來接收 Shopline 的 WebHooks 通知
 * session.succeeded 將訂單轉為處理中
 *
 * @see https://docs.shoplinepayments.com/api/event/model/session/
 */
final class WebHook extends ApiBase {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string log 用：尚未驗簽（驗簽前就失敗，例如 timestamp 超出容差） */
	private const VERIFICATION_NOT_VERIFIED = 'not_verified';

	/** @var string log 用：驗簽通過 */
	private const VERIFICATION_VERIFIED = 'verified';

	/** @var string log 用：本地環境略過驗簽 */
	private const VERIFICATION_SKIPPED_LOCAL = 'skipped_local_env';

	/** @var string Namespace power-checkout/{payment_gateway} */
	protected $namespace = 'power-checkout/slp';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> $apis API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'webhook',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];


	/**
	 * 結帳交易 WebHooks 通知
	 *
	 * 回應碼判準只有一個：「同一份 payload 之後有沒有可能成功？」
	 *  - 驗簽不符 → 401。極可能是商家 signKey 設定錯誤，回 200 會讓 SLP 永久放棄這筆通知，
	 *    商家改好設定也救不回來；回 401 保留重送機會，且不洩漏任何資訊。
	 *  - 其餘（timestamp 超時 / DTO 解析失敗 / 找不到訂單 / 業務例外 / 正常完成）→ 一律 200。
	 *    重送不會讓結果改變，回 500 只會造成 SLP 無限重試。
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 * @see specs/open-issue/issue-18-plan.md §決策 4
	 */
	public function post_webhook_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_params();

		/*
		* 真實的驗簽結果，供 log 判讀。
		* 舊版把驗簽結果賦值給從未使用的 $is_valid，且 log 一律寫死 'is_valid' => 'true'，
		* 不論驗簽是否通過都印 true，等於假的可觀測性。
		*/
		$sign_verification = self::VERIFICATION_NOT_VERIFIED;

		try {
			$this->assert_valid($request);
			$sign_verification = ( 'local' === Plugin::$env ) ? self::VERIFICATION_SKIPPED_LOCAL : self::VERIFICATION_VERIFIED;

			$webhook_dto = Body::create($body_params);

			$webhook_data_dto = $webhook_dto->data;

			// 處理退款
			if ($webhook_data_dto instanceof Webhooks\Refund) {
				$this->handle_refund($webhook_data_dto);
			}

			if ($webhook_data_dto instanceof Webhooks\Payment && $webhook_data_dto->is_successed_or_failed()) {
				$order = OrderResolver::resolve( $webhook_data_dto->tradeOrderId, $webhook_data_dto->referenceOrderId );

				if (!$order) {
					throw new \Exception("找不到訂單，tradeOrderId: {$webhook_data_dto->tradeOrderId}, referenceOrderId: {$webhook_data_dto->referenceOrderId}");
				}

				$status_manager = new StatusManager($webhook_data_dto, $order, StatusSource::WEBHOOK);
				$status_manager->update_order_status();
			}

			// 收到通知就始終回 200 ，不用讓 SLP 重試
			return new \WP_REST_Response(null, 200);
		} catch (SignatureException $e) {
			// 驗簽不符：保留 SLP 重送機會（商家可能只是 signKey 填錯），回應不洩漏任何資訊
			Plugin::logger(
				'WebHook 驗簽失敗',
				'error',
				[
					'error'             => $e->getMessage(),
					'sign_verification' => $sign_verification,
					'params'            => $body_params,
				]
			);
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_signature',
					'message' => 'Invalid signature',
					'data'    => null,
				],
				401
			);
		} catch (\Throwable $e) {
			Plugin::logger(
				'WebHook 處理失敗',
				'error',
				[
					'error'             => $e->getMessage(),
					'sign_verification' => $sign_verification,
					'params'            => $body_params,
				]
			);
			// 收到通知就始終回 200 ，不用讓 SLP 重試
			return new \WP_REST_Response(
				[
					'code'    => 'mapping_order_failed',
					'message' => $e->getMessage(),
					'data'    => null,
				],
				200
			);
		}
	}

	// region 驗證有效性


	/**
	 * 驗證通知有效性（timestamp 容差 + 簽章）
	 *
	 * 驗證失敗一律 throw，成功則靜默返回。
	 * 兩種失敗的例外型別刻意不同，讓呼叫端能對應到不同的 HTTP 回應碼。
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return void
	 * @throws \Exception 簽章不符時拋 SignatureException（可重送）；timestamp 超出容差時拋 \Exception（重送必然也失敗）
	 */
	private function assert_valid( \WP_REST_Request $request ): void {
		if ('local' === Plugin::$env) {
			// 本地環境不驗證簽章
			return;
		}

		// 容許的時間誤差
		$diff_tolerance = 5 * 60 * 1000; // 300 seconds = 5 mins
		$timestamp      = (int) $request->get_header('timestamp');
		$current_time   = \time() * 1000;
		$diff_time      = \abs($current_time - $timestamp);
		if ($diff_time > $diff_tolerance) {
			throw new \Exception(
				"Invalid timestamp, current: {$current_time}, received: {$timestamp}, diff: {$diff_time}"
			);
		}

		$this->verify_hmac_sha256_signature($request);
	}

	/**
	 * 驗證簽章
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return void
	 * @throws SignatureException 如果簽章驗證失敗
	 */
	private function verify_hmac_sha256_signature( \WP_REST_Request $request ): void {
		$timestamp            = (string) $request->get_header('timestamp');
		$payload              = "{$timestamp}.{$request->get_body()}";
		$calculated_signature = $this->generate_hmac_sha256_signature($payload);
		$sign                 = (string) $request->get_header('sign');
		$is_verified          = \hash_equals($sign, $calculated_signature);
		if (!$is_verified) {
			throw new SignatureException("Invalid sign, calculated: {$calculated_signature}, actual: {$sign}");
		}
	}

	/**
	 * 使用 hash_hmac 函數生成 HMAC-SHA256 簽章
	 *
	 * @param string $payload 要簽名的字串
	 *
	 * @return string 簽章
	 */
	private function generate_hmac_sha256_signature( string $payload ): string {
		// 確保資料是 UTF-8 編碼
		$converted = mb_convert_encoding($payload, 'UTF-8', 'auto');
		$payload   = \is_string($converted) ? $converted : $payload;
		$sign_key  = ( RedirectSettingsDTO::instance() )->signKey;
		return hash_hmac('sha256', $payload, $sign_key);
	}

	// endregion


	/** @return string 取得 webhook url */
	public static function get_webhook_url(): string {
		return \get_rest_url(null, 'power-checkout/slp/webhook');
	}

	/**
	 * 處理退款資訊
	 *
	 * @param Webhooks\Refund $refund_dto 退款通知 DTO
	 *
	 * @return void
	 * @throws \Exception 如果找不到訂單
	 */
	private function handle_refund( Webhooks\Refund $refund_dto ): void {
		$order = OrderResolver::resolve( $refund_dto->tradeOrderId, $refund_dto->referenceOrderId );
		if (!$order) {
			throw new \Exception("找不到訂單，tradeOrderId: {$refund_dto->tradeOrderId}, referenceOrderId: {$refund_dto->referenceOrderId}");
		}

		// 如果 webhook 通知退款失敗
		if ($refund_dto->status === ResponseStatus::FAILED->value) {
			$refunds       = $order->get_refunds();
			$latest_refund = \reset($refunds);
			if ($latest_refund instanceof \WC_Order_Refund) {
				$latest_refund->delete(true);
			}

			return;
		}

		$reason = (string) $order->get_meta('tmp_refund_reason');
		$order->delete_meta_data('tmp_refund_reason');

		RedirectGateway::handle_refund_response($refund_dto, $order, $reason);
	}
}
