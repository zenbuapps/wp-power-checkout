<?php
/**
 * 嘉里大榮物流（Kerry TJ，EDIEntrance）物流服務提供者 `kerrytj_logistics`
 *
 * 純宅配（無門市地圖）：
 *  - create_shipment = ShipmentDelivery：溫層（結帳 meta → 設定預設）決定常溫 / 低溫號段，P5 WaybillNumberAllocator 原子配號
 *                      （key = `kerrytj_logistics_{mode}_{ambient|cold}`，測試機進度不吃正式號段）。
 *                      配號後**先記在訂單**（pending BLN）再呼叫 API：失敗重試沿用同號、號碼不回收；
 *                      沿用號重送回「託運單號重複」= 上次（多半逾時）其實已建成 → 視為成功。寫 ref / 34 欄快照 / 到著站
 *  - print_document  = Report/GetLabelPdf（單一物件，取自建單快照，同環境帳密）→ PDF
 *  - print_detail    = Report/GetDetailPdf（託運明細表）→ PDF（嘉里專屬 admin REST）
 *  - update_shipment = UpdateShipmentDelivery（同 BLN / 出貨日 / 溫層，以目前訂單地址重組）；已交寄（貨況非 pending）→ CONFLICT（嘉里專屬 admin REST）
 *  - query_shipment  = 常溫 BLNListTracing / 低溫 ColdBLNListTracing（依單號長度分流）→ ShippingState；無快取、無背景輪詢（ASM-7）
 *  - create_return   = PickupV2（常溫取件；另配常溫號段單號，同建單的 pending 沿用機制）
 *  - cancel / 選店 → UNSUPPORTED（規格書與 Help Page 皆無取消 API）；無貨態推送 → handle_status_callback 200 no-op
 *  - test_connection = UpdateShipmentDelivery 空陣列驗 Basic Auth（不寫入任何資料）
 *  - 貨到付款不支援（代收金額算錯 = 向顧客多收 / 少收，zenbu 同採不開）
 *
 * 失敗一律 throw KerrytjLogisticsException / WaybillNumberException（帶 ErrorCode），建單 / 修改 / 取件失敗另寫 order note。
 *
 * @see .claude/skills/kerrytj-logistics/SKILL.md
 * @see zenbu apps/api-gateway/src/commerce/shipping/kerrytj/kerrytj-logistics.service.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Services;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\PickupParams;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\ShipmentDeliveryParams;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Http\KerrytjApiClient;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjTemperature;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjStatusMapper;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsConnectionTest;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 嘉里大榮服務提供者 */
final class KerrytjLogisticsProvider extends BaseService implements ILogisticsProvider, ISupportsConnectionTest {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Provider ID（對應 woocommerce_kerrytj_logistics_settings option） */
	public const ID = 'kerrytj_logistics';

	/** Constructor */
	public function __construct() {
		$settings                 = KerrytjLogisticsSettingsDTO::instance();
		$this->id                 = self::ID;
		$this->enabled            = $settings->enabled;
		$this->method_title       = \__( '嘉里大榮', 'power_checkout' );
		$this->method_description = \__( '嘉里大榮物流 EDI 直串：宅配建單（常溫 / 冷藏 / 冷凍）、修改、列印標籤與託運明細、查詢貨況、退貨取件。', 'power_checkout' );
	}

	/** 註冊 hooks（不論啟用與否：設定儲存時同步號段） @return void */
	public static function register_hooks(): void {
		$option = ProviderUtils::get_option_name( self::ID );
		\add_action( "add_option_{$option}", [ __CLASS__, 'sync_waybill_ranges' ], 10, 0 );
		\add_action( "update_option_{$option}", [ __CLASS__, 'sync_waybill_ranges' ], 10, 0 );
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

		$settings = KerrytjLogisticsSettingsDTO::instance();
		return \array_merge(
			$settings->to_array(),
			[
				'id'                     => self::ID,
				'icon'                   => '',
				'method_title'           => \__( '嘉里大榮', 'power_checkout' ),
				'method_description'     => \__( '嘉里大榮物流 EDI 直串，宅配到府（常溫 / 冷藏 / 冷凍）、列印標籤、查詢貨況。', 'power_checkout' ),
				'ambient_waybill_status' => self::waybill_allocator( KerrytjBlnKind::AMBIENT, $settings->mode )->status(),
				'cold_waybill_status'    => self::waybill_allocator( KerrytjBlnKind::COLD, $settings->mode )->status(),
			]
		);
	}

	/**
	 * 號段配號器（key 分環境 × 種類）
	 *
	 * @param KerrytjBlnKind $kind 種類
	 * @param string         $mode 環境 test / prod
	 * @return WaybillNumberAllocator
	 */
	public static function waybill_allocator( KerrytjBlnKind $kind, string $mode ): WaybillNumberAllocator {
		$mode = 'prod' === $mode ? 'prod' : 'test';
		return new WaybillNumberAllocator( self::ID . "_{$mode}_{$kind->value}", KerrytjBln::format( $kind ) );
	}

	/**
	 * 設定儲存後同步目前環境的常溫 / 低溫號段到配號器（never-throw；設定不合法時保留原號段）
	 *
	 * @return void
	 */
	public static function sync_waybill_ranges(): void {
		KerrytjLogisticsSettingsDTO::reset();
		$settings = KerrytjLogisticsSettingsDTO::instance();
		foreach ( KerrytjBlnKind::cases() as $kind ) {
			try {
				$range     = $settings->range( $kind );
				$allocator = self::waybill_allocator( $kind, $settings->mode );
				if (null === $range) {
					$allocator->clear_range();
				} else {
					$allocator->sync_range( $range['start'], $range['end'] );
				}
			} catch ( \Throwable $e ) {
				Plugin::logger(
					'嘉里大榮號段同步失敗',
					'warning',
					[
						'kind'  => $kind->value,
						'error' => $e->getMessage(),
					]
					);
			}
		}
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
	 * 選店（不支援：嘉里大榮直串僅宅配）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 * @throws KerrytjLogisticsException UNSUPPORTED
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		throw KerrytjLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 解析選店回呼（不支援）
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 * @throws KerrytjLogisticsException UNSUPPORTED
	 */
	public function parse_store_selection( array $raw ): array {
		throw KerrytjLogisticsException::unsupported( '超商選店' );
	}

	/**
	 * 取消託運單（不支援：規格書與 Help Page 皆無取消 API；未交寄的單不交貨即可，或洽營業所）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws KerrytjLogisticsException UNSUPPORTED
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		throw KerrytjLogisticsException::unsupported( '取消託運單（嘉里大榮未提供取消 API，未交寄的單不交貨即可，或洽營業所）' );
	}

	/**
	 * 貨態推送（嘉里大榮無推送，回 200 no-op）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'ignored',
				'message' => '嘉里大榮沒有貨態推送，請於後台查詢貨況',
				'data'    => null,
			],
			200
		);
	}

	// endregion

	// region create_shipment

	/**
	 * 建立宅配託運單（ShipmentDelivery）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / temperature / station（冪等時另含 idempotent=true）
	 * @throws KerrytjLogisticsException 驗證 / API 失敗
	 * @throws WaybillNumberException 號段用罄 / 未設定
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta = new KerrytjLogisticsMetaKeys( $order );
		if ('' !== $meta->get_ref()) {
			return [
				'logistics_id' => $meta->get_ref(),
				'temperature'  => $meta->get_temperature(),
				'station'      => $meta->get_station(),
				'idempotent'   => true,
			];
		}

		try {
			$settings    = $this->settings_with_credentials();
			$temperature = $this->temperature_of( $order, $settings );
			$kind        = $temperature->bln_kind();
			if ('cod' === $order->get_payment_method()) {
				throw KerrytjLogisticsException::validation( '嘉里大榮物流目前不支援貨到付款，請改用其他物流方式或改為不代收出貨' );
			}
			$ship_date = self::today();
			$range     = $this->range_or_throw( $settings, $kind );

			$pending = $meta->get_pending_bln();
			$reused  = '' !== $pending && KerrytjBln::in_range( $pending, $kind, $range['start'], $range['end'] );
			// 先以佔位單號驗證全部欄位（不消耗號碼），通過後才配號
			$bln = $reused ? $pending : KerrytjBln::format( $kind )->format( $range['start'] );
			ShipmentDeliveryParams::from_order( $order, $settings, $temperature, $ship_date, $bln );
			if (!$reused) {
				$bln = $this->allocate( $settings, $kind );
				$meta->save_pending_bln( $bln );
			}
			$row = ShipmentDeliveryParams::from_order( $order, $settings, $temperature, $ship_date, $bln )->to_api_row();

			$station = '';
			try {
				$area    = ( new KerrytjApiClient( $settings ) )->shipment_delivery( $row );
				$station = \is_scalar( $area['StationName'] ?? null ) ? (string) $area['StationName'] : '';
			} catch ( KerrytjLogisticsException $e ) {
				if (!$reused || !$e->is_duplicate_bln()) {
					throw $e;
				}
				$order->add_order_note( \sprintf( '嘉里大榮回報「託運單號重複」（%s）；此為沿用上次配發的單號重送，視為上次已建立成功（嘉里大榮端資料以上次送出為準）', $bln ) );
			}
		} catch ( KerrytjLogisticsException | WaybillNumberException $e ) {
			$order->add_order_note( \sprintf( '❌ 嘉里大榮建單失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_shipment( $bln, $settings->mode, $row, $station );
		$order->add_order_note(
			\sprintf(
				'嘉里大榮託運單已建立：%1$s（%2$s%3$s）',
				$bln,
				$temperature->label(),
				'' !== $station ? "，到著站 {$station}" : ''
			)
		);

		return [
			'logistics_id' => $bln,
			'temperature'  => $temperature->value,
			'station'      => $station,
		];
	}

	// endregion

	// region print / detail / update

	/**
	 * 列印地址標籤（GetLabelPdf，取自建單快照）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PDF bytes
	 * @throws KerrytjLogisticsException 尚未建單 / 環境不符 / API 失敗
	 */
	public function print_document( \WC_Order $order ): string {
		[ $settings, $row ] = $this->shipped_snapshot( $order, '列印標籤' );
		return ( new KerrytjApiClient( $settings ) )->label_pdf( ShipmentDeliveryParams::label_fields( $row ) );
	}

	/**
	 * 列印託運明細表（GetDetailPdf，取自建單快照）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PDF bytes
	 * @throws KerrytjLogisticsException 尚未建單 / 環境不符 / API 失敗
	 */
	public function print_detail( \WC_Order $order ): string {
		[ $settings, $row ] = $this->shipped_snapshot( $order, '列印託運明細' );
		return ( new KerrytjApiClient( $settings ) )->detail_pdf( [ ShipmentDeliveryParams::detail_fields( $row ) ] );
	}

	/**
	 * 修改託運資料（UpdateShipmentDelivery；同 BLN / 出貨日 / 溫層，以目前訂單資料重組 34 欄）
	 *
	 * 已交站所則嘉里大榮不允許修改：本地貨況已非 pending → CONFLICT 不打 API。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / updated
	 * @throws KerrytjLogisticsException 尚未建單 / 已交寄 / 驗證 / API 失敗
	 */
	public function update_shipment( \WC_Order $order ): array {
		[ $settings, $previous ] = $this->shipped_snapshot( $order, '修改託運資料' );
		$meta                    = new KerrytjLogisticsMetaKeys( $order );
		$state                   = $meta->get_status()['unified_state'];
		if ('' !== $state && ShippingState::PENDING->value !== $state) {
			throw KerrytjLogisticsException::conflict( \sprintf( '嘉里大榮託運單 %1$s 已交寄（貨況：%2$s），無法修改', $meta->get_ref(), $meta->get_status()['status_name'] ) );
		}

		try {
			$temperature = KerrytjTemperature::tryFrom( $previous['Temperature'] ?? '' ) ?? KerrytjTemperature::AMBIENT;
			$row         = ShipmentDeliveryParams::from_order( $order, $settings, $temperature, $previous['ShipDate'] ?? self::today(), $meta->get_ref() )->to_api_row();
			( new KerrytjApiClient( $settings ) )->update_shipment_delivery( $row );
		} catch ( KerrytjLogisticsException $e ) {
			$order->add_order_note( \sprintf( '❌ 嘉里大榮修改託運資料失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_shipment_row( $row );
		$order->add_order_note( \sprintf( '嘉里大榮託運資料已修改：%s', $meta->get_ref() ) );

		return [
			'logistics_id' => $meta->get_ref(),
			'updated'      => true,
		];
	}

	// endregion

	// region query_shipment

	/**
	 * 查詢貨況（常溫 / 低溫依單號長度分流）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / status / status_name / unified_state / status_time / history / cached
	 * @throws KerrytjLogisticsException 尚未建單 / 查詢失敗
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new KerrytjLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw KerrytjLogisticsException::conflict( '尚未成立物流單，無法查詢貨況' );
		}
		$kind = KerrytjBln::kind_of( $ref ) ?? KerrytjBlnKind::AMBIENT;

		$events = ( new KerrytjApiClient( $this->settings_with_credentials() ) )->tracing( $ref );
		\usort( $events, static fn( array $a, array $b ): int => \strcmp( $b['date'] . $b['time'], $a['date'] . $a['time'] ) );

		$latest = null;
		$state  = null;
		foreach ( $events as $event ) {
			$state = KerrytjStatusMapper::map( $kind, $event['status_code_type'], $event['status_code'] );
			if (null !== $state) {
				$latest = $event;
				break;
			}
		}

		$previous = $meta->get_status();
		if (null === $latest || null === $state) {
			$result = [
				'logistics_id'  => $ref,
				'status'        => $previous['status'],
				'status_name'   => [] === $events ? '尚無貨況（尚未集貨）' : $previous['status_name'],
				'unified_state' => '' !== $previous['unified_state'] ? $previous['unified_state'] : ShippingState::PENDING->value,
				'status_time'   => $previous['status_time'],
			];
		} else {
			$result = [
				'logistics_id'  => $ref,
				'status'        => \strtoupper( $latest['status_code'] ),
				'status_name'   => $latest['status'],
				'unified_state' => $state->value,
				'status_time'   => \trim( "{$latest['date']} {$latest['time']}" ),
			];
			$meta->save_status( $result['status'], $result['status_name'], $result['unified_state'], $result['status_time'] );
			if ($previous['status'] !== $result['status'] || $previous['status_time'] !== $result['status_time']) {
				$order->add_order_note( \sprintf( '嘉里大榮貨況更新：%1$s %2$s（%3$s）', $result['status'], $result['status_name'], $ref ) );
			}
		}

		return \array_merge(
			$result,
			[
				'kind'    => $kind->value,
				'history' => $events,
				'cached'  => false,
			]
		);
	}

	// endregion

	// region return

	/**
	 * 建立退貨取件單（PickupV2，常溫；顧客為取件人、商店為收貨人）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（未使用）
	 * @return array<string, mixed> return_logistics_id（冪等時另含 idempotent=true）
	 * @throws KerrytjLogisticsException 尚未建單 / 驗證 / API 失敗
	 * @throws WaybillNumberException 常溫號段用罄
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		$meta = new KerrytjLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw KerrytjLogisticsException::conflict( '尚未成立物流單，無法建立退貨取件單' );
		}
		if ('' !== $meta->get_return_ref()) {
			return [
				'return_logistics_id' => $meta->get_return_ref(),
				'idempotent'          => true,
			];
		}

		try {
			$settings = $this->settings_with_credentials();
			$range    = $this->range_or_throw( $settings, KerrytjBlnKind::AMBIENT );
			$remark   = "原單 {$ref}";
			$pending  = $meta->get_return_pending_bln();
			$reused   = '' !== $pending && KerrytjBln::in_range( $pending, KerrytjBlnKind::AMBIENT, $range['start'], $range['end'] );
			$bln      = $reused ? $pending : KerrytjBln::format( KerrytjBlnKind::AMBIENT )->format( $range['start'] );
			PickupParams::from_order( $order, $settings, $bln, $remark );
			if (!$reused) {
				$bln = $this->allocate( $settings, KerrytjBlnKind::AMBIENT );
				$meta->save_return_pending_bln( $bln );
			}
			$row = PickupParams::from_order( $order, $settings, $bln, $remark )->to_api_row();

			try {
				( new KerrytjApiClient( $settings ) )->pickup( $row );
			} catch ( KerrytjLogisticsException $e ) {
				if (!$reused || !$e->is_duplicate_bln()) {
					throw $e;
				}
				$order->add_order_note( \sprintf( '嘉里大榮回報「託運單號重複」（取件單號 %s）；此為沿用上次配發的單號重送，視為上次已建立成功', $bln ) );
			}
		} catch ( KerrytjLogisticsException | WaybillNumberException $e ) {
			$order->add_order_note( \sprintf( '❌ 嘉里大榮退貨取件單建立失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_return_ref( $bln );
		$order->add_order_note( \sprintf( '嘉里大榮退貨取件單已建立：%1$s（原單 %2$s；中午 12 點後送出為隔日派遣）', $bln, $ref ) );

		return [ 'return_logistics_id' => $bln ];
	}

	// endregion

	// region test_connection

	/**
	 * 測試連線：以已儲存帳密 POST UpdateShipmentDelivery 空陣列驗證 Basic Auth（不寫入任何資料）
	 *
	 * @return array<string, mixed> provider / mode / endpoint / customer_no_configured / missing_sender_fields / 號段狀態
	 * @throws KerrytjLogisticsException 帳密缺漏 / 401 / 連線失敗
	 */
	public function test_connection(): array {
		$settings = KerrytjLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials()) {
			throw KerrytjLogisticsException::validation( '請先填寫並儲存嘉里大榮帳號與密碼' );
		}

		( new KerrytjApiClient( $settings ) )->verify_credentials();

		return [
			'provider'               => self::ID,
			'mode'                   => $settings->mode,
			'endpoint'               => $settings->edi_base(),
			'customer_no_configured' => '' !== $settings->customer_no,
			'missing_sender_fields'  => $settings->missing_sender_fields(),
			'ambient_waybill_status' => self::waybill_allocator( KerrytjBlnKind::AMBIENT, $settings->mode )->status(),
			'cold_waybill_status'    => self::waybill_allocator( KerrytjBlnKind::COLD, $settings->mode )->status(),
		];
	}

	// endregion

	// region helpers

	/**
	 * 取得設定並確認帳密與客編齊全
	 *
	 * @return KerrytjLogisticsSettingsDTO
	 * @throws KerrytjLogisticsException VALIDATION 帳密 / 客編缺漏
	 */
	private function settings_with_credentials(): KerrytjLogisticsSettingsDTO {
		$settings = KerrytjLogisticsSettingsDTO::instance();
		if (!$settings->has_credentials() || '' === $settings->customer_no) {
			throw KerrytjLogisticsException::validation( '請先於嘉里大榮設定頁填寫帳號、密碼與客戶編號' );
		}
		return $settings;
	}

	/**
	 * 訂單溫層（結帳選用 → 設定預設）
	 *
	 * @param \WC_Order                   $order    訂單
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 * @return KerrytjTemperature
	 */
	private function temperature_of( \WC_Order $order, KerrytjLogisticsSettingsDTO $settings ): KerrytjTemperature {
		return KerrytjTemperature::tryFrom( ( new KerrytjLogisticsMetaKeys( $order ) )->get_temperature() )
		?? KerrytjTemperature::tryFrom( $settings->default_temperature )
		?? KerrytjTemperature::AMBIENT;
	}

	/**
	 * 某種類號段（未設定 → VALIDATION）
	 *
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 * @param KerrytjBlnKind              $kind     種類
	 * @return array{start: int, end: int}
	 * @throws KerrytjLogisticsException VALIDATION
	 */
	private function range_or_throw( KerrytjLogisticsSettingsDTO $settings, KerrytjBlnKind $kind ): array {
		$range = $settings->range( $kind );
		if (null === $range) {
			throw KerrytjLogisticsException::validation( "尚未設定嘉里大榮{$kind->label()}託運單號段，請至設定頁填寫嘉里大榮核發的號段" );
		}
		return $range;
	}

	/**
	 * 同步設定號段後原子配號
	 *
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 * @param KerrytjBlnKind              $kind     種類
	 * @return string 託運單號
	 * @throws WaybillNumberException 號段不合法 / 用罄
	 * @throws KerrytjLogisticsException 號段未設定
	 */
	private function allocate( KerrytjLogisticsSettingsDTO $settings, KerrytjBlnKind $kind ): string {
		$range     = $this->range_or_throw( $settings, $kind );
		$allocator = self::waybill_allocator( $kind, $settings->mode );
		$allocator->sync_range( $range['start'], $range['end'] );
		return $allocator->allocate();
	}

	/**
	 * 已建單訂單的設定與 34 欄快照（建單環境須與目前設定相同）
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $action 操作中文
	 * @return array{0: KerrytjLogisticsSettingsDTO, 1: array<string, string>}
	 * @throws KerrytjLogisticsException 尚未建單 / 快照遺失 / 環境不符 / 帳密缺漏
	 */
	private function shipped_snapshot( \WC_Order $order, string $action ): array {
		$meta = new KerrytjLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw KerrytjLogisticsException::conflict( "尚未成立物流單，無法{$action}" );
		}
		$row = $meta->get_shipment();
		if ([] === $row) {
			throw KerrytjLogisticsException::conflict( "嘉里大榮託運單 {$ref} 缺少建單資料，無法{$action}" );
		}
		$settings = $this->settings_with_credentials();
		$mode     = $meta->get_mode();
		if ('' !== $mode && $mode !== $settings->mode) {
			$label = static fn( string $m ): string => 'prod' === $m ? '正式' : '測試';
			throw new KerrytjLogisticsException(
				\sprintf( '嘉里大榮託運單 %1$s 建立於%2$s環境，目前設定為%3$s環境，無法%4$s；請暫時切回%2$s環境的帳密後再操作', $ref, $label( $mode ), $label( $settings->mode ), $action ),
				ErrorCode::CONFLICT
			);
		}
		return [ $settings, $row ];
	}

	/** @return string 台北今天 yyyyMMdd */
	private static function today(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );
	}

	// endregion
}
