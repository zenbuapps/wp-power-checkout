<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 順豐 Access Token 快取（WP transient，跨程序共用）
 *
 * - `GET {base}/openapi/api/token?appKey=&appSecret=` → apiResultData.accessToken（有效 7200 秒）
 * - **取得新 token 會讓舊 token 立即失效**：同一 appKey 的所有程序必須共用同一份快取，否則會互踢成間歇的
 *   `Access denied, token invalid`。快取鍵 = mode + appKey 雜湊（換環境 / 換應用不沿用；鍵名不含 appKey 原文）。
 * - TTL = expireIn − 60 秒（R12）；dispatch 遇 token invalid 由 SfApiClient 呼叫 invalidate() 後重取一次。
 * - 錯誤訊息只用平台 apiErrorMsg，絕不帶出 URL（querystring 含 appSecret）。
 *
 * @see .claude/skills/sf-express/references/auth-and-crypto.md §0.2、§1
 */
final class SfAccessTokenStore {

	/** @var string transient 前綴 */
	public const PREFIX = 'pc_sf_express_token_';

	/** @var int 提前失效秒數 */
	public const TTL_MARGIN = 60;

	/** @var int token 端點逾時秒數 */
	private const TIMEOUT = 10;

	/**
	 * Constructor
	 *
	 * @param SfExpressSettingsDTO $settings 設定
	 */
	public function __construct( private readonly SfExpressSettingsDTO $settings ) {
	}

	/**
	 * 快取鍵
	 *
	 * @param string $mode    test|prod
	 * @param string $app_key appKey
	 * @return string
	 */
	public static function cache_key( string $mode, string $app_key ): string {
		return self::PREFIX . \md5( "{$mode}|{$app_key}" );
	}

	/**
	 * 取得 token（命中快取直接回；否則向順豐取號並寫快取）
	 *
	 * @return string
	 * @throws SfExpressException AUTH / NETWORK / PROVIDER
	 */
	public function get(): string {
		$cached = \get_transient( $this->key() );
		if (\is_string( $cached ) && '' !== $cached) {
			return $cached;
		}
		return $this->refresh();
	}

	/**
	 * 強制向順豐取新 token 並寫快取
	 *
	 * @return string
	 * @throws SfExpressException AUTH / NETWORK / PROVIDER
	 */
	public function refresh(): string {
		$url      = $this->settings->base_url() . '/openapi/api/token?' . \http_build_query(
			[
				'appKey'    => $this->settings->app_key,
				'appSecret' => $this->settings->app_secret,
			]
		);
		$response = \wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'lang' => 'zh-HK' ],
			]
		);
		if (\is_wp_error( $response )) {
			throw new SfExpressException( '順豐取得 Access Token 失敗：連線失敗', ErrorCode::NETWORK );
		}

		$json = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		if (!\is_array( $json ) || !\array_key_exists( 'apiResultCode', $json )) {
			throw new SfExpressException( \sprintf( '順豐取得 Access Token 失敗：回應格式錯誤（HTTP %d）', (int) \wp_remote_retrieve_response_code( $response ) ), ErrorCode::NETWORK );
		}

		$code = \is_scalar( $json['apiResultCode'] ) ? (string) $json['apiResultCode'] : '';
		if ('0' !== $code) {
			$message = \is_string( $json['apiErrorMsg'] ?? null ) && '' !== $json['apiErrorMsg'] ? $json['apiErrorMsg'] : '取號失敗';
			throw new SfExpressException( "順豐取得 Access Token 失敗：{$message}（{$code}）", ErrorCode::AUTH, $code );
		}

		$data   = \is_array( $json['apiResultData'] ?? null ) ? $json['apiResultData'] : [];
		$token  = \is_string( $data['accessToken'] ?? null ) ? $data['accessToken'] : '';
		$expire = \is_numeric( $data['expireIn'] ?? null ) ? (int) $data['expireIn'] : 7200;
		if ('' === $token) {
			throw new SfExpressException( '順豐取得 Access Token 失敗：回應缺少 accessToken', ErrorCode::PROVIDER );
		}

		\set_transient( $this->key(), $token, \max( 60, $expire - self::TTL_MARGIN ) );
		return $token;
	}

	/** @return void 清除快取（token invalid 時） */
	public function invalidate(): void {
		\delete_transient( $this->key() );
	}

	/** @return string 本設定的快取鍵 */
	private function key(): string {
		return self::cache_key( $this->settings->mode, $this->settings->app_key );
	}
}
