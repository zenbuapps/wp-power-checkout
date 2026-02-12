<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\Country;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * Address 物流送貨地址
 * 請求會帶
 *  */
class Address extends DTO {
    /** @var string Country::value (2) *國家地區編碼，如 TW */
    public string $countryCode;
    
    /** @var string (12) 州或省代碼 */
    public string $stateCode = '';
    
    /** @var string (128) 州或省名稱 */
    public string $state = '';
    
    /** @var string (128) 城市名稱 */
    public string $city;
    
    /** @var string (128) 區域 */
    public string $district;
    
    /** @var string (128) *詳細街道地址 */
    public string $street;
    
    /** @var string (32) 郵政編碼 */
    public string $postcode;
    
    /** @var array<string> 必填屬性 */
    protected array $required_properties = [
        'countryCode',
        'street',
    ];
    
    /**
     * @param \WC_Order $order 訂單
     *
     * @return self 創建實例
     */
    public static function create( \WC_Order $order ): self {
        $street = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
        $country_code = $order->get_billing_country();
        $country_code = Country::tryFrom( $country_code )?->value ?? Country::TW->value;
        $args = [
            'countryCode' => $country_code,
            'city'        => ( new StrHelper( $order->get_billing_state(), 'billing_state', 128 ) )->substr()->value,
            'district'    => ( new StrHelper( $order->get_billing_city(), 'billing_city', 128 ) )->substr()->value,
            'street'      => ( new StrHelper( $street, 'street', 128 ) )->substr()->value,
            'postcode'    => ( new StrHelper( $order->get_billing_postcode(), 'billing_postcode', 32 ) )->substr(
            )->value,
        ];
        return new self( $args );
    }
    
    /**
     * 自訂驗證邏輯
     *
     * @throws \Exception 如果驗證失敗
     *  */
    protected function validate(): void {
        parent::validate();
        ( new StrHelper( $this->stateCode, 'stateCode', 12 ) )->get_strlen( true );
        ( new StrHelper( $this->state, 'state', 128 ) )->get_strlen( true );
        ( new StrHelper( $this->city, 'city', 128 ) )->get_strlen( true );
        ( new StrHelper( $this->district, 'district', 128 ) )->get_strlen( true );
        ( new StrHelper( $this->street, 'street', 128 ) )->get_strlen( true );
        ( new StrHelper( $this->postcode, 'postcode', 32 ) )->get_strlen( true );
    }
}
