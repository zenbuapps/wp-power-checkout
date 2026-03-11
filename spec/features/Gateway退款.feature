@ignore
Feature: Gateway 退款

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And "shopline_payment_redirect" 已啟用
    And 系統中有以下訂單：
      | orderId | userId | total | status     | payment_method            |
      | 100     | 1      | 1000  | processing | shopline_payment_redirect |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- order_id 必須是數字
    Example: order_id 不是數字
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | abc   |
      Then 回應狀態碼為 500
      And 回應包含 "訂單編號必須是數字"

  Rule: 前置（參數）- 訂單必須存在
    Example: 找不到訂單
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 9999  |
      Then 回應狀態碼為 500
      And 回應包含 "找不到訂單"

  Rule: 前置（參數）- 訂單必須有可退餘額
    Example: 訂單已全額退款
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 已退款 1000 元（remaining_refund_amount = 0）
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 100   |
      Then 回應狀態碼為 500
      And 回應包含 "已經沒有餘額可退"

  Rule: 前置（參數）- Gateway 必須是 AbstractPaymentGateway 實例
    Example: 訂單使用的 Gateway 不是 Power Checkout 的
      Given 系統中有訂單 #200，payment_method 為 "bacs"
      And 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 200   |
      Then 回應狀態碼為 500
      And 回應包含 "不是 AbstractPaymentGateway 的實例"

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- ATM 虛擬帳號不支援退款
    Example: ATM 付款方式退款失敗
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 付款方式為 VirtualAccount
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 100   |
      Then 回應狀態碼為 500
      And 回應包含 "不支援退款"

  Rule: 後置（狀態）- 中租零卡僅支援全額退款
    Example: 中租零卡部分退款失敗
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 付款方式為 ChaileaseBNPL
      And 訂單 #100 已退款 500 元（remaining_refund_amount = 500）
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 100   |
      Then 回應狀態碼為 500
      And 回應包含 "僅支援全額退款"

  Rule: 後置（狀態）- 退款成功時建立退款記錄並呼叫 SLP API
    Example: 信用卡退款成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 付款方式為 CreditCard
      And SLP 退款 API 回傳成功
      When 用戶發送 POST /wp-json/power-checkout/v1/refund
        | key      | value |
        | order_id | 100   |
      Then 回應狀態碼為 200
      And 回應 message 包含 "退款成功"
      And 訂單 #100 新增了 WC_Order_Refund 記錄

  Rule: 後置（狀態）- 退款 API 失敗時回滾
    Example: SLP 退款 API 失敗
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 付款方式為 CreditCard
      And SLP 退款 API 回傳失敗
      When Gateway handle_payment_gateway_refund 被觸發
      Then 退款記錄被刪除
      And 訂單 #100 有 order note 包含 "退款失敗"
