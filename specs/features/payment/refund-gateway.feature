# language: zh-TW
功能: Gateway 退款
  作為 網站管理員
  我想要 透過原付款 Gateway 自動退款
  以便 直接透過金流 API 返還顧客款項，並同步更新 WooCommerce 訂單狀態

  背景:
    假設 管理員已登入後台並取得 Nonce
    而且 "shopline_payment_redirect" 已啟用
    而且 系統中有以下訂單：
      | orderId | userId | total | status     | payment_method            |
      | 100     | 1      | 1000  | processing | shopline_payment_redirect |

  規則: 前置（參數）- order_id 必須是數字

    場景: order_id 不是數字
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 "abc"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "訂單編號必須是數字"

  規則: 前置（狀態）- 訂單必須存在

    場景: 找不到訂單
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 9999
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到訂單"

  規則: 前置（狀態）- 訂單必須有可退餘額

    場景: 訂單已全額退款
      假設 訂單 #100 已退款 1000 元（remaining_refund_amount = 0）
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 100
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "已經沒有餘額可退"

  規則: 前置（狀態）- Gateway 必須是 AbstractPaymentGateway 實例

    場景: 訂單使用非 Power Checkout 的 Gateway
      假設 系統中有訂單 #200，payment_method 為 "bacs"
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 200
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "不是 AbstractPaymentGateway 的實例"

  規則: 付款方式退款限制

    場景: ATM 虛擬帳號不支援退款
      假設 訂單 #100 付款方式為 VirtualAccount
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 100
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "不支援退款"

    場景: 中租零卡僅支援全額退款
      假設 訂單 #100 付款方式為 ChaileaseBNPL
      而且 訂單 #100 已退款 500 元（remaining_refund_amount = 500）
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 100
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "僅支援全額退款"

    場景: Apple Pay 僅支援全額退款
      假設 訂單 #100 付款方式為 ApplePay
      而且 訂單 #100 已退款 500 元（remaining_refund_amount = 500）
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 100
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "僅支援全額退款"

  規則: 信用卡退款

    場景: 信用卡全額退款成功
      假設 訂單 #100 付款方式為 CreditCard
      而且 SLP 退款 API 回傳成功（refundMsg.code 為 null）
      當 管理員發送 POST /wp-json/power-checkout/v1/refund，order_id 為 100
      那麼 回應狀態碼為 200
      而且 回應 message 包含 "退款成功"
      而且 系統呼叫 SLP API POST /trade/refund/create
      而且 訂單 #100 新增了 WC_Order_Refund 記錄
      而且 訂單 #100 有 order note 包含退款資訊 HTML
      而且 訂單 #100 的 tmp_refund_reason 已儲存
      而且 退款過程使用資料庫交易

  規則: LINE Pay 退款

    場景: LINE Pay 全額退款
      假設 訂單 #100 付款方式為 LINE Pay
      當 管理員選擇 "使用 Shopline Payment 自動退款" 且退款金額等於訂單總額
      那麼 系統呼叫 SLP API POST /trade/refund/create
      而且 退款成功

    場景: LINE Pay 部分退款
      假設 訂單 #100 付款方式為 LINE Pay
      當 管理員選擇 "使用 Shopline Payment 自動退款" 且退款金額小於訂單總額
      那麼 系統呼叫 SLP API POST /trade/refund/create
      而且 退款成功

  規則: 退款 API 失敗時回滾

    場景: SLP 退款 API 回傳失敗
      假設 訂單 #100 付款方式為 CreditCard
      而且 SLP 退款 API 回傳失敗（refundMsg.code 有值）
      當 Gateway handle_payment_gateway_refund 被觸發
      那麼 資料庫事務被 ROLLBACK
      而且 退款記錄被刪除
      而且 訂單 #100 有 order note 包含 "退款失敗"

  規則: 手動建立的退款記錄不走 Gateway

    場景: 手動退款不觸發 Gateway API
      假設 訂單 #100 有一筆手動退款（get_refunded_payment 為 false）
      當 woocommerce_order_refunded hook 被觸發
      那麼 不呼叫 SLP 退款 API
