@ignore @command
Feature: 手動退款

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And 系統中有以下訂單：
      | orderId | userId | total | status     | payment_method            |
      | 100     | 1      | 1000  | processing | shopline_payment_redirect |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- order_id 必須是數字
    Example: order_id 不是數字
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund/manual
        | key      | value |
        | order_id | abc   |
      Then 回應狀態碼為 500
      And 回應包含 "order_id must be numeric"

  Rule: 前置（參數）- 訂單必須存在
    Example: 找不到訂單
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund/manual
        | key      | value |
        | order_id | 9999  |
      Then 回應狀態碼為 500
      And 回應包含 "order not found"

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 訂單狀態改為 refunded
    Example: 手動退款成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund/manual
        | key      | value |
        | order_id | 100   |
      Then 回應狀態碼為 200
      And 回應 code 為 "success"
      And 回應 message 包含 "手動退款成功"
      And 訂單 #100 狀態為 "refunded"

  Rule: 後置（狀態）- 手動退款觸發 order note
    Example: WooCommerce 自動新增手動退款 order note
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 狀態為 "processing"
      When WooCommerce 觸發 woocommerce_order_refunded hook
      And 該退款不是 API 退款（get_refunded_payment 為 false）
      Then 訂單 #100 有 order note 包含 "手動退款"
      And order note 包含退款金額
