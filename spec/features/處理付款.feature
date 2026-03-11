@ignore
Feature: 處理付款

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role     |
      | 2      | Customer | customer@example.com | customer |
    And "shopline_payment_redirect" 已啟用
    And SLP 設定如下：
      | key        | value            |
      | merchantId | test_merchant    |
      | apiKey     | test_api_key     |
      | mode       | test             |
      | min_amount | 5                |
      | max_amount | 50000            |
      | expire_min | 360              |
    And 系統中有以下訂單：
      | orderId | userId | total | status  | payment_method              |
      | 100     | 2      | 1000  | pending | shopline_payment_redirect   |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 訂單必須存在
    Example: 訂單不存在時處理失敗
      Given 用戶 "Customer" 已登入
      When WooCommerce 呼叫 process_payment(999)
      Then 回傳 result 為 "failure"
      And 前台顯示錯誤通知

  Rule: 前置（參數）- 訂單金額必須在允許範圍內
    Example: 金額低於 min_amount 時 Gateway 不可用
      Given 系統中有訂單 #101，total 為 3，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 false

    Example: 金額高於 max_amount 時 Gateway 不可用
      Given 系統中有訂單 #102，total 為 60000，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 false

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 成功時建立 SLP Session 並導向付款頁
    Example: 正常結帳流程
      Given 用戶 "Customer" 已登入
      And SLP API create_session 回傳 sessionUrl "https://payment.example.com/pay"
      When WooCommerce 呼叫 process_payment(100)
      Then 回傳 result 為 "success"
      And 回傳 redirect 為 "https://payment.example.com/pay"
      And 訂單 #100 有 order note "Pay via Shopline Payment 線上付款"
      And 庫存被扣減

  Rule: 後置（狀態）- Session 過期時訂單取消
    Example: SLP Session 已過期
      Given 用戶 "Customer" 已登入
      And SLP API create_session 回傳 status 為 "EXPIRED"
      When WooCommerce 呼叫 process_payment(100)
      Then 訂單 #100 狀態為 "cancelled"
      And 訂單 #100 有 order note 包含 "已超過 Shopline Payment 付款期限"
      And 前台顯示錯誤通知 "已超過 Shopline Payment 付款期限，請重新下單"
      And 頁面導向訂單檢視頁
