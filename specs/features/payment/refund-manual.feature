# language: zh-TW
功能: 手動退款
  作為 網站管理員
  我想要 僅改變 WooCommerce 訂單狀態為 refunded 而不呼叫任何金流 API
  以便 在金流後台已手動處理退款的情況下同步 WooCommerce 狀態

  背景:
    假設 管理員已登入後台並取得 Nonce
    而且 系統中有以下訂單：
      | orderId | userId | total | status     | payment_method            |
      | 100     | 1      | 1000  | processing | shopline_payment_redirect |

  規則: 前置（參數）- order_id 必須是數字

    場景: order_id 不是數字
      當 管理員發送 POST /wp-json/power-checkout/v1/refund/manual，order_id 為 "abc"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "order_id must be numeric"

  規則: 前置（狀態）- 訂單必須存在

    場景: 找不到訂單
      當 管理員發送 POST /wp-json/power-checkout/v1/refund/manual，order_id 為 9999
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "order not found"

  規則: 後置（狀態）- 訂單狀態改為 refunded

    場景: 手動退款成功
      當 管理員發送 POST /wp-json/power-checkout/v1/refund/manual，order_id 為 100
      那麼 回應狀態碼為 200
      而且 回應 code 為 "success"
      而且 回應 message 包含 "手動退款成功"
      而且 訂單 #100 狀態為 "refunded"
      而且 不呼叫任何金流 API

  規則: 手動退款觸發 order note

    場景: WooCommerce 自動新增手動退款 order note
      假設 訂單 #100 狀態為 "processing"
      當 WooCommerce 觸發 woocommerce_order_refunded hook
      而且 該退款不是 API 退款（get_refunded_payment 為 false）
      那麼 訂單 #100 有 order note 包含 "手動退款"
      而且 order note 包含退款金額
