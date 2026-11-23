<?php
/**
 * 黑貓宅急便直串（統一速達標準印單 API）物流服務提供者 `tcat_logistics`
 *
 * 第一版只做宅配（ASM-6）：不做 7-11 快速到店（B2S）與電子地圖（需黑貓PAY token + ccat.com.tw 綁定）。
 *
 *  - create_shipment = PrintOBT：系統配號 → 寫 _pc_tcat_logistics_ref + FileNo；已有 ref → 冪等回既有（不重複配號）
 *  - print_document  = DownloadOBT：回 PDF bytes（LogisticsApiService 以 application/pdf 輸出）；FileNo 24 小時失效
 *  - query_shipment  = OBTStatus：同一單號 2 小時只能查 1 次（E087）→ 2 小時 transient 快取，命中回 cached=true；
 *                      查無貨態（E077）= 尚未集貨 → pending；raw StatusId → ShippingState（unified_state）
 *  - create_return   = ReserveAdd：收件人 = 商店（寄件人設定）、寄件人 = 顧客；TakeDate D+1~D+7、16:30 截止
 *  - 選店 / 取消：UNSUPPORTED（meta 宣告 false，REST 層回 501）；無貨態推送 → handle_status_callback 回 200 no-op
 *  - 不做背景輪詢（ASM-7）
 *
 * 失敗一律 throw TcatLogisticsException（帶 ErrorCode 與黑貓 Exxx 原始碼），建單 / 逆物流失敗另寫 order note。
 *
 * @see .claude/skills/tcat-logistics/SKILL.md
 * @see zenbu apps/api-gateway/src/commerce/shipping/tcat/tcat-logistics.service.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsConnectionTest;
use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\PrintObtParams;
use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\TcatLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Tcat\Http\TcatLogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums\TcatThermosphere;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatFieldRules;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsDates;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatStatusMapper;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 黑貓宅急便直串物流服務提供者 */
final class TcatLogisticsProvider extends BaseService implements ILogisticsProvider, ISupportsConnectionTest {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Provider ID（對應 woocommerce_tcat_logistics_settings option） */
	public const ID = 'tcat_logistics';

	/** @var int 貨態查詢快取秒數（OBTStatus 同一單號 2 小時限查 1 次） */
	public const STATUS_CACHE_TTL = 7200;

	/** @var string 貨態快取 transient 前綴 */
	public const STATUS_CACHE_PREFIX = 'pc_tcat_logistics_status_';

	/** @var int FileNo 有效秒數（24 小時） */
	public const FILE_NO_TTL = 86400;

	/** @var string 寄件地址未設定時 ping 使用的查詢地址（唯讀） */
	private const PING_FALLBACK_ADDRESS = '台北市中正區重慶南路一段122號';

	/** Constructor */
	public function __construct() {
		$settings                 = TcatLogisticsSettingsDTO::instance();
		$this->id                 = self::ID;
		$this->enabled            = $settings->enabled;
		$this->method_title       = \__( '黑貓宅急便（直串）', 'power_checkout' );
		$this->method_description = \__( '統一速達黑貓宅急便標準印單 API 直串：宅配建單、下載託運單 PDF、查詢貨態、逆物流退貨預約。', 'power_checkout' );
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			/** @var array<string, mixed> $result */
			$result = \is_array( $option ) ? $option : [];
			return $result;
		}

		return \array_merge(
			TcatLogisticsSettingsDTO::instance()->to_array(),
			[
				'id'                 => self::ID,
				'icon'               => Plugin::$url . '/inc/assets/images/icons/tcat.png',
				'method_title'       => \__( '黑貓宅急便（直串）', 'power_checkout' ),
				'method_description' => \__( '統一速達黑貓宅急便標準印單 API 直串，宅配到府、下載託運單 PDF、查詢貨態。', 'power_checkout' ),
			]
		);
	}

	/** @return LogisticsProviderMetaDTO 宅配 only；選店 / 取消不支援 */
	public function get_provider_meta(): LogisticsProviderMetaDTO {
		return new LogisticsProviderMetaDTO(
			id: self::ID,
			supported_delivery_methods: [ DeliveryMethod::HOME ],
			capabilities: new ShipmentCapabilitiesDTO(
				can_create_shipment: true,
				can_query_shipment: true,
				has_cvs_map: false,
				can_print: true,
				can_return: true,
				can_cancel: false,
			),
		);
	}

	/** @return array<int, string> 結帳可選子類型（只有宅配） */
	public function get_supported_methods(): array {
		return [ DeliveryMethod::HOME->value ];
	}

	// region 不支援的操作

	/**
	 * 選店（不支援：第一版只做宅配）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 * @throws TcatLogisticsException UNSUPPORTED
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		throw TcatLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 解析選店回呼（不支援）
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 * @throws TcatLogisticsException UNSUPPORTED
	 */
	public function parse_store_selection( array $raw ): array {
		throw TcatLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 取消託運單（不支援：印單 API 沒有宅配註銷端點）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws TcatLogisticsException UNSUPPORTED
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		throw TcatLogisticsException::unsupported( '取消託運單' );
	}

	/**
	 * 貨態推送（黑貓印單 API 無推送，回 200 no-op）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'ignored',
				'message' => '黑貓宅急便印單 API 沒有貨態推送，請於後台查詢貨態',
				'data'    => null,
			],
			200
		);
	}

	// endregion

	// region create_shipment / print_document

	/**
	 * 建立宅配託運單（PrintOBT）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 含 logistics_id / file_no（冪等時另含 idempotent=true）
	 * @throws TcatLogisticsException 驗證或 API 失敗
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta = new TcatLogisticsMetaKeys( $order );
		if ('' !== $meta->get_ref()) {
			return [
				'logistics_id' => $meta->get_ref(),
				'file_no'      => $meta->get_file_no(),
				'idempotent'   => true,
			];
		}

		try {
			$settings = $this->settings_with_credentials();
			$client   = new TcatLogisticsApiClient( $settings );
			$params   = PrintObtParams::from_order(
				$order,
				$settings,
				$this->resolve_sender_zip_code( $settings, $client ),
				TcatLogisticsDates::now()
			);

			$result = $client->print_obt( $settings->print_obt_type, [ $params->to_api_order() ] );
		} catch ( TcatLogisticsException $e ) {
			$order->add_order_note( \sprintf( '❌ 黑貓宅急便建單失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_shipment( $result['obt_number'], $result['file_no'], \time() );
		$order->add_order_note(
			\sprintf(
				'黑貓宅急便託運單已建立：%1$s（%2$s，託運單 PDF 請於 24 小時內下載）',
				$result['obt_number'],
				TcatThermosphere::from( $params->thermosphere )->label()
			)
		);

		return [
			'logistics_id' => $result['obt_number'],
			'file_no'      => $result['file_no'],
		];
	}

	/**
	 * 下載託運單 PDF（DownloadOBT，帶本單單號補印）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PDF bytes
	 * @throws TcatLogisticsException 尚未建單 / FileNo 逾期 / API 失敗
	 */
	public function print_document( \WC_Order $order ): string {
		$meta = new TcatLogisticsMetaKeys( $order );
		if ('' === $meta->get_ref() || '' === $meta->get_file_no()) {
			throw new TcatLogisticsException( '尚未成立物流單，無法列印託運單', ErrorCode::CONFLICT );
		}
		if (\time() - $meta->get_file_no_created_at() > self::FILE_NO_TTL) {
			throw new TcatLogisticsException(
				'託運單下載期限已過（建單後 24 小時內有效），黑貓僅能重新建單取得新單號，請洽統一速達處理原單號',
				ErrorCode::CONFLICT,
				'E070'
			);
		}

		return ( new TcatLogisticsApiClient( $this->settings_with_credentials() ) )->download_obt( $meta->get_file_no(), [ $meta->get_ref() ] );
	}

	// endregion

	// region query_shipment

	/**
	 * 查詢貨態（OBTStatus；同單號 2 小時快取）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / status / status_name / unified_state / station_name / status_time / history / cached（/ throttled）
	 * @throws TcatLogisticsException 尚未建單 / API 失敗
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new TcatLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw new TcatLogisticsException( '尚未成立物流單，無法查詢貨態', ErrorCode::CONFLICT );
		}

		$cache_key = self::STATUS_CACHE_PREFIX . $ref;
		$cached    = \get_transient( $cache_key );
		if (\is_array( $cached )) {
			return \array_merge( $cached, [ 'cached' => true ] );
		}

		$client = new TcatLogisticsApiClient( $this->settings_with_credentials() );
		try {
			$obts   = $client->obt_status( [ $ref ] );
			$result = self::build_status_result( $ref, $obts );
		} catch ( TcatLogisticsException $e ) {
			if ('E077' === $e->raw_code) {
				// 查無貨態 = 剛配號尚未集貨，不是錯誤
				$result = self::build_status_result( $ref, [] );
			} elseif ('E087' === $e->raw_code) {
				// 平台限流（可能在其他系統查過）：回上次結果，不拋錯
				$last   = $meta->get_status();
				$result = [
					'logistics_id'  => $ref,
					'status'        => $last['status'],
					'status_name'   => $last['status_name'],
					'unified_state' => '' !== $last['unified_state'] ? $last['unified_state'] : null,
					'station_name'  => '',
					'status_time'   => '',
					'history'       => [],
				];
				\set_transient( $cache_key, $result, self::STATUS_CACHE_TTL );
				return \array_merge(
					$result,
					[
						'cached'    => true,
						'throttled' => true,
					]
				);
			} else {
				throw $e;
			}
		}

		$previous = $meta->get_status()['status'];
		$meta->save_status( (string) $result['status'], (string) $result['status_name'], (string) ( $result['unified_state'] ?? '' ) );
		if ('' !== $result['status'] && $previous !== $result['status']) {
			$order->add_order_note( \sprintf( '黑貓宅急便貨態更新：%1$s %2$s（%3$s）', $result['status'], $result['status_name'], $ref ) );
		}

		\set_transient( $cache_key, $result, self::STATUS_CACHE_TTL );
		return \array_merge( $result, [ 'cached' => false ] );
	}

	/**
	 * 由 OBTs 組查詢結果（找不到本單 = 尚無貨態 → pending）
	 *
	 * @param string                           $ref  託運單號
	 * @param array<int, array<string, mixed>> $obts OBTStatus Data.OBTs
	 * @return array{logistics_id: string, status: string, status_name: string, unified_state: string|null, station_name: string, status_time: string, history: array<int, array{status: string, status_name: string, time: string, station_name: string}>}
	 */
	private static function build_status_result( string $ref, array $obts ): array {
		$matched = null;
		foreach ( $obts as $obt ) {
			if ($ref === self::str( $obt['OBTNumber'] ?? '' )) {
				$matched = $obt;
				break;
			}
		}

		if (null === $matched) {
			return [
				'logistics_id'  => $ref,
				'status'        => '',
				'status_name'   => '尚無貨態（尚未集貨）',
				'unified_state' => ShippingState::PENDING->value,
				'station_name'  => '',
				'status_time'   => '',
				'history'       => [],
			];
		}

		$status  = \trim( self::str( $matched['StatusId'] ?? '' ) );
		$history = [];
		foreach ( \is_array( $matched['StatusList'] ?? null ) ? $matched['StatusList'] : [] as $row ) {
			if (!\is_array( $row )) {
				continue;
			}
			$history[] = [
				'status'       => \trim( self::str( $row['StatusId'] ?? '' ) ),
				'status_name'  => self::str( $row['StatusName'] ?? '' ),
				'time'         => self::str( $row['CreateDateTime'] ?? '' ),
				'station_name' => self::str( $row['StationName'] ?? '' ),
			];
		}

		return [
			'logistics_id'  => $ref,
			'status'        => $status,
			'status_name'   => self::str( $matched['StatusName'] ?? '' ),
			'unified_state' => TcatStatusMapper::map( $status )?->value,
			'station_name'  => self::str( $matched['StationName'] ?? '' ),
			'status_time'   => self::str( $matched['CreateDateTime'] ?? '' ),
			'history'       => $history,
		];
	}

	// endregion

	// region create_return

	/**
	 * 建立逆物流（ReserveAdd 退貨預約）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（take_date：指定取件日 yyyyMMdd，選填）
	 * @return array<string, mixed> 含 return_logistics_id（冪等時另含 idempotent=true）
	 * @throws TcatLogisticsException 驗證或 API 失敗
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		$meta = new TcatLogisticsMetaKeys( $order );
		if ('' === $meta->get_ref()) {
			throw new TcatLogisticsException( '尚未成立物流單，無法建立退貨單', ErrorCode::CONFLICT );
		}
		if ('' !== $meta->get_return_ref()) {
			return [
				'return_logistics_id' => $meta->get_return_ref(),
				'idempotent'          => true,
			];
		}

		try {
			$settings  = $this->settings_with_credentials();
			$requested = \is_string( $ctx['take_date'] ?? null ) ? $ctx['take_date'] : null;
			$reserve   = PrintObtParams::reserve_order_from( $order, $settings, TcatLogisticsDates::take_date( TcatLogisticsDates::now(), $requested ) );
			$result    = ( new TcatLogisticsApiClient( $settings ) )->reserve_add( $reserve );
		} catch ( TcatLogisticsException $e ) {
			$order->add_order_note( \sprintf( '❌ 黑貓宅急便退貨預約失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_return_ref( $result['obt_number'] );
		$order->add_order_note( \sprintf( '黑貓宅急便退貨預約已建立：%1$s（取件日 %2$s）', $result['obt_number'], (string) $reserve['TakeDate'] ) );

		return [ 'return_logistics_id' => $result['obt_number'] ];
	}

	// endregion

	// region test_connection

	/**
	 * 測試連線：以寄件地址呼叫 ParsingAddress（唯讀）
	 *
	 * @return array<string, mixed> provider / mode / address / post_number / zip_code / deliverable / credentials_verified（恆 false）
	 * @throws TcatLogisticsException 憑證缺漏 / API 失敗
	 */
	public function test_connection(): array {
		$settings = TcatLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials()) {
			throw TcatLogisticsException::validation( '請先填寫並儲存契客代號與授權碼' );
		}

		$address     = '' !== $settings->sender_address ? $settings->sender_address : self::PING_FALLBACK_ADDRESS;
		$rows        = ( new TcatLogisticsApiClient( $settings ) )->parsing_address( [ $address ] );
		$post_number = \trim( $rows[0]['PostNumber'] ?? '' );

		return [
			'provider'             => self::ID,
			'mode'                 => $settings->mode,
			'address'              => $address,
			'post_number'          => $post_number,
			'zip_code'             => TcatFieldRules::to_six_digit_zip( $post_number ),
			'deliverable'          => '' !== $post_number && 'X' !== \strtoupper( $post_number ),
			// ParsingAddress 不驗證授權碼（P8 實測：測試站佔位憑證仍回郵號），授權碼需以實際建單確認
			'credentials_verified' => false,
		];
	}

	// endregion

	// region helpers

	/**
	 * 取得設定並確認憑證齊全
	 *
	 * @return TcatLogisticsSettingsDTO
	 * @throws TcatLogisticsException 憑證缺漏（VALIDATION）
	 */
	private function settings_with_credentials(): TcatLogisticsSettingsDTO {
		$settings = TcatLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials()) {
			throw TcatLogisticsException::validation( '請先於黑貓宅急便設定頁填寫契客代號與授權碼' );
		}
		return $settings;
	}

	/**
	 * 寄件人六碼郵號：設定值優先，否則以寄件地址 ParsingAddress 查詢（去連字號取末六碼）
	 *
	 * @param TcatLogisticsSettingsDTO $settings 設定
	 * @param TcatLogisticsApiClient   $client   client
	 * @return string 六碼郵號
	 * @throws TcatLogisticsException 寄件地址未設定 / 不可配送 / 查無郵號
	 */
	private function resolve_sender_zip_code( TcatLogisticsSettingsDTO $settings, TcatLogisticsApiClient $client ): string {
		if ('' !== $settings->sender_zip_code) {
			return $settings->sender_zip_code;
		}
		if ('' === $settings->sender_address) {
			throw TcatLogisticsException::validation( '請先設定寄件人地址' );
		}

		$rows        = $client->parsing_address( [ $settings->sender_address ] );
		$post_number = \trim( $rows[0]['PostNumber'] ?? '' );
		if ('X' === \strtoupper( $post_number )) {
			throw TcatLogisticsException::validation( '寄件人地址不在黑貓配送範圍（郵號為 X），請確認寄件地址' );
		}
		$zip = TcatFieldRules::to_six_digit_zip( $post_number );
		if ('' === $zip) {
			throw TcatLogisticsException::validation( '無法由寄件人地址查出黑貓郵號，請於設定頁直接填寫寄件人郵號' );
		}
		return $zip;
	}

	/**
	 * 純量轉字串
	 *
	 * @param mixed $value 值
	 * @return string
	 */
	private static function str( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	// endregion
}
