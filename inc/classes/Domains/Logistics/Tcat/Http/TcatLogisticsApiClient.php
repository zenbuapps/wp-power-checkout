<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Http;

use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\TcatLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 黑貓宅急便（統一速達）標準印單 API client
 *
 * - HTTPS JSON POST `{base}/{服務名稱}`；認證 = CustomerId + CustomerToken 放 body 最上層（無簽章 / 無加密 / 無 header）
 * - 回應外殼 SrvTranId / IsOK / Message / Data；**HTTP 200 不代表成功，必須判 IsOK === 'Y'**
 * - DownloadOBT 唯一 binary 端點：200 = PDF、400 = JSON 錯誤 → 先看 status / 首位元組再解析
 * - 連線逾時 ≥ 120 秒（官方明列），可經 filter `power_checkout_tcat_logistics_timeout` 調整
 * - log 只記服務名稱 / SrvTranId / 結果，不記 body（含授權碼與收件人個資）
 *
 * @see .claude/skills/tcat-logistics/references/api-reference.md §0
 */
final class TcatLogisticsApiClient {

	/** @var int 預設逾時秒數 */
	private const DEFAULT_TIMEOUT = 120;

	/**
	 * Constructor
	 *
	 * @param TcatLogisticsSettingsDTO $settings 設定
	 */
	public function __construct( private readonly TcatLogisticsSettingsDTO $settings ) {
	}

	/**
	 * ParsingAddress — 查詢郵號
	 *
	 * @param array<int, string> $addresses 地址（≤100 筆）
	 * @return array<int, array{Search: string, PostNumber: string}>
	 * @throws TcatLogisticsException 失敗
	 */
	public function parsing_address( array $addresses ): array {
		$data = $this->post_json(
			'ParsingAddress',
			[
				'Addresses' => \array_map( static fn( string $address ): array => [ 'Search' => $address ], \array_values( $addresses ) ),
			]
		);

		$result = [];
		foreach ( self::list_of( $data['Addresses'] ?? null ) as $row ) {
			$result[] = [
				'Search'     => self::str( $row['Search'] ?? '' ),
				'PostNumber' => self::str( $row['PostNumber'] ?? '' ),
			];
		}
		return $result;
	}

	/**
	 * PrintOBT — 列印託運單（宅配）：配號 + FileNo
	 *
	 * @param string                           $print_obt_type 01 A4 二模 / 02 A4 三模 / 03 熱轉印
	 * @param array<int, array<string, mixed>> $orders         訂單（≤100 筆）
	 * @return array{obt_number: string, file_no: string, print_date_time: string, srv_tran_id: string}
	 * @throws TcatLogisticsException 失敗或回應缺單號 / FileNo
	 */
	public function print_obt( string $print_obt_type, array $orders ): array {
		$srv_tran_id = '';
		$data        = $this->post_json(
			'PrintOBT',
			[
				'PrintType'    => '01',
				'PrintOBTType' => $print_obt_type,
				'Orders'       => \array_values( $orders ),
			],
			$srv_tran_id
		);

		$first   = self::list_of( $data['Orders'] ?? null )[0] ?? [];
		$obt     = self::str( $first['OBTNumber'] ?? '' );
		$file_no = self::str( $data['FileNo'] ?? '' );
		if ('' === $obt || '' === $file_no) {
			throw new TcatLogisticsException( '黑貓宅急便 PrintOBT 回應缺少託運單號或 FileNo', ErrorCode::PROVIDER );
		}

		return [
			'obt_number'      => $obt,
			'file_no'         => $file_no,
			'print_date_time' => self::str( $data['PrintDateTime'] ?? '' ),
			'srv_tran_id'     => $srv_tran_id,
		];
	}

	/**
	 * DownloadOBT — 下載 / 補印託運單 PDF（binary）
	 *
	 * @param string             $file_no     FileNo（24 小時內有效）
	 * @param array<int, string> $obt_numbers 指定單號（補印；空 = 整批）
	 * @return string PDF bytes
	 * @throws TcatLogisticsException HTTP 非 200 或回 JSON 錯誤（如 E070 FileNo 逾期）
	 * @throws TcatLogisticsException 回應不是 PDF
	 */
	public function download_obt( string $file_no, array $obt_numbers = [] ): string {
		$body = [ 'FileNo' => $file_no ];
		if ([] !== $obt_numbers) {
			$body['Orders'] = \array_map( static fn( string $obt ): array => [ 'OBTNumber' => $obt ], \array_values( $obt_numbers ) );
		}

		[ $status, $raw ] = $this->request( 'DownloadOBT', $body );

		$looks_json = '' !== $raw && '{' === $raw[0];
		if (200 !== $status || $looks_json) {
			$decoded   = $looks_json ? \json_decode( $raw, true ) : null;
			$message   = \is_array( $decoded ) ? self::str( $decoded['Message'] ?? '' ) : '';
			$exception = '' !== $message
			? TcatLogisticsException::from_api_message( 'DownloadOBT', $message )
			: new TcatLogisticsException( "黑貓宅急便 DownloadOBT 失敗：HTTP {$status}", ErrorCode::PROVIDER );
			throw $exception;
		}
		if (!\str_starts_with( $raw, '%PDF' )) {
			throw new TcatLogisticsException( '黑貓宅急便 DownloadOBT 回應不是 PDF', ErrorCode::PROVIDER );
		}

		Plugin::logger( '黑貓宅急便 DownloadOBT 成功', 'info', [ 'bytes' => \strlen( $raw ) ] );
		return $raw;
	}

	/**
	 * OBTStatus — 查詢託運單貨態（≤10 筆；同單號 2 小時限流由呼叫端快取）
	 *
	 * 「查無貨態」黑貓回 IsOK=N（E077），以 NOT_FOUND 例外上拋，由呼叫端視為尚未集貨。
	 *
	 * @param array<int, string> $obt_numbers 託運單號
	 * @return array<int, array<string, mixed>> OBTs（僅回已有貨態的單號）
	 * @throws TcatLogisticsException 失敗
	 */
	public function obt_status( array $obt_numbers ): array {
		$data = $this->post_json( 'OBTStatus', [ 'OBTNumbers' => \array_values( $obt_numbers ) ] );
		return self::list_of( $data['OBTs'] ?? null );
	}

	/**
	 * ReserveAdd — 逆物流新增（單筆；Order 為物件）
	 *
	 * @param array<string, mixed> $order 逆物流訂單
	 * @return array{obt_number: string, srv_tran_id: string}
	 * @throws TcatLogisticsException 失敗或回應缺單號
	 */
	public function reserve_add( array $order ): array {
		$srv_tran_id = '';
		$data        = $this->post_json(
			'ReserveAdd',
			[
				'PrintType' => '01',
				'Order'     => $order,
			],
			$srv_tran_id
		);

		$result = \is_array( $data['Order'] ?? null ) ? $data['Order'] : [];
		$obt    = self::str( $result['OBTNumber'] ?? '' );
		if ('' === $obt) {
			throw new TcatLogisticsException( '黑貓宅急便 ReserveAdd 回應缺少託運單號', ErrorCode::PROVIDER );
		}
		return [
			'obt_number'  => $obt,
			'srv_tran_id' => $srv_tran_id,
		];
	}

	/**
	 * JSON 端點：送出並驗 IsOK，回 Data
	 *
	 * @param string               $service     服務名稱
	 * @param array<string, mixed> $body        業務欄位（不含認證）
	 * @param string               $srv_tran_id 回傳平台交易序號（傳址）
	 * @return array<string, mixed> Data
	 * @throws TcatLogisticsException 失敗
	 */
	private function post_json( string $service, array $body, string &$srv_tran_id = '' ): array {
		[ $status, $raw ] = $this->request( $service, $body );

		$decoded = '' !== $raw ? \json_decode( $raw, true ) : null;
		if (!\is_array( $decoded )) {
			throw new TcatLogisticsException(
				"黑貓宅急便 {$service} 回應無法解析（HTTP {$status}），請確認契客代號 / 授權碼與測試 / 正式環境是否對應",
				ErrorCode::PROVIDER
			);
		}

		$srv_tran_id = self::str( $decoded['SrvTranId'] ?? '' );
		if ('Y' !== ( $decoded['IsOK'] ?? '' )) {
			$exception = TcatLogisticsException::from_api_message( $service, self::str( $decoded['Message'] ?? '' ) );
			Plugin::logger(
				"黑貓宅急便 {$service} 失敗",
				'warning',
				[
					'srv_tran_id' => $srv_tran_id,
					'raw_code'    => $exception->raw_code,
					'http_status' => $status,
				]
			);
			throw $exception;
		}

		Plugin::logger( "黑貓宅急便 {$service} 成功", 'debug', [ 'srv_tran_id' => $srv_tran_id ] );
		return \is_array( $decoded['Data'] ?? null ) ? $decoded['Data'] : [];
	}

	/**
	 * 送出 HTTP 請求（認證欄位放 body 最上層最前面）
	 *
	 * @param string               $service 服務名稱
	 * @param array<string, mixed> $body    業務欄位
	 * @return array{0: int, 1: string} [HTTP status, raw body]
	 * @throws TcatLogisticsException 傳輸層失敗（NETWORK）
	 */
	private function request( string $service, array $body ): array {
		$payload = \array_merge(
			[
				'CustomerId'    => $this->settings->customer_id,
				'CustomerToken' => $this->settings->customer_token,
			],
			$body
		);

		$timeout  = (int) \apply_filters( 'power_checkout_tcat_logistics_timeout', self::DEFAULT_TIMEOUT, $service );
		$response = \wp_remote_post(
			$this->settings->api_base() . '/' . $service,
			[
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => (string) \wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'timeout' => \max( 1, $timeout ),
			]
		);

		if (\is_wp_error( $response )) {
			Plugin::logger( "黑貓宅急便 {$service} 連線失敗", 'error', [ 'error' => $response->get_error_message() ] );
			throw new TcatLogisticsException(
				"黑貓宅急便 {$service} 連線失敗：{$response->get_error_message()}",
				ErrorCode::NETWORK
			);
		}

		return [ (int) \wp_remote_retrieve_response_code( $response ), (string) \wp_remote_retrieve_body( $response ) ];
	}

	/**
	 * 轉成陣列清單（非陣列元素略過）
	 *
	 * @param mixed $value 值
	 * @return array<int, array<string, mixed>>
	 */
	private static function list_of( mixed $value ): array {
		if (!\is_array( $value )) {
			return [];
		}
		$list = [];
		foreach ( $value as $row ) {
			if (\is_array( $row )) {
				/** @var array<string, mixed> $row */
				$list[] = $row;
			}
		}
		return $list;
	}

	/**
	 * 純量轉字串（非純量回空字串）
	 *
	 * @param mixed $value 值
	 * @return string
	 */
	private static function str( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}
}
