<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Http;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctTrackCrypto;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 新竹物流貨況查詢 client（searchGoods 多筆 XML，非 SOAP）
 *
 * 流程：`<qrylist>` XML → DES-CBC 加密（KEY = 台北今天 + 偏移天數）→ POST `searchGoods_Main_Xml.ashx?no={密文}&v={v}`
 * → 回應為密文（少數空值情境回明文 XML）→ 解密 → 解析 `<order wrktime category status status_code>`。
 * 一般只能查 3 個月內資料；每日 03:40–04:30 維護不更新。log 不記 IV / v / 金鑰。
 *
 * @see .claude/skills/hct-logistics/references/cargo-tracking-decrypt.md §4
 */
final class HctTrackClient {

	/** @var int 逾時秒數 */
	private const TIMEOUT = 15;

	/**
	 * Constructor
	 *
	 * @param HctLogisticsSettingsDTO $settings 設定（須 has_track_query()）
	 */
	public function __construct( private readonly HctLogisticsSettingsDTO $settings ) {
	}

	/**
	 * 查詢單一貨號的貨況歷程
	 *
	 * @param string $edelno 新竹貨號
	 * @return array<int, array{wrktime: string, status: string, status_code: string, category: string}> 貨況（無資料為空陣列）
	 * @throws HctLogisticsException NETWORK / PROVIDER / UNKNOWN
	 */
	public function query( string $edelno ): array {
		$orderid  = \htmlspecialchars( $edelno, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$xml      = "<?xml version=\"1.0\" encoding=\"utf-8\"?><qrylist><order orderid=\"{$orderid}\"></order></qrylist>";
		$key_date = HctTrackCrypto::key_date( $this->settings->track_offset() );
		$url      = HctLogisticsSettingsDTO::TRACK_ENDPOINT
		. '?no=' . \rawurlencode( HctTrackCrypto::encrypt( $xml, $key_date, $this->settings->track_iv ) )
		. '&v=' . \rawurlencode( $this->settings->track_v );

		$response = \wp_remote_post( $url, [ 'timeout' => self::TIMEOUT ] );
		if (\is_wp_error( $response )) {
			Plugin::logger( '新竹物流貨況查詢連線失敗', 'warning', [ 'error' => $response->get_error_message() ] );
			throw new HctLogisticsException( '新竹物流貨況查詢連線失敗：' . $response->get_error_message(), ErrorCode::NETWORK );
		}
		$status = (int) \wp_remote_retrieve_response_code( $response );
		if (200 !== $status) {
			throw new HctLogisticsException( "新竹物流貨況查詢失敗：HTTP {$status}", ErrorCode::PROVIDER );
		}

		$body  = \trim( (string) \wp_remote_retrieve_body( $response ) );
		$plain = \str_starts_with( $body, '<' ) ? $body : HctTrackCrypto::decrypt( $body, $key_date, $this->settings->track_iv );

		return self::parse( $plain );
	}

	/**
	 * 解析解密後 XML（`<rlist />` = 無貨況）
	 *
	 * @param string $xml XML
	 * @return array<int, array{wrktime: string, status: string, status_code: string, category: string}>
	 * @throws HctLogisticsException UNKNOWN 非合法 XML
	 */
	private static function parse( string $xml ): array {
		$previous = \libxml_use_internal_errors( true );
		$doc      = \simplexml_load_string( $xml );
		\libxml_clear_errors();
		\libxml_use_internal_errors( $previous );
		if (false === $doc) {
			throw new HctLogisticsException( '新竹物流貨況查詢回應不是合法 XML', ErrorCode::UNKNOWN );
		}

		$events = [];
		foreach ( $doc->xpath( '//order' ) ?: [] as $node ) {
			$events[] = [
				'wrktime'     => (string) ( $node['wrktime'] ?? '' ),
				'status'      => (string) ( $node['status'] ?? '' ),
				'status_code' => (string) ( $node['status_code'] ?? '' ),
				'category'    => (string) ( $node['category'] ?? '' ),
			];
		}
		return $events;
	}
}
