<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Http;

use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\LinepaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Exceptions\LinepayApiException;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepaySignature;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * LINE Pay Online API v4 client（直連，商家自有 Channel ID / Secret）
 *
 * - Header：Content-Type / X-LINE-ChannelId / X-LINE-Authorization-Nonce（UUID v4）/ X-LINE-Authorization（HMAC-SHA256 Base64）
 * - body 序列化一次，同一字串簽章並送出（重新序列化會 1106）；無 body 的 POST 簽空字串、不送 body
 * - HTTP 一律 200，成敗只看 returnCode：本 client **不**對非 0000 拋例外，原樣回給呼叫端依碼分流；
 *   只有傳輸層失敗（逾時 / 連線）與非 JSON 回應才拋 LinepayApiException
 * - 回應以 LinepaySignature::decode_json() 解碼，19 位 transactionId 一律為字串（R4）
 * - 錯誤訊息只帶 HTTP 狀態，絕不含 Channel Secret / 簽章
 *
 * @see .claude/skills/linepay/references/authentication.md
 */
final class LinepayApiClient {

	/** @var string API 版本前綴 */
	private const VERSION = '/v4';

	/** @var array<string, int> 各端點 read timeout（秒；官方建議 request ≥10、confirm ≥40、其餘 ≥20） */
	private const TIMEOUTS = [
		'request' => 15,
		'check'   => 20,
		'confirm' => 45,
		'details' => 20,
		'refund'  => 30,
	];

	/**
	 * Constructor
	 *
	 * @param LinepaySettingsDTO $settings 設定
	 */
	public function __construct( private readonly LinepaySettingsDTO $settings ) {
	}

	/**
	 * 付款請求
	 *
	 * @param array<string, mixed> $payload request body
	 * @return array<string, mixed> 回應
	 * @throws LinepayApiException 傳輸失敗 / 非 JSON / 憑證未設定
	 */
	public function request( array $payload ): array {
		return $this->call( 'POST', self::VERSION . '/payments/request', 'request', $payload );
	}

	/**
	 * 查詢付款請求狀態（0000 未認證 / 0110 可 confirm / 0121 取消逾時 / 0122 失敗 / 0123 完成）
	 *
	 * @param string $transaction_id transactionId
	 * @return array<string, mixed>
	 * @throws LinepayApiException 傳輸失敗
	 */
	public function check_request_status( string $transaction_id ): array {
		return $this->call( 'GET', self::VERSION . '/payments/requests/' . self::path_id( $transaction_id ) . '/check', 'check' );
	}

	/**
	 * 付款授權（自動請款）
	 *
	 * @param string $transaction_id transactionId
	 * @param int    $amount         金額（取自我方訂單快照）
	 * @return array<string, mixed>
	 * @throws LinepayApiException 傳輸失敗
	 */
	public function confirm( string $transaction_id, int $amount ): array {
		return $this->call(
			'POST',
			self::VERSION . '/payments/' . self::path_id( $transaction_id ) . '/confirm',
			'confirm',
			[
				'amount'   => $amount,
				'currency' => 'TWD',
			]
		);
	}

	/**
	 * 查詢付款明細（以 transactionId）
	 *
	 * @param string $transaction_id transactionId
	 * @return array<string, mixed>
	 * @throws LinepayApiException 傳輸失敗
	 */
	public function details( string $transaction_id ): array {
		return $this->call( 'GET', self::VERSION . '/payments', 'details', null, 'transactionId=' . \rawurlencode( $transaction_id ) );
	}

	/**
	 * 退款（一律帶明確金額）
	 *
	 * @param string $transaction_id 原付款 transactionId
	 * @param int    $amount         退款金額
	 * @return array<string, mixed>
	 * @throws LinepayApiException 傳輸失敗
	 */
	public function refund( string $transaction_id, int $amount ): array {
		return $this->call(
			'POST',
			self::VERSION . '/payments/' . self::path_id( $transaction_id ) . '/refund',
			'refund',
			[ 'refundAmount' => $amount ]
		);
	}

	/**
	 * 取回應的 returnCode（缺值為空字串）
	 *
	 * @param array<string, mixed> $response 回應
	 * @return string
	 */
	public static function return_code( array $response ): string {
		return \is_scalar( $response['returnCode'] ?? null ) ? (string) $response['returnCode'] : '';
	}

	/**
	 * 取回應的 returnMessage（缺值為空字串）
	 *
	 * @param array<string, mixed> $response 回應
	 * @return string
	 */
	public static function return_message( array $response ): string {
		return \is_scalar( $response['returnMessage'] ?? null ) ? (string) $response['returnMessage'] : '';
	}

	/**
	 * 送出已簽章請求
	 *
	 * @param string                    $method   GET|POST
	 * @param string                    $api_path 含版本的路徑
	 * @param string                    $endpoint 端點名稱（決定逾時）
	 * @param array<string, mixed>|null $body     POST body（null 不送 body、簽空字串）
	 * @param string                    $query    GET query（不含 ?）
	 * @return array<string, mixed>
	 * @throws LinepayApiException 憑證未設定（AUTH）/ 傳輸失敗（NETWORK）/ 非 JSON（PROVIDER）
	 */
	private function call( string $method, string $api_path, string $endpoint, ?array $body = null, string $query = '' ): array {
		if ( ! $this->settings->has_credentials() ) {
			throw new LinepayApiException( 'LINE Pay 尚未設定 Channel ID / Channel Secret', ErrorCode::AUTH );
		}

		$body_text = 'POST' === $method && null !== $body
		? (string) \wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		: '';
		$nonce     = LinepaySignature::nonce();
		$args      = [
			'method'  => $method,
			'timeout' => self::TIMEOUTS[ $endpoint ] ?? 20,
			'headers' => [
				'Content-Type'               => 'application/json',
				'X-LINE-ChannelId'           => $this->settings->channel_id,
				'X-LINE-Authorization-Nonce' => $nonce,
				'X-LINE-Authorization'       => LinepaySignature::sign(
					$this->settings->channel_secret,
					$api_path,
					'GET' === $method ? $query : $body_text,
					$nonce
				),
			],
		];
		if ( '' !== $body_text ) {
			$args['body'] = $body_text;
		}

		$url      = $this->settings->get_api_base() . $api_path . ( '' !== $query ? "?{$query}" : '' );
		$response = \wp_remote_request( $url, $args );
		if ( \is_wp_error( $response ) ) {
			throw new LinepayApiException( 'LINE Pay 連線失敗：' . $response->get_error_message(), ErrorCode::NETWORK );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$json = LinepaySignature::decode_json( (string) \wp_remote_retrieve_body( $response ) );
		if ( null === $json || '' === self::return_code( $json ) ) {
			// 維護頁 / WAF 會回 HTML：不把第三方內容帶進訊息
			throw new LinepayApiException( "LINE Pay 回應格式錯誤（HTTP {$code}）", ErrorCode::PROVIDER );
		}

		return $json;
	}

	/**
	 * 路徑上的交易 ID（只允許數字，避免路徑注入）
	 *
	 * @param string $transaction_id transactionId
	 * @return string
	 * @throws LinepayApiException 格式錯誤
	 */
	private static function path_id( string $transaction_id ): string {
		if ( 1 !== \preg_match( '/^\d{1,32}$/', $transaction_id ) ) {
			throw new LinepayApiException( 'LINE Pay transactionId 格式錯誤', ErrorCode::VALIDATION );
		}
		return $transaction_id;
	}
}
