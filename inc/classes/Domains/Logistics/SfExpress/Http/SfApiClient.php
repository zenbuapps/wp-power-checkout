<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Http;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfAccessTokenStore;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfCrypto;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 順豐國際開放平台 dispatch client（單一入口，header msgType 分派業務接口）
 *
 * 流程：取 token（SfAccessTokenStore）→ JSON（UNESCAPED_UNICODE；空 body = `{}`）→ AES 加密 → signature →
 * `POST {base}/openapi/api/dispatch`（body = 密文字串本身；Content-Type 必須 application/json）→
 * 兩層判斷：apiResultCode=0 → 解密 apiResultData → success=true 才回傳業務 JSON。
 *
 * - token invalid（500 + Access denied, token invalid）→ 清快取、重取 1 次後重送；仍失敗 → AUTH
 * - 其他平台錯誤 / 業務錯誤 → SfExpressException（帶 raw_code）；傳輸層（WP_Error / 非 2xx / 非 JSON）→ NETWORK
 * - lang 用 zh-HK（繁體訊息；順豐沒有 zh-TW）
 *
 * @see .claude/skills/sf-express/references/auth-and-crypto.md §0.3–§0.6
 * @see .claude/skills/sf-express/references/php-client.md §3
 */
final class SfApiClient {

	/** @var int dispatch 逾時秒數 */
	private const TIMEOUT = 30;

	/** @var array<string, string> msgType → 中文動作名（錯誤訊息用） */
	private const ACTIONS = [
		'IUOP_CREATE_ORDER' => '建單',
		'IUOP_QUERY_ORDER'  => '查單',
		'IUOP_CANCEL_ORDER' => '取消',
		'IUOP_PRINT_ORDER'  => '面單取得',
		'IUOP_QUERY_TRACK'  => '查詢軌跡',
	];

	/** @var SfAccessTokenStore token 快取 */
	private readonly SfAccessTokenStore $tokens;

	/**
	 * Constructor
	 *
	 * @param SfExpressSettingsDTO    $settings 設定
	 * @param SfAccessTokenStore|null $tokens   token 快取（null = 依設定建立）
	 */
	public function __construct( private readonly SfExpressSettingsDTO $settings, ?SfAccessTokenStore $tokens = null ) {
		$this->tokens = $tokens ?? new SfAccessTokenStore( $settings );
	}

	/**
	 * 呼叫業務接口
	 *
	 * @param string               $msg_type 接口編碼（如 IUOP_CREATE_ORDER）
	 * @param array<string, mixed> $body     明文業務 body
	 * @return array<string, mixed> 解密後業務 JSON（success=true）
	 * @throws SfExpressException VALIDATION 憑證缺漏 / AUTH / SIGNATURE / NETWORK / CONFLICT / NOT_FOUND / PROVIDER
	 */
	public function dispatch( string $msg_type, array $body ): array {
		$missing = $this->settings->missing_api_credentials();
		if ([] !== $missing) {
			throw SfExpressException::validation( '請先於順豐設定頁填寫：' . \implode( '、', $missing ) );
		}

		$crypto = new SfCrypto( $this->settings->app_key, $this->settings->encoding_aes_key );
		$action = self::ACTIONS[ $msg_type ] ?? $msg_type;
		$plain  = (string) \wp_json_encode( [] === $body ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		try {
			return $this->attempt( $crypto, $msg_type, $action, $plain, $this->tokens->get() );
		} catch ( SfExpressException $e ) {
			if (!$this->is_token_invalid( $e )) {
				throw $e;
			}
			// 另一程序剛換票、本程序的快取還沒跟上 → 清快取重取一次即可；仍失效就交給站主（AUTH）
			$this->tokens->invalidate();
			try {
				return $this->attempt( $crypto, $msg_type, $action, $plain, $this->tokens->refresh() );
			} catch ( SfExpressException $retry ) {
				if ($this->is_token_invalid( $retry )) {
					$this->tokens->invalidate();
				}
				throw $retry;
			}
		}
	}

	/**
	 * 以指定 token 送出一次
	 *
	 * @param SfCrypto $crypto   加解密
	 * @param string   $msg_type 接口編碼
	 * @param string   $action   中文動作名
	 * @param string   $plain    明文 JSON
	 * @param string   $token    Access Token
	 * @return array<string, mixed>
	 * @throws SfExpressException 失敗
	 */
	private function attempt( SfCrypto $crypto, string $msg_type, string $action, string $plain, string $token ): array {
		$encrypt   = $crypto->encrypt( $plain );
		$timestamp = (string) (int) \round( \microtime( true ) * 1000 );
		$nonce     = \wp_generate_uuid4();

		$response = \wp_remote_post(
			$this->settings->base_url() . '/openapi/api/dispatch',
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'appKey'       => $this->settings->app_key,
					'token'        => $token,
					'timestamp'    => $timestamp,
					'nonce'        => $nonce,
					'signature'    => SfCrypto::signature( $token, $timestamp, $nonce, $encrypt ),
					'msgType'      => $msg_type,
					'lang'         => 'zh-HK',
					'Content-Type' => 'application/json',
				],
				'body'    => $encrypt,
			]
		);
		if (\is_wp_error( $response )) {
			throw new SfExpressException( "順豐{$action}失敗：連線失敗（{$response->get_error_message()}）", ErrorCode::NETWORK );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$outer  = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		if ($status < 200 || $status >= 300 || !\is_array( $outer ) || !\array_key_exists( 'apiResultCode', $outer )) {
			throw new SfExpressException( "順豐{$action}失敗：回應異常（HTTP {$status}）", ErrorCode::NETWORK );
		}

		$code = \is_scalar( $outer['apiResultCode'] ) ? (string) $outer['apiResultCode'] : '';
		if ('0' !== $code) {
			$message = \is_string( $outer['apiErrorMsg'] ?? null ) ? $outer['apiErrorMsg'] : '';
			throw SfExpressException::platform( $action, $code, $message );
		}

		$data     = $outer['apiResultData'] ?? null;
		$business = \is_string( $data ) ? \json_decode( $crypto->decrypt( $data ), true ) : $data;
		if (!\is_array( $business )) {
			throw new SfExpressException( "順豐{$action}失敗：無法解析回應內容", ErrorCode::PROVIDER );
		}

		/** @var array<string, mixed> $business */
		if (true !== ( $business['success'] ?? null )) {
			$biz_code = \is_scalar( $business['code'] ?? null ) ? (string) $business['code'] : '';
			$biz_msg  = \is_string( $business['msg'] ?? null ) ? $business['msg'] : '';
			throw SfExpressException::business( $action, $biz_code, $biz_msg );
		}
		return $business;
	}

	/**
	 * 是否為 token 失效例外
	 *
	 * @param SfExpressException $e 例外
	 * @return bool
	 */
	private function is_token_invalid( SfExpressException $e ): bool {
		return ErrorCode::AUTH === $e->error_code && '500' === $e->raw_code;
	}
}
