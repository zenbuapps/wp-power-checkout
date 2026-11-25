<?php
/**
 * PAYUNi 黑貓宅配建單 home_delivery/trade（Ver 1.2）請求參數
 *
 * LgsType 固定 HOME、ShipType 固定 2（黑貓）。TradeAmt 30~20,000；ServiceType=3 時為商品價值。
 *
 * @see .claude/skills/payuni-logistics-v3/references/home-delivery-apis.md#trade-1.2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\DTOs;

use J7\WpUtils\Classes\DTO;

/** PAYUNi 黑貓宅配建單請求參數 */
final class HomeTradeParams extends DTO {

	/** @var int 宅配金額下限（官方規格） */
	public const MIN_AMOUNT = 30;

	/** @var int 宅配金額上限（官方規格） */
	public const MAX_AMOUNT = 20000;

	/** @var array<int, string> 合法的希望配達時段（01=13 時前 / 02=14-18 時 / 04=不指定） */
	public const DELIVERY_TIME_TAGS = [ '01', '02', '04' ];

	/** @var string 商店訂單編號（≤25；[A-Za-z0-9_-]；10 分鐘內不可重複） */
	public string $MerTradeNo = '';

	/** @var int 寄件型態（1=常溫，2=冷凍，3=冷藏） */
	public int $GoodsType = 1;

	/** @var string 物流型態（固定 HOME） */
	public string $LgsType = 'HOME';

	/** @var int 通路類別（固定 2=黑貓） */
	public int $ShipType = 2;

	/** @var int 訂單金額（取貨付款金額；ServiceType=3 時為商品價值） */
	public int $TradeAmt = 0;

	/** @var int 取件方式（1=取貨付款 / 3=取貨不付款） */
	public int $ServiceType = 3;

	/** @var string 希望配達時段（01 / 02 / 04） */
	public string $DeliveryTimeTag = '04';

	/** @var string 收件人姓名（≤30） */
	public string $Consignee = '';

	/** @var string 收件人手機（09 開頭半形數字） */
	public string $ConsigneeMobile = '';

	/** @var string 收件人地址（≤120；縣市 + 鄉鎮市區 + 路段 + 號） */
	public string $ConsigneeAddress = '';

	/** @var string 商品名稱（≤20；中文算 2） */
	public string $ProdDesc = '';

	/** @var string 宅配到付完成取件通知 URL（ServiceType=1 才觸發；僅 80/443 port） */
	public string $NotifyURL = '';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		foreach ( [ 'MerTradeNo', 'Consignee', 'ConsigneeMobile', 'ConsigneeAddress', 'ProdDesc' ] as $required ) {
			if ('' === \trim( (string) $this->{$required} )) {
				throw new \Exception( "HomeTradeParams: {$required} 必填" );
			}
		}

		if ($this->TradeAmt < self::MIN_AMOUNT || $this->TradeAmt > self::MAX_AMOUNT) {
			throw new \Exception(
				\sprintf( 'HomeTradeParams: 黑貓宅配金額須介於 %1$d ~ %2$d 元', self::MIN_AMOUNT, self::MAX_AMOUNT )
			);
		}

		if (!\in_array( $this->DeliveryTimeTag, self::DELIVERY_TIME_TAGS, true )) {
			throw new \Exception( 'HomeTradeParams: DeliveryTimeTag 必須為 01 / 02 / 04' );
		}
	}

	/**
	 * 轉為 PAYUNi 內層明文陣列（NotifyURL 留空時不送出）
	 *
	 * @return array<string, mixed>
	 */
	public function to_payuni_data(): array {
		$data = $this->to_array();
		if ('' === (string) ( $data['NotifyURL'] ?? '' )) {
			unset( $data['NotifyURL'] );
		}
		return $data;
	}

	/**
	 * 依「中 / 全形算 2、半形算 1」截斷字串（PAYUNi 黑貓欄位長度規則）
	 *
	 * @param string $value     原字串
	 * @param int    $max_width 最大寬度
	 * @return string
	 */
	public static function truncate_width( string $value, int $max_width ): string {
		$result = '';
		$width  = 0;
		foreach ( \mb_str_split( $value ) as $char ) {
			$char_width = \strlen( $char ) > 1 ? 2 : 1;
			if ($width + $char_width > $max_width) {
				break;
			}
			$result .= $char;
			$width  += $char_width;
		}
		return $result;
	}
}
