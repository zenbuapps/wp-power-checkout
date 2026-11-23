<?php
/**
 * 新竹物流（EDI WebServices V15，SOAP）物流服務提供者 `hct_logistics`
 *
 * 純宅配（新竹無超商門市地圖）：
 *  - create_shipment = TransData_Json：設定了號段 → WaybillNumberAllocator 原子配號帶 edelno；未設定 → 新竹系統配號。
 *                      寫 ref（edelno）/ 到著站 / 標籤圖（image hex → base64）；已有 ref → 冪等回既有；號碼一經配出不回收
 *  - print_document  = 建單時存下的標籤圖 → HTML（data URL），不再打 API（重打 TransData 等同「更正資料」的寫入語意）
 *  - query_shipment  = searchGoods（DES-CBC）→ status_code → ShippingState；未設定查貨參數 → degrade pending（不打 API）；
 *                      10 分鐘 transient 快取（新竹要求等結果回傳後才可再查，且每日 03:40–04:30 維護）
 *  - cancel_shipment = TransDataCancel_Json（edelno + epino）
 *  - create_return   = R_TransData_Json（退貨人 = 顧客、edelno2 = 原貨號；回傳大寫 key）
 *  - 選店 UNSUPPORTED；無貨態推送 → handle_status_callback 回 200 no-op；不做背景輪詢（ASM-7）
 *  - test_connection = QueryEDELNO_Json（唯讀）驗證公司名稱 / 密碼
 *
 * 失敗一律 throw HctLogisticsException / WaybillNumberException（帶 ErrorCode），建單 / 退貨失敗另寫 order note。
 *
 * @see .claude/skills/hct-logistics/SKILL.md
 * @see zenbu apps/api-gateway/src/commerce/shipping/hct/hct-logistics.service.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Services;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\TransDataParams;
use J7\PowerCheckout\Domains\Logistics\Hct\Http\HctSoapClient;
use J7\PowerCheckout\Domains\Logistics\Hct\Http\HctTrackClient;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Enums\HctProductType;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLabelImage;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctStatusMapper;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctTrackCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\Mod7CheckDigitFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsConnectionTest;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 新竹物流服務提供者 */
final class HctLogisticsProvider extends BaseService implements ILogisticsProvider, ISupportsConnectionTest {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Provider ID（對應 woocommerce_hct_logistics_settings option） */
	public const ID = 'hct_logistics';

	/** @var int 貨況查詢快取秒數 */
	public const STATUS_CACHE_TTL = 600;

	/** @var string 貨況快取 transient 前綴 */
	public const STATUS_CACHE_PREFIX = 'pc_hct_logistics_status_';

	/** @var string ping 查詢用的訂單編號（不存在的單，唯讀） */
	private const PING_EPINO = 'PCPING';

	/** Constructor */
	public function __construct() {
		$settings                 = HctLogisticsSettingsDTO::instance();
		$this->id                 = self::ID;
		$this->enabled            = $settings->enabled;
		$this->method_title       = \__( '新竹物流', 'power_checkout' );
		$this->method_description = \__( '新竹物流 EDI 直串：宅配建單、列印標籤、查詢貨況、取消託運單與退貨。', 'power_checkout' );
	}

	/** 註冊 hooks（不論啟用與否：設定儲存時同步號段） @return void */
	public static function register_hooks(): void {
		$option = ProviderUtils::get_option_name( self::ID );
		\add_action( "add_option_{$option}", [ __CLASS__, 'sync_waybill_range' ], 10, 0 );
		\add_action( "update_option_{$option}", [ __CLASS__, 'sync_waybill_range' ], 10, 0 );
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
			HctLogisticsSettingsDTO::instance()->to_array(),
			[
				'id'                     => self::ID,
				'icon'                   => Plugin::$url . '/inc/assets/images/icons/hct.png',
				'method_title'           => \__( '新竹物流', 'power_checkout' ),
				'method_description'     => \__( '新竹物流 EDI 直串，宅配到府、列印標籤、查詢貨況。', 'power_checkout' ),
				'waybill_status'         => self::waybill_allocator()->status(),
				'track_crypto_available' => HctTrackCrypto::is_available(),
			]
		);
	}

	/** @return WaybillNumberAllocator 新竹號段配號器（9 碼本體 + MOD 7） */
	public static function waybill_allocator(): WaybillNumberAllocator {
		return new WaybillNumberAllocator( self::ID, new Mod7CheckDigitFormat( 9 ) );
	}

	/**
	 * 設定儲存後同步號段到配號器（never-throw；設定不合法時保留原號段）
	 *
	 * @return void
	 */
	public static function sync_waybill_range(): void {
		HctLogisticsSettingsDTO::reset();
		$settings = HctLogisticsSettingsDTO::instance();
		try {
			if ($settings->has_waybill_range()) {
				self::waybill_allocator()->sync_range( (int) $settings->waybill_start, (int) $settings->waybill_end );
			} else {
				self::waybill_allocator()->clear_range();
			}
		} catch ( \Throwable $e ) {
			Plugin::logger( '新竹物流號段同步失敗', 'warning', [ 'error' => $e->getMessage() ] );
		}
	}

	/** @return LogisticsProviderMetaDTO 宅配 only；選店不支援 */
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
				can_cancel: true,
			),
		);
	}

	/** @return array<int, string> 結帳可選子類型（只有宅配） */
	public function get_supported_methods(): array {
		return [ DeliveryMethod::HOME->value ];
	}

	// region 不支援的操作

	/**
	 * 選店（不支援：新竹純宅配）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 * @throws HctLogisticsException UNSUPPORTED
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		throw HctLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 解析選店回呼（不支援）
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 * @throws HctLogisticsException UNSUPPORTED
	 */
	public function parse_store_selection( array $raw ): array {
		throw HctLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 貨態推送（新竹無推送，回 200 no-op）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'ignored',
				'message' => '新竹物流沒有貨態推送，請於後台查詢貨況',
				'data'    => null,
			],
			200
		);
	}

	// endregion

	// region create_shipment / print_document

	/**
	 * 建立宅配託運單（TransData_Json）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / arrival_station / has_label（冪等時另含 idempotent=true）
	 * @throws HctLogisticsException 驗證 / API 失敗
	 * @throws WaybillNumberException 號段不合法 / 用罄
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta = new HctLogisticsMetaKeys( $order );
		if ('' !== $meta->get_ref()) {
			return [
				'logistics_id'    => $meta->get_ref(),
				'arrival_station' => (string) $order->get_meta( HctLogisticsMetaKeys::ARRIVAL_STATION_KEY ),
				'has_label'       => '' !== $meta->get_label(),
				'idempotent'      => true,
			];
		}

		try {
			$settings = $this->settings_with_credentials();
			$esdate   = self::today();
			// 先驗證欄位（不消耗號碼），通過後才配號
			TransDataParams::from_order( $order, $settings, $esdate );
			$edelno = $settings->has_waybill_range() ? $this->allocate( $settings ) : '';
			$params = TransDataParams::from_order( $order, $settings, $esdate, $edelno )->to_api_row();
			$row    = $this->first_row( ( new HctSoapClient( $settings ) )->trans_data( [ $params ] ), 'TransData' );
		} catch ( HctLogisticsException | WaybillNumberException $e ) {
			$order->add_order_note( \sprintf( '❌ 新竹物流建單失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$ref = '' !== ( $row['edelno'] ?? '' ) ? $row['edelno'] : $edelno;
		if ('' === $ref) {
			throw new HctLogisticsException( '新竹物流建單回應缺少貨號（edelno）' );
		}

		$label = '';
		if ('' !== ( $row['image'] ?? '' )) {
			try {
				$label = HctLabelImage::from_hex( $row['image'] )->to_base64();
			} catch ( HctLogisticsException $e ) {
				Plugin::logger( '新竹物流標籤圖無法解析', 'warning', [ 'order_id' => $order->get_id() ] );
			}
		}

		$arrival = (string) ( $row['erstno'] ?? '' );
		$meta->save_shipment( $ref, $params['epino'], $esdate, $arrival, $label );
		$order->add_order_note(
			\sprintf(
				'新竹物流託運單已建立：%1$s（到著站 %2$s，%3$s）%4$s',
				$ref,
				'' !== $arrival ? $arrival : '-',
				HctProductType::from( $params['eprdcl2'] )->label(),
				'' === $label ? '，⚠️ 未取得標籤圖，請至新竹物流後台列印' : ''
			)
		);

		return [
			'logistics_id'    => $ref,
			'arrival_station' => $arrival,
			'has_label'       => '' !== $label,
		];
	}

	/**
	 * 列印標籤（建單時存下的標籤圖 → HTML，不再打 API）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string HTML
	 * @throws HctLogisticsException CONFLICT 尚未建單 / 無標籤圖
	 */
	public function print_document( \WC_Order $order ): string {
		$meta = new HctLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw HctLogisticsException::conflict( '尚未成立物流單，無法列印標籤' );
		}
		if ('' === $meta->get_label()) {
			throw HctLogisticsException::conflict( '建單時未取得新竹物流標籤圖，請至新竹物流後台列印託運單' );
		}

		$src   = \esc_attr( HctLabelImage::from_base64( $meta->get_label() )->to_data_url() );
		$title = \esc_html( "新竹物流託運單 {$ref}" );
		$alt   = \esc_attr( $ref );

		return <<<HTML
<!doctype html>
<html lang="zh-Hant-TW">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>body{margin:0;text-align:center}img{max-width:100%;image-rendering:pixelated}@media print{@page{margin:0}}</style>
</head>
<body onload="window.print()">
<img src="{$src}" alt="{$alt}">
</body>
</html>
HTML;
	}

	// endregion

	// region query_shipment

	/**
	 * 查詢貨況（searchGoods；10 分鐘快取；未設定查貨參數 → degrade pending）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / status / status_name / unified_state / status_time / history / cached（/ degraded / reason）
	 * @throws HctLogisticsException 尚未建單 / 查詢失敗
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new HctLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw HctLogisticsException::conflict( '尚未成立物流單，無法查詢貨況' );
		}

		$settings = HctLogisticsSettingsDTO::instance();
		if (!$settings->has_track_query()) {
			$last = $meta->get_status();
			return [
				'logistics_id'  => $ref,
				'status'        => $last['status'],
				'status_name'   => '' !== $last['status_name'] ? $last['status_name'] : '未設定貨況查詢參數（需向新竹物流申請查貨服務）',
				'unified_state' => '' !== $last['unified_state'] ? $last['unified_state'] : ShippingState::PENDING->value,
				'status_time'   => $last['status_time'],
				'history'       => [],
				'cached'        => false,
				'degraded'      => true,
				'reason'        => 'track_query_not_configured',
			];
		}

		$cache_key = self::STATUS_CACHE_PREFIX . $ref;
		$cached    = \get_transient( $cache_key );
		if (\is_array( $cached )) {
			return \array_merge( $cached, [ 'cached' => true ] );
		}

		$result   = self::build_status_result( $ref, ( new HctTrackClient( $settings ) )->query( $ref ) );
		$previous = $meta->get_status()['status'];
		$meta->save_status( $result['status'], $result['status_name'], $result['unified_state'], $result['status_time'] );
		if ('' !== $result['status'] && $previous !== $result['status']) {
			$order->add_order_note( \sprintf( '新竹物流貨況更新：%1$s %2$s（%3$s）', $result['status'], $result['status_name'], $ref ) );
		}

		\set_transient( $cache_key, $result, self::STATUS_CACHE_TTL );
		return \array_merge( $result, [ 'cached' => false ] );
	}

	/**
	 * 由貨況歷程組結果（最新一筆 = wrktime 最大；無資料 → pending）
	 *
	 * @param string                                                                                    $ref    貨號
	 * @param array<int, array{wrktime: string, status: string, status_code: string, category: string}> $events 貨況
	 * @return array{logistics_id: string, status: string, status_name: string, unified_state: string, status_time: string, history: array<int, array{wrktime: string, status: string, status_code: string, category: string}>}
	 */
	private static function build_status_result( string $ref, array $events ): array {
		if ([] === $events) {
			return [
				'logistics_id'  => $ref,
				'status'        => '',
				'status_name'   => '尚無貨況（尚未集貨）',
				'unified_state' => ShippingState::PENDING->value,
				'status_time'   => '',
				'history'       => [],
			];
		}

		\usort( $events, static fn( array $a, array $b ): int => \strcmp( $b['wrktime'], $a['wrktime'] ) );
		$latest = $events[0];

		return [
			'logistics_id'  => $ref,
			'status'        => \strtoupper( \trim( $latest['status_code'] ) ),
			'status_name'   => $latest['status'],
			'unified_state' => HctStatusMapper::map( $latest['status_code'], $latest['status'] )->value,
			'status_time'   => $latest['wrktime'],
			'history'       => $events,
		];
	}

	// endregion

	// region cancel / return

	/**
	 * 取消託運單（TransDataCancel_Json）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / cancelled（已取消時另含 idempotent=true）
	 * @throws HctLogisticsException 尚未建單 / API 失敗
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		$meta = new HctLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw HctLogisticsException::conflict( '尚未成立物流單，無法取消託運單' );
		}
		if (ShippingState::CANCELLED->value === $meta->get_status()['unified_state']) {
			return [
				'logistics_id' => $ref,
				'cancelled'    => true,
				'idempotent'   => true,
			];
		}

		$settings = $this->settings_with_credentials();
		$this->first_row(
			( new HctSoapClient( $settings ) )->trans_data_cancel(
				[
					[
						'edelno' => $ref,
						'epino'  => $meta->get_epino(),
					],
				]
			),
			'TransDataCancel'
		);

		$meta->save_status( 'CANCELLED', '已取消託運單', ShippingState::CANCELLED->value, \wp_date( 'Y/m/d H:i' ) ?: '' );
		\delete_transient( self::STATUS_CACHE_PREFIX . $ref );
		$order->add_order_note( \sprintf( '新竹物流託運單已取消：%s', $ref ) );

		return [
			'logistics_id' => $ref,
			'cancelled'    => true,
		];
	}

	/**
	 * 建立退貨單（R_TransData_Json）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（未使用）
	 * @return array<string, mixed> return_logistics_id（冪等時另含 idempotent=true）
	 * @throws HctLogisticsException 尚未建單 / 驗證 / API 失敗
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		$meta = new HctLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw HctLogisticsException::conflict( '尚未成立物流單，無法建立退貨單' );
		}
		if ('' !== $meta->get_return_ref()) {
			return [
				'return_logistics_id' => $meta->get_return_ref(),
				'idempotent'          => true,
			];
		}

		try {
			$settings = $this->settings_with_credentials();
			$params   = TransDataParams::return_from( $order, $settings, $ref, self::today() )->to_api_row();
			$row      = $this->first_row( ( new HctSoapClient( $settings ) )->r_trans_data( [ $params ] ), 'R_TransData' );
		} catch ( HctLogisticsException $e ) {
			$order->add_order_note( \sprintf( '❌ 新竹物流退貨單建立失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$return_ref = (string) ( $row['edelno'] ?? '' );
		if ('' === $return_ref) {
			throw new HctLogisticsException( '新竹物流退貨單回應缺少貨號（edelno）' );
		}

		$meta->save_return_ref( $return_ref );
		$order->add_order_note( \sprintf( '新竹物流退貨單已建立：%1$s（原貨號 %2$s）', $return_ref, $ref ) );

		return [ 'return_logistics_id' => $return_ref ];
	}

	// endregion

	// region test_connection

	/**
	 * 測試連線：以已儲存帳密呼叫 QueryEDELNO_Json 查一個不存在的訂單編號（唯讀）
	 *
	 * 「查無資料」= 帳密正確；「公司名稱或密碼錯誤」/「「測試環境」請勿使用正式公司名稱或密碼」→ AUTH。
	 *
	 * @return array<string, mixed> provider / mode / endpoint / waybill_status / track_query_configured / track_crypto_available
	 * @throws HctLogisticsException 帳密缺漏 / 驗證失敗 / API 失敗
	 */
	public function test_connection(): array {
		$settings = HctLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials()) {
			throw HctLogisticsException::validation( '請先填寫並儲存新竹物流公司名稱與密碼' );
		}

		$rows    = ( new HctSoapClient( $settings ) )->query_edelno( [ self::PING_EPINO ] );
		$err_msg = (string) ( $rows[0]['ErrMsg'] ?? '' );
		if (\str_contains( $err_msg, '密碼' )) {
			$e = HctLogisticsException::from_err_msg( 'QueryEDELNO', $err_msg );
			if (\str_contains( $err_msg, '測試環境' )) {
				throw new HctLogisticsException( $e->getMessage() . '（此帳密屬正式環境，請切換為正式站或向新竹申請測試帳號）', $e->error_code, $e->raw_code );
			}
			throw $e;
		}

		return [
			'provider'               => self::ID,
			'mode'                   => $settings->mode,
			'endpoint'               => $settings->endpoint(),
			'waybill_status'         => self::waybill_allocator()->status(),
			'track_query_configured' => $settings->has_track_query(),
			'track_crypto_available' => HctTrackCrypto::is_available(),
		];
	}

	// endregion

	// region helpers

	/**
	 * 取得設定並確認帳密齊全
	 *
	 * @return HctLogisticsSettingsDTO
	 * @throws HctLogisticsException VALIDATION 帳密缺漏
	 */
	private function settings_with_credentials(): HctLogisticsSettingsDTO {
		$settings = HctLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials()) {
			throw HctLogisticsException::validation( '請先於新竹物流設定頁填寫公司名稱與密碼' );
		}
		return $settings;
	}

	/**
	 * 同步設定號段後原子配號
	 *
	 * @param HctLogisticsSettingsDTO $settings 設定
	 * @return string 10 碼貨號
	 * @throws WaybillNumberException 號段不合法 / 用罄
	 */
	private function allocate( HctLogisticsSettingsDTO $settings ): string {
		$allocator = self::waybill_allocator();
		$allocator->sync_range( (int) $settings->waybill_start, (int) $settings->waybill_end );
		return $allocator->allocate();
	}

	/**
	 * 取第一筆結果並判斷 success（N → 依 ErrMsg 分類拋例外）
	 *
	 * @param array<int, array<string, string>> $rows      結果
	 * @param string                            $operation 操作名稱
	 * @return array<string, string>
	 * @throws HctLogisticsException 無結果 / success=N
	 */
	private function first_row( array $rows, string $operation ): array {
		$row = $rows[0] ?? null;
		if (null === $row) {
			throw new HctLogisticsException( "新竹物流 {$operation} 回應為空" );
		}
		if ('N' === \strtoupper( $row['success'] ?? '' )) {
			throw HctLogisticsException::from_err_msg( $operation, (string) ( $row['ErrMsg'] ?? '' ) );
		}
		return $row;
	}

	/** @return string 台北今天 yyyyMMdd */
	private static function today(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );
	}

	// endregion
}
