<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Http;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 嘉里大榮 EDIEntrance / KerryTracingEDI client（只呼叫規格書 v1.1.0 收錄的端點）
 *
 * - `POST` + `application/json` + Basic Auth（帳密錯 / 環境用錯 → 401 `{"Message":"The requested authorization has been rejected."}`，
 *   2026-11-21 無憑證實打確認）；Report 規格書未列驗證但照樣帶
 * - 寫入類 body 一律 JSON 陣列（一次只送一筆）；`Report/GetLabelPdf` 唯一例外為單一物件
 * - 寫入類回應 `Result`：01 成功 / 02 部分成功 / 03 錯誤；本系統一次一筆 → 02 / 03 皆判失敗，
 *   優先取 `Data[].BLN` 等於自己單號那筆的 `Error`（測試機實測 03 也會帶原因）；無原因 → VALIDATION「未提供原因」
 * - Report：以 `%PDF-` 檔頭判定成功（空陣列也會回合法空白 PDF → 呼叫前擋）；失敗為 ASP.NET ModelState → 攤平成一行
 * - 貨況：常溫 11 碼 → BLNListTracing、低溫 10 碼 → ColdBLNListTracing（不可混送）；`Status` 1 成功 / 0 失敗
 * - log 只記端點 / HTTP 狀態 / 結果，不記 body（含收件人個資）與 Authorization
 *
 * @see .claude/skills/kerrytj-logistics/references/api-reference.md
 * @see .claude/skills/kerrytj-logistics/references/tracking.md
 */
final class KerrytjApiClient {

	/** @var int 預設逾時秒數（寫入有副作用，逾時後對面可能已處理 → 不調短） */
	private const DEFAULT_TIMEOUT = 30;

	/**
	 * Constructor
	 *
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 */
	public function __construct( private readonly KerrytjLogisticsSettingsDTO $settings ) {
	}

	/**
	 * ShipmentDelivery — 上傳託運資料（建單）
	 *
	 * @param array<string, string> $row 34 欄
	 * @return array<string, mixed> 對應本單號的 AreaData（無則空陣列）
	 * @throws KerrytjLogisticsException 失敗
	 */
	public function shipment_delivery( array $row ): array {
		$body = $this->write( 'ShipmentDelivery', '建單', $row );
		$area = self::list_of( $body['AreaData'] ?? null );
		foreach ( $area as $entry ) {
			if (self::str( $entry['Id'] ?? '' ) === ( $row['BLN'] ?? '' )) {
				return $entry;
			}
		}
		return $area[0] ?? [];
	}

	/**
	 * UpdateShipmentDelivery — 修改託運資料（送完整 34 欄；已交站所則無法修改）
	 *
	 * @param array<string, string> $row 34 欄
	 * @return void
	 * @throws KerrytjLogisticsException 失敗
	 */
	public function update_shipment_delivery( array $row ): void {
		$this->write( 'UpdateShipmentDelivery', '修改託運資料', $row );
	}

	/**
	 * PickupV2 — 常溫取件（退貨）
	 *
	 * @param array<string, string> $row 17 欄
	 * @return void
	 * @throws KerrytjLogisticsException 失敗
	 */
	public function pickup( array $row ): void {
		$this->write( 'PickupV2', '取件', $row );
	}

	/**
	 * Report/GetLabelPdf — 單張地址標籤（body 為單一物件）
	 *
	 * @param array<string, string> $label 標籤 16 欄
	 * @return string PDF bytes
	 * @throws KerrytjLogisticsException 失敗
	 */
	public function label_pdf( array $label ): string {
		return $this->pdf( 'Report/GetLabelPdf', '標籤列印', $label );
	}

	/**
	 * Report/GetDetailPdf — 託運明細表（body 為陣列）
	 *
	 * @param array<int, array<string, string>> $rows 託運明細 26 欄（≥ 1 筆）
	 * @return string PDF bytes
	 * @throws KerrytjLogisticsException 失敗 / 空陣列
	 */
	public function detail_pdf( array $rows ): string {
		if ([] === $rows) {
			throw KerrytjLogisticsException::validation( '嘉里大榮託運明細至少需一筆（空陣列會回空白 PDF）' );
		}
		return $this->pdf( 'Report/GetDetailPdf', '託運明細列印', \array_values( $rows ) );
	}

	/**
	 * 貨況查詢（依單號長度分流常溫 / 低溫端點）；同一 BLN 合併並以 Date+Time+StatusCode 去重
	 *
	 * @param string $bln 託運單號
	 * @return array<int, array{station: string, date: string, time: string, status_code: string, status: string, status_code_type: int|null}>
	 * @throws KerrytjLogisticsException 單號不合法 / 查詢失敗
	 */
	public function tracing( string $bln ): array {
		$kind = KerrytjBln::kind_of( $bln );
		if (null === $kind) {
			throw KerrytjLogisticsException::validation( "嘉里大榮託運單號 {$bln} 格式不符，無法查詢貨況" );
		}
		$endpoint         = $kind->tracing_endpoint();
		[ $status, $raw ] = $this->request( KerrytjLogisticsSettingsDTO::TRACING_BASE . '/' . $endpoint, [ [ 'BLN' => $bln ] ] );
		self::assert_authorized( $status, '貨況查詢（貨況主機無測試環境，測試帳號可能無法查詢）' );

		$decoded = \json_decode( $raw, true );
		if (!\is_array( $decoded )) {
			throw new KerrytjLogisticsException( "嘉里大榮貨況查詢回應格式異常（HTTP {$status}）", ErrorCode::PROVIDER );
		}
		$result_status = self::str( $decoded['Status'] ?? '' );
		if ('1' !== $result_status) {
			$error = self::str( $decoded['Error'] ?? '' );
			throw new KerrytjLogisticsException(
				'嘉里大榮貨況查詢失敗：' . ( '' !== $error ? $error : "未提供原因（Status {$result_status}）" ),
				ErrorCode::PROVIDER,
				'' !== $error ? $error : null
			);
		}

		$events = [];
		foreach ( self::list_of( $decoded['Result'] ?? null ) as $entry ) {
			if (self::str( $entry['BLN'] ?? '' ) !== $bln) {
				continue;
			}
			foreach ( self::list_of( $entry['CargoTracing'] ?? null ) as $raw_event ) {
				$type  = $raw_event['StatusCodeType'] ?? null;
				$event = [
					'station'          => self::str( $raw_event['Station'] ?? '' ),
					'date'             => self::str( $raw_event['Date'] ?? '' ),
					'time'             => self::str( $raw_event['Time'] ?? '' ),
					'status_code'      => self::str( $raw_event['StatusCode'] ?? '' ),
					'status'           => self::str( $raw_event['Status'] ?? '' ),
					'status_code_type' => \is_numeric( $type ) ? (int) $type : null,
				];
				$events[ $event['date'] . $event['time'] . $event['status_code'] ] = $event;
			}
		}
		return \array_values( $events );
	}

	/**
	 * 驗證帳密：POST UpdateShipmentDelivery 空陣列（不含任何託運資料 → 不建立 / 修改任何單）
	 *
	 * 401 → AUTH；連線失敗 → NETWORK；其他任何回應 = Basic Auth 已通過。
	 *
	 * @return void
	 * @throws KerrytjLogisticsException 驗證失敗
	 */
	public function verify_credentials(): void {
		[ $status ] = $this->request( $this->settings->edi_base() . '/UpdateShipmentDelivery', [] );
		self::assert_authorized( $status, '測試連線' );
	}

	/**
	 * 寫入類共用：送一筆、判讀 Result
	 *
	 * @param string                $endpoint 端點
	 * @param string                $action   操作中文
	 * @param array<string, string> $row      單筆欄位
	 * @return array<string, mixed> 回應 JSON
	 * @throws KerrytjLogisticsException 失敗
	 */
	private function write( string $endpoint, string $action, array $row ): array {
		$bln              = (string) ( $row['BLN'] ?? '' );
		[ $status, $raw ] = $this->request( $this->settings->edi_base() . '/' . $endpoint, [ $row ] );
		self::assert_authorized( $status, $action );

		$decoded = \json_decode( $raw, true );
		if (200 !== $status || !\is_array( $decoded )) {
			throw new KerrytjLogisticsException( "嘉里大榮{$action}失敗：回應格式異常（HTTP {$status}）", ErrorCode::PROVIDER );
		}

		$result = self::str( $decoded['Result'] ?? '' );
		if ('01' === $result) {
			Plugin::logger( "嘉里大榮 {$endpoint} 成功", 'debug', [ 'bln' => $bln ] );
			return $decoded;
		}
		if ('02' === $result || '03' === $result) {
			$failures = self::list_of( $decoded['Data'] ?? null );
			$mine     = $failures[0] ?? [];
			foreach ( $failures as $failure ) {
				if (self::str( $failure['BLN'] ?? '' ) === $bln) {
					$mine = $failure;
					break;
				}
			}
			$reason = self::str( $mine['Error'] ?? '' );
			Plugin::logger(
				"嘉里大榮 {$endpoint} 失敗",
				'warning',
				[
					'bln'    => $bln,
					'result' => $result,
					'reason' => $reason,
				]
			);
			if ('' !== $reason) {
				throw KerrytjLogisticsException::from_write_error( $action, $bln, $reason );
			}
			throw new KerrytjLogisticsException(
				"嘉里大榮拒絕{$action}（Result {$result}，未提供原因）：請確認客戶編號、必填欄位與欄位長度",
				ErrorCode::VALIDATION,
				"Result {$result}"
			);
		}

		throw new KerrytjLogisticsException( "嘉里大榮{$action}失敗：無法辨識的 Result「{$result}」", ErrorCode::PROVIDER );
	}

	/**
	 * Report 共用：成功回 PDF bytes，失敗攤平 ModelState
	 *
	 * @param string                   $endpoint 端點
	 * @param string                   $action   操作中文
	 * @param array<int|string, mixed> $body     body
	 * @return string
	 * @throws KerrytjLogisticsException 失敗
	 */
	private function pdf( string $endpoint, string $action, array $body ): string {
		[ $status, $raw ] = $this->request( $this->settings->edi_base() . '/' . $endpoint, $body );
		self::assert_authorized( $status, $action );

		if (\str_starts_with( $raw, '%PDF-' )) {
			return $raw;
		}

		$decoded  = \json_decode( $raw, true );
		$messages = [];
		if (\is_array( $decoded )) {
			$model_state = \is_array( $decoded['ModelState'] ?? null ) ? $decoded['ModelState'] : [];
			foreach ( $model_state as $value ) {
				foreach ( \is_array( $value ) ? $value : [ $value ] as $message ) {
					if ('' !== self::str( $message )) {
						$messages[] = self::str( $message );
					}
				}
			}
			if ([] === $messages && '' !== self::str( $decoded['Message'] ?? '' )) {
				$messages[] = self::str( $decoded['Message'] );
			}
		}
		if ([] !== $messages) {
			$text = \implode( '；', $messages );
			throw new KerrytjLogisticsException( "嘉里大榮{$action}失敗：{$text}", ErrorCode::VALIDATION, $text );
		}
		throw new KerrytjLogisticsException( "嘉里大榮{$action}失敗：未回傳 PDF（HTTP {$status}）", ErrorCode::PROVIDER );
	}

	/**
	 * 送出 HTTP 請求
	 *
	 * @param string                   $url  URL
	 * @param array<int|string, mixed> $body JSON body
	 * @return array{0: int, 1: string} [HTTP status, raw body]
	 * @throws KerrytjLogisticsException 傳輸層失敗（NETWORK）
	 */
	private function request( string $url, array $body ): array {
		$timeout  = (int) \apply_filters( 'power_checkout_kerrytj_logistics_timeout', self::DEFAULT_TIMEOUT, $url );
		$response = \wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Basic ' . \base64_encode( $this->settings->username . ':' . $this->settings->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth
				],
				'body'    => (string) \wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'timeout' => \max( 1, $timeout ),
			]
		);

		if (\is_wp_error( $response )) {
			Plugin::logger( '嘉里大榮連線失敗', 'error', [ 'error' => $response->get_error_message() ] );
			throw new KerrytjLogisticsException( "嘉里大榮連線失敗：{$response->get_error_message()}", ErrorCode::NETWORK );
		}

		return [ (int) \wp_remote_retrieve_response_code( $response ), (string) \wp_remote_retrieve_body( $response ) ];
	}

	/**
	 * 401 → AUTH（不帶操作細節，帳密錯與送了什麼無關）
	 *
	 * @param int    $status HTTP 狀態
	 * @param string $action 操作中文
	 * @return void
	 * @throws KerrytjLogisticsException AUTH
	 */
	private static function assert_authorized( int $status, string $action ): void {
		if (401 === $status) {
			throw new KerrytjLogisticsException(
				"嘉里大榮拒絕連線（{$action}）：帳號或密碼錯誤，請確認後台設定的帳號密碼與環境（測試 / 正式）是否相符",
				ErrorCode::AUTH,
				'401'
			);
		}
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
	 * 純量轉字串並 trim（非純量回空字串）
	 *
	 * @param mixed $value 值
	 * @return string
	 */
	private static function str( mixed $value ): string {
		return \is_scalar( $value ) ? \trim( (string) $value ) : '';
	}
}
