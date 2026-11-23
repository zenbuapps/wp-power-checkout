<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Managers;

use J7\PowerCheckout\Domains\Payment\Linepay\Http\LinepayApiClient;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayConfirmResult;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayReturnCode;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Exceptions\LinepayApiException;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;

/**
 * LINE Pay 付款認列（confirmUrl / AS 補單 / 後台查詢補單共用，冪等）
 *
 *  - confirm()：以「我方金額快照」呼叫 confirm
 *      0000 → settle()；1172 / 1198 → settle_from_details()（重複 confirm，查明細確認）；
 *      1180 → 逾期 note（EXPIRED）；其他碼 → 失敗 note（FAILED）；傳輸失敗向上拋（呼叫端決定是否重試）
 *  - settle_from_details()：查明細，只有 transactionType=PAYMENT 且 payStatus=CAPTURE 才認列
 *  - settle()：守衛 orderId 與實收金額 = 快照（payInfo 加總；無 payInfo 退回 packages 加總；兩者皆無 → fail closed 不認列）；
 *    通過才 payment_complete(transactionId)
 *  - 已付款 / 已退款 / 已取消的訂單不再認列，也不打 API（冪等）
 *
 * ⚠️ 不可拿 confirm 當狀態探針：顧客未認證就 confirm 會 1169 且整筆作廢——補單路徑必須先查狀態（見 LinepayReconcileJob）。
 */
final class StatusManager {

	/**
	 * Constructor
	 *
	 * @param \WC_Order        $order  訂單
	 * @param LinepayApiClient $client API client
	 */
	public function __construct(
		private readonly \WC_Order $order,
		private readonly LinepayApiClient $client
	) {
	}

	/**
	 * 訂單是否已結案（已付款 / 已退款 / 已取消），不應再認列
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function is_settled( \WC_Order $order ): bool {
		return $order->is_paid() || $order->has_status( [ 'refunded', 'cancelled' ] );
	}

	/**
	 * 呼叫 confirm 並認列
	 *
	 * @param string $source 來源（order note 用）
	 * @return LinepayConfirmResult
	 * @throws LinepayApiException 傳輸失敗 / 回應格式錯誤
	 */
	public function confirm( string $source ): LinepayConfirmResult {
		if ( self::is_settled( $this->order ) ) {
			return LinepayConfirmResult::PAID;
		}

		$meta     = new LinepayMetaKeys( $this->order );
		$tx       = $meta->get_transaction_id();
		$response = $this->client->confirm( $tx, $meta->get_charge_amount() );
		$code     = LinepayApiClient::return_code( $response );
		$enum     = LinepayReturnCode::tryFrom( $code );

		if ( LinepayReturnCode::SUCCESS === $enum ) {
			$info = \is_array( $response['info'] ?? null ) ? $response['info'] : [];
			/** @var array<string, mixed> $info */
			return $this->settle( $info, $source );
		}

		if ( null !== $enum && $enum->means_already_confirmed() ) {
			return $this->settle_from_details( $source );
		}

		$message = LinepayApiClient::return_message( $response );
		if ( LinepayReturnCode::PAYMENT_EXPIRED === $enum ) {
			$this->order->add_order_note(
				\sprintf(
					/* translators: 1: 來源 2: returnCode 3: 訊息 4: transactionId */
					\__( 'LINE Pay %1$s：付款請求已逾期（%2$s %3$s），訂單保持待付款，顧客需重新付款。transactionId %4$s', 'power_checkout' ),
					$source,
					$code,
					$message,
					$tx
				)
			);
			return LinepayConfirmResult::EXPIRED;
		}

		$this->order->add_order_note(
			\sprintf(
				/* translators: 1: 來源 2: returnCode 3: 訊息 4: transactionId */
				\__( '❌ LINE Pay %1$s：付款授權失敗（%2$s %3$s），訂單保持待付款。transactionId %4$s', 'power_checkout' ),
				$source,
				$code,
				$message,
				$tx
			)
		);
		return LinepayConfirmResult::FAILED;
	}

	/**
	 * 查付款明細，確認已請款才認列（重複 confirm / 狀態 0123 時用）
	 *
	 * @param string $source 來源
	 * @return LinepayConfirmResult
	 * @throws LinepayApiException 傳輸失敗 / 明細查詢失敗
	 */
	public function settle_from_details( string $source ): LinepayConfirmResult {
		if ( self::is_settled( $this->order ) ) {
			return LinepayConfirmResult::PAID;
		}

		$detail = $this->find_payment_detail();
		if ( null === $detail || 'CAPTURE' !== ( $detail['payStatus'] ?? null ) ) {
			return LinepayConfirmResult::NOT_PAID;
		}
		return $this->settle( $detail, $source );
	}

	/**
	 * 查付款明細中本訂單目前 transactionId 的付款交易
	 *
	 * @return array<string, mixed>|null 查無（1150 / 無此筆）回 null
	 * @throws LinepayApiException 傳輸失敗 / 其他錯誤碼
	 */
	public function find_payment_detail(): ?array {
		$tx       = ( new LinepayMetaKeys( $this->order ) )->get_transaction_id();
		$response = $this->client->details( $tx );
		$code     = LinepayApiClient::return_code( $response );

		if ( LinepayReturnCode::TRANSACTION_NOT_FOUND->value === $code ) {
			return null;
		}
		if ( LinepayReturnCode::SUCCESS->value !== $code ) {
			throw new LinepayApiException(
				"LINE Pay 查詢付款明細失敗：{$code} " . LinepayApiClient::return_message( $response ),
				LinepayReturnCode::error_code_for( $code ),
				$code
			);
		}

		$rows = \is_array( $response['info'] ?? null ) ? $response['info'] : [];
		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}
			$row_tx = \is_scalar( $row['transactionId'] ?? null ) ? (string) $row['transactionId'] : '';
			$type   = \is_string( $row['transactionType'] ?? null ) ? $row['transactionType'] : 'PAYMENT';
			if ( $row_tx === $tx && 'PAYMENT' === $type ) {
				/** @var array<string, mixed> $row */
				return $row;
			}
		}
		return null;
	}

	/**
	 * 認列付款（orderId / 金額守衛）
	 *
	 * @param array<string, mixed> $info   confirm info 或付款明細
	 * @param string               $source 來源
	 * @return LinepayConfirmResult
	 */
	private function settle( array $info, string $source ): LinepayConfirmResult {
		if ( self::is_settled( $this->order ) ) {
			return LinepayConfirmResult::PAID;
		}

		$meta     = new LinepayMetaKeys( $this->order );
		$tx       = $meta->get_transaction_id();
		$expected = $meta->get_charge_amount();
		$order_id = \is_scalar( $info['orderId'] ?? null ) ? (string) $info['orderId'] : '';
		$paid     = self::sum_paid( $info );

		if ( null === $paid ) {
			// fail closed：payInfo / packages 都取不到金額時不認列（LINE Pay 規格兩者恆存在，缺漏代表回應異常）
			$this->order->add_order_note(
				\sprintf(
					/* translators: 1: 來源 2: transactionId 3: 應收 */
					\__( '⚠️ LINE Pay %1$s：回應缺少 payInfo / packages 金額，無法確認金額，未自動完成訂單，請人工確認。transactionId %2$s，應收 %3$d 元', 'power_checkout' ),
					$source,
					$tx,
					$expected
				)
			);
			return LinepayConfirmResult::MISMATCH;
		}

		if ( ( '' !== $order_id && $order_id !== $meta->get_linepay_order_id() ) || $paid !== $expected ) {
			$this->order->add_order_note(
				\sprintf(
					/* translators: 1: 來源 2: transactionId 3: 應收 4: 實收 */
					\__( '⚠️ LINE Pay %1$s：orderId 或金額不符，未自動完成訂單，請人工確認。transactionId %2$s，應收 %3$d 元，LINE Pay 回報 %4$s 元', 'power_checkout' ),
					$source,
					$tx,
					$expected,
					(string) $paid
				)
			);
			return LinepayConfirmResult::MISMATCH;
		}

		$meta->update_payment_detail( $info );
		$this->order->payment_complete( $tx );
		$this->order->add_order_note(
			\sprintf(
				/* translators: 1: 來源 2: transactionId 3: 金額 4: 付款方式 */
				\__( '✅ LINE Pay 付款成功（%1$s）。transactionId %2$s，金額 %3$d 元，付款方式 %4$s', 'power_checkout' ),
				$source,
				$tx,
				$expected,
				self::pay_methods( $info )
			)
		);
		return LinepayConfirmResult::PAID;
	}

	/**
	 * 實收金額：payInfo 金額加總（信用卡 + 點數 + 餘額）；無 payInfo 金額時退回 packages 金額加總；兩者皆無回 null
	 *
	 * @param array<string, mixed> $info info
	 * @return int|null null = 無法確定金額（呼叫端 fail closed）
	 */
	private static function sum_paid( array $info ): ?int {
		return self::sum_amounts( $info['payInfo'] ?? null ) ?? self::sum_amounts( $info['packages'] ?? null );
	}

	/**
	 * 陣列各列 amount 加總
	 *
	 * @param mixed $rows payInfo / packages
	 * @return int|null 沒有任何數值 amount 時回 null
	 */
	private static function sum_amounts( mixed $rows ): ?int {
		if ( ! \is_array( $rows ) ) {
			return null;
		}
		$sum   = 0;
		$found = false;
		foreach ( $rows as $row ) {
			if ( \is_array( $row ) && \is_numeric( $row['amount'] ?? null ) ) {
				$sum  += (int) \round( (float) $row['amount'] );
				$found = true;
			}
		}
		return $found ? $sum : null;
	}

	/**
	 * 付款方式摘要（BALANCE / CREDIT_CARD / POINT）
	 *
	 * @param array<string, mixed> $info info
	 * @return string
	 */
	private static function pay_methods( array $info ): string {
		$methods = [];
		foreach ( \is_array( $info['payInfo'] ?? null ) ? $info['payInfo'] : [] as $row ) {
			if ( \is_array( $row ) && \is_string( $row['method'] ?? null ) ) {
				$methods[] = $row['method'];
			}
		}
		return [] === $methods ? '-' : \implode( ' + ', $methods );
	}
}
