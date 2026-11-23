<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Http;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions\TcatPayApiException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 黑貓PAY HTTP client（取 Token + `/api/Collect` 以 cmd 分派）
 *
 * - Token：`POST {base}/Token`（**form-urlencoded**，唯一不用 JSON / 不帶 Bearer 的端點），
 *   transient 快取至 `.expires` − 60 秒（取不到時保守 30 分鐘；文件對有效期自相矛盾）。
 * - `/api/Collect`：JSON + `Authorization: Bearer {token}`（Bearer 與 token 間有空格）；cust_id 由 client 補上
 *   （必須等於 token username，否則平台回「cust_id(X)與 token(Y)不匹配」）。
 * - HTTP 401（sandbox 實測 body `{"Message":"Authorization has been denied for this request."}`）
 *   → 清快取重取 token 1 次再送；仍 401 → AUTH。
 * - 業務錯誤是 HTTP 200 + `status:"ERROR"` + `msg`（不是 4xx）→ PROVIDER（含「不匹配」→ AUTH）。
 * - 錯誤訊息只帶 HTTP 狀態與平台 msg，絕不含 api_password / hash_base / token。
 *
 * @see .claude/skills/tcat-pay/SKILL.md §傳輸與格式
 * @see zenbu payments/kolet/kolet-client.ts
 */
final class TcatPayApiClient {

	public const CMD_CVS_ORDER_APPEND  = 'CvsOrderAppend';
	public const CMD_CVS_ORDER_QUERY   = 'CvsOrderQuery';
	public const CMD_COCS_ORDER_APPEND = 'CocsOrderAppend';
	public const CMD_COCS_ORDER_QUERY  = 'CocsOrderQuery';
	public const CMD_COCS_ORDER_CANCEL = 'CocsOrderCancel';
	public const CMD_COCS_CASH_REQUEST = 'CocsCashRequest';
	public const CMD_COCS_ORDER_REFUND = 'CocsOrderRefund';

	/** @var int HTTP 逾時秒數（取號 / 建單在結帳同步路徑） */
	private const TIMEOUT = 15;

	/** @var int token 到期安全邊際（秒） */
	private const EXPIRY_SKEW = 60;

	/** @var int 取不到到期時間時的保守存活（秒） */
	private const FALLBACK_TTL = 1800;

	/**
	 * Constructor
	 *
	 * @param TcatPaySettingsDTO $settings 設定
	 */
	public function __construct( private readonly TcatPaySettingsDTO $settings ) {
	}

	/**
	 * 取得 token（快取命中不打網路）
	 *
	 * @return string
	 * @throws TcatPayApiException 憑證未設定 / 帳密錯（AUTH）、連線失敗（NETWORK）
	 */
	public function get_token(): string {
		if ( ! $this->settings->has_credentials() ) {
			throw new TcatPayApiException( '黑貓PAY 尚未設定金流代號或 API 密碼', ErrorCode::AUTH );
		}

		$cached = \get_transient( $this->token_cache_key() );
		if ( \is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		[ $code, $json ] = $this->send(
			"{$this->settings->get_api_base()}/Token",
			[ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			\http_build_query(
				[
					'grant_type' => 'password',
					'username'   => $this->settings->cust_id,
					'password'   => $this->settings->api_password,
				]
			)
		);

		$token = \is_string( $json['access_token'] ?? null ) ? $json['access_token'] : '';
		if ( '' === $token ) {
			$reason = \is_string( $json['error_description'] ?? null ) ? $json['error_description'] : "HTTP {$code}";
			throw new TcatPayApiException( "黑貓PAY token 取得失敗：{$reason}", ErrorCode::AUTH );
		}

		\set_transient( $this->token_cache_key(), $token, $this->resolve_ttl( $json ) );
		return $token;
	}

	/** @return void 清除 token 快取 */
	public function clear_token_cache(): void {
		\delete_transient( $this->token_cache_key() );
	}

	/**
	 * 呼叫 `/api/Collect`
	 *
	 * @param string               $cmd     cmd
	 * @param array<string, mixed> $payload body（cmd / cust_id 由 client 補）
	 * @return array<string, mixed> 平台回應（status=OK）
	 * @throws TcatPayApiException 失敗
	 */
	public function collect( string $cmd, array $payload ): array {
		$body = (string) \wp_json_encode(
			\array_merge(
				[
					'cmd'     => $cmd,
					'cust_id' => $this->settings->cust_id,
				],
				$payload
			),
			JSON_UNESCAPED_UNICODE
		);

		[ $code, $json ] = $this->post_collect( $body );
		if ( 401 === $code ) {
			$this->clear_token_cache();
			[ $code, $json ] = $this->post_collect( $body );
			if ( 401 === $code ) {
				throw new TcatPayApiException( "黑貓PAY {$cmd} 授權失敗（HTTP 401）", ErrorCode::AUTH );
			}
		}

		if ( 'OK' !== \strtoupper( \is_string( $json['status'] ?? null ) ? $json['status'] : '' ) ) {
			$msg = \is_string( $json['msg'] ?? null ) && '' !== \trim( $json['msg'] ) ? \trim( $json['msg'] ) : "HTTP {$code}";
			throw new TcatPayApiException(
				"黑貓PAY {$cmd} 失敗：{$msg}",
				\str_contains( $msg, '不匹配' ) ? ErrorCode::AUTH : ErrorCode::PROVIDER
			);
		}

		return $json;
	}

	/**
	 * 帶 token 送出 Collect
	 *
	 * @param string $body JSON body
	 * @return array{0: int, 1: array<string, mixed>}
	 * @throws TcatPayApiException 失敗
	 */
	private function post_collect( string $body ): array {
		return $this->send(
			"{$this->settings->get_api_base()}/api/Collect",
			[
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->get_token(),
			],
			$body
		);
	}

	/**
	 * 送出請求並解析 JSON（401 原樣回給呼叫端判斷）
	 *
	 * @param string                $url     URL
	 * @param array<string, string> $headers headers
	 * @param string                $body    body
	 * @return array{0: int, 1: array<string, mixed>} [HTTP code, JSON]
	 * @throws TcatPayApiException 連線失敗（NETWORK）、非 JSON（PROVIDER）
	 */
	private function send( string $url, array $headers, string $body ): array {
		$response = \wp_remote_post(
			$url,
			[
				'headers' => $headers,
				'body'    => $body,
				'timeout' => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			throw new TcatPayApiException( '黑貓PAY 連線失敗：' . $response->get_error_message(), ErrorCode::NETWORK );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		$json = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $json ) ) {
			// 維護頁 / WAF 會回 HTML：不把第三方內容帶進訊息
			throw new TcatPayApiException( "黑貓PAY 回應非 JSON（HTTP {$code}），可能為維護頁或憑證問題", ErrorCode::PROVIDER );
		}

		/** @var array<string, mixed> $json */
		return [ $code, $json ];
	}

	/**
	 * Token 快取秒數：`.expires`（GMT）優先 → expires_in → 保守 30 分鐘，皆扣安全邊際，至少 60 秒
	 *
	 * @param array<string, mixed> $json token 回應
	 * @return int
	 */
	private function resolve_ttl( array $json ): int {
		$expires = \is_string( $json['.expires'] ?? null ) ? \strtotime( $json['.expires'] ) : false;
		if ( false !== $expires && $expires > \time() ) {
			$ttl = $expires - \time();
		} elseif ( \is_numeric( $json['expires_in'] ?? null ) && (int) $json['expires_in'] > 0 ) {
			$ttl = (int) $json['expires_in'];
		} else {
			$ttl = self::FALLBACK_TTL;
		}
		return \max( $ttl - self::EXPIRY_SKEW, 60 );
	}

	/** @return string token transient key（依環境 + 金流代號區隔） */
	private function token_cache_key(): string {
		return 'pc_tcat_pay_token_' . \md5( $this->settings->get_api_base() . '|' . $this->settings->cust_id );
	}
}
