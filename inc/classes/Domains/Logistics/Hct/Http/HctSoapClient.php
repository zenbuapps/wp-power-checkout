<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Http;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 新竹物流 EDI SOAP client（手組 SOAP 1.1 envelope，不用 \SoapClient）
 *
 * - ASP.NET `.asmx`：`_Json` 方法仍是 SOAP 呼叫，JSON 字串放進 `<json>` 參數、回傳包在 `<{method}Result>`
 * - namespace = `http://tempuri.org/`、SOAPAction = `"http://tempuri.org/{method}"`（無尾斜線）、參數 company / password / json
 *   ——2026-11-21 唯讀實抓 DEV `service1.asmx?wsdl` 確認（skill version-notes.md §4）
 * - 業務成敗看每筆 `success`（Y 新增 / R 修改 / N 失敗）；本 client 只負責「成功取回結果陣列」，success=N 由呼叫端判斷
 * - 不用 \SoapClient：WSDL 文件未載、標籤 image 約 2 萬字元易超過預設訊息上限（R9）
 * - 錯誤訊息遮罩公司名稱 / 密碼（.asmx 認證失敗可能把參數回吐在 faultstring）；log 只記方法與結果，不記 body
 *
 * @see .claude/skills/hct-logistics/references/code-examples.md §2
 */
final class HctSoapClient {

	/** @var string SOAP target namespace（WSDL 實測） */
	public const SOAP_NAMESPACE = 'http://tempuri.org/';

	/** @var int 預設逾時秒數（可由 filter power_checkout_hct_logistics_timeout 調整） */
	private const DEFAULT_TIMEOUT = 60;

	/**
	 * Constructor
	 *
	 * @param HctLogisticsSettingsDTO $settings 設定
	 */
	public function __construct( private readonly HctLogisticsSettingsDTO $settings ) {
	}

	/**
	 * TransData_Json — 上傳託運資料（建單；帶 image 一次上限 5 筆）
	 *
	 * @param array<int, array<string, string>> $rows 託運資料
	 * @return array<int, array<string, string>> 每筆結果（success / edelno / erstno / image / ErrMsg / CODE*…）
	 * @throws HctLogisticsException 傳輸 / 格式失敗
	 */
	public function trans_data( array $rows ): array {
		return $this->call_json( 'TransData_Json', \array_values( $rows ) );
	}

	/**
	 * QueryEDELNO_Json — 以訂單編號查已配發的貨號（唯讀）
	 *
	 * Payload 形狀 = 陣列包「單一」物件、epino 為逗號分隔（zenbu 對 DEV 實打：多物件只會處理第一筆）。
	 *
	 * @param array<int, string> $epinos 訂單編號（不可含逗號）
	 * @return array<int, array<string, string>>
	 * @throws HctLogisticsException 傳輸 / 格式失敗
	 */
	public function query_edelno( array $epinos ): array {
		$csv = \implode( ',', \array_map( static fn( string $epino ): string => \str_replace( ',', '', $epino ), $epinos ) );
		return $this->call_json( 'QueryEDELNO_Json', [ [ 'epino' => $csv ] ] );
	}

	/**
	 * TransDataCancel_Json — 取消收貨資料（取消託運單；edelno + epino）
	 *
	 * @param array<int, array{edelno: string, epino: string}> $rows 取消資料
	 * @return array<int, array<string, string>>
	 * @throws HctLogisticsException 傳輸 / 格式失敗
	 */
	public function trans_data_cancel( array $rows ): array {
		return $this->call_json( 'TransDataCancel_Json', \array_values( $rows ) );
	}

	/**
	 * R_TransData_Json — 逆物流（退貨）；回傳大寫 key（Success / Edelno / Erstno…）正規化為小寫
	 *
	 * @param array<int, array<string, string>> $rows 退貨資料
	 * @return array<int, array<string, string>> 正規化後（success / edelno / epino / erstno / eqamt / ErrMsg）
	 * @throws HctLogisticsException 傳輸 / 格式失敗
	 */
	public function r_trans_data( array $rows ): array {
		$normalized = [];
		foreach ( $this->call_json( 'R_TransData_Json', \array_values( $rows ) ) as $row ) {
			$pick         = static function ( string ...$keys ) use ( $row ): string {
				foreach ( $keys as $key ) {
					if (isset( $row[ $key ] ) && '' !== $row[ $key ]) {
						return $row[ $key ];
					}
				}
				return '';
			};
			$normalized[] = [
				'Num'     => $pick( 'Num', 'num' ),
				'success' => $pick( 'Success', 'success' ),
				'edelno'  => $pick( 'Edelno', 'edelno' ),
				'epino'   => $pick( 'Epino', 'epino' ),
				'erstno'  => $pick( 'Erstno', 'erstno' ),
				'eqamt'   => $pick( 'Eqamt', 'eqamt' ),
				'ErrMsg'  => $pick( 'ErrMsg', 'errMsg' ),
			];
		}
		return $normalized;
	}

	/**
	 * 標準三參數方法（company / password / json）
	 *
	 * @param string                            $method  SOAP 方法
	 * @param array<int, array<string, string>> $payload 物件陣列
	 * @return array<int, array<string, string>>
	 * @throws HctLogisticsException 傳輸 / 格式失敗
	 */
	private function call_json( string $method, array $payload ): array {
		$json = \wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if (!\is_string( $json )) {
			throw new HctLogisticsException( "新竹物流 {$method} 請求資料無法編碼", ErrorCode::VALIDATION );
		}

		$result  = $this->invoke(
			$method,
			[
				'company'  => $this->settings->company,
				'password' => $this->settings->password,
				'json'     => $json,
			]
		);
		$decoded = \json_decode( $result, true );
		if (!\is_array( $decoded )) {
			throw new HctLogisticsException( "新竹物流 {$method} 回應不是合法 JSON", ErrorCode::UNKNOWN );
		}
		// 部分情境回單一物件 → 包成陣列
		$rows = \array_is_list( $decoded ) ? $decoded : [ $decoded ];

		$normalized = [];
		foreach ( $rows as $row ) {
			if (!\is_array( $row )) {
				continue;
			}
			$item = [];
			foreach ( $row as $key => $value ) {
				$item[ (string) $key ] = \is_scalar( $value ) ? (string) $value : '';
			}
			$normalized[] = $item;
		}
		return $normalized;
	}

	/**
	 * 組 envelope → POST → 抽出 `<{method}Result>` 內文
	 *
	 * @param string                $method SOAP 方法
	 * @param array<string, string> $params 參數（順序 = WSDL element 順序，名稱大小寫敏感）
	 * @return string Result 內文（已解 XML entity）
	 * @throws HctLogisticsException NETWORK / PROVIDER / UNKNOWN
	 */
	private function invoke( string $method, array $params ): string {
		$inner = '';
		foreach ( $params as $name => $value ) {
			$inner .= "<{$name}>" . \htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</{$name}>";
		}
		$namespace = self::SOAP_NAMESPACE;
		$envelope  = '<?xml version="1.0" encoding="utf-8"?>'
		. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
		. "<soap:Body><{$method} xmlns=\"{$namespace}\">{$inner}</{$method}></soap:Body></soap:Envelope>";

		$response = \wp_remote_post(
			$this->settings->endpoint(),
			[
				'timeout' => (int) \apply_filters( 'power_checkout_hct_logistics_timeout', self::DEFAULT_TIMEOUT ),
				'headers' => [
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '"' . \rtrim( $namespace, '/' ) . "/{$method}\"",
				],
				'body'    => $envelope,
			]
		);

		if (\is_wp_error( $response )) {
			Plugin::logger( "新竹物流 {$method} 連線失敗", 'warning', [ 'error' => $response->get_error_message() ] );
			throw new HctLogisticsException( "新竹物流 {$method} 連線失敗：" . $this->mask( $response->get_error_message() ), ErrorCode::NETWORK );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );
		$body   = (string) \wp_remote_retrieve_body( $response );

		$fault = self::extract_fault( $body );
		if (null !== $fault) {
			Plugin::logger( "新竹物流 {$method} SOAP Fault", 'warning', [ 'status' => $status ] );
			throw new HctLogisticsException( "新竹物流 {$method} SOAP Fault：" . $this->mask( $fault ), ErrorCode::PROVIDER );
		}
		if (200 !== $status) {
			throw new HctLogisticsException( "新竹物流 {$method} 失敗：HTTP {$status}", ErrorCode::PROVIDER );
		}

		$tag = "{$method}Result";
		if (1 !== \preg_match( "#<{$tag}[^>]*>(.*?)</{$tag}>#s", $body, $matches )) {
			throw new HctLogisticsException( "新竹物流 {$method} 回應格式非預期（缺少 {$tag}）", ErrorCode::UNKNOWN );
		}

		Plugin::logger( "新竹物流 {$method} 完成", 'info', [ 'bytes' => \strlen( $body ) ] );
		return \html_entity_decode( $matches[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * 偵測 SOAP Fault 並取 faultstring
	 *
	 * @param string $xml 回應
	 * @return string|null 無 fault 回 null
	 */
	private static function extract_fault( string $xml ): ?string {
		if (1 === \preg_match( '#<faultstring[^>]*>(.*?)</faultstring>#si', $xml, $matches )) {
			return \trim( \html_entity_decode( $matches[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) );
		}
		return 1 === \preg_match( '#<(?:[a-z0-9]+:)?Fault\b#i', $xml ) ? 'SOAP Fault' : null;
	}

	/**
	 * 遮罩訊息中的公司名稱 / 密碼
	 *
	 * @param string $message 訊息
	 * @return string
	 */
	private function mask( string $message ): string {
		foreach ( [ $this->settings->password, $this->settings->company ] as $secret ) {
			if ('' !== $secret) {
				$message = \str_replace( $secret, '***', $message );
			}
		}
		return $message;
	}
}
