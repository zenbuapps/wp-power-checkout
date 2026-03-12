@ignore @command
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

  # ========== 前置（狀態）==========
  Rule: 前置（狀態）- 訂單必須存在
    Example: 訂單不存在時處理失敗
      Given 用戶 "Customer" 已登入
      When WooCommerce 呼叫 process_payment(999)
      Then 回傳 result 為 "failure"
      And 前台顯示錯誤通知 "處理結帳時發生錯誤，請查閱 Shopline Payment 線上付款 的 log 紀錄了解詳情"

  Rule: 前置（狀態）- Gateway 必須啟用且訂單金額在允許範圍內
    Example: 金額低於 min_amount 時 Gateway 不可用
      Given 系統中有訂單 #101，total 為 3，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 false

    Example: 金額高於 max_amount 時 Gateway 不可用
      Given 系統中有訂單 #102，total 為 60000，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 false

    Example: 金額為零時 Gateway 不可用
      Given 系統中有訂單 #103，total 為 0，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 false

    Example: 金額在範圍內時 Gateway 可用
      Given 系統中有訂單 #104，total 為 1000，payment_method 為 "shopline_payment_redirect"
      When 檢查 Gateway is_available
      Then 回傳 true

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 成功時建立 SLP Session 並導向付款頁
    Example: 正常結帳流程
      Given 用戶 "Customer" 已登入
      And SLP API create_session 回傳 sessionUrl "https://payment.example.com/pay" 且 status 為 "CREATED"
      When WooCommerce 呼叫 process_payment(100)
      Then 回傳 result 為 "success"
      And 回傳 redirect 為 "https://payment.example.com/pay"
      And 訂單 #100 有 order note "Pay via Shopline Payment 線上付款"
      And 庫存被扣減

  Rule: 後置（狀態）- Session 過期時訂單取消
    Example: SLP Session 已過期
      Given 用戶 "Customer" 已登入
      And SLP API create_session 回傳 status 為 "EXPIRED" 且 sessionId 為 "sess_123"
      When WooCommerce 呼叫 process_payment(100)
      Then 訂單 #100 狀態為 "cancelled"
      And 訂單 #100 有 order note 包含 "已超過 Shopline Payment 付款期限"
      And 訂單 #100 有 order note 包含 "session_id: sess_123"
      And 前台顯示錯誤通知 "已超過 Shopline Payment 付款期限，請重新下單"
      And 頁面導向訂單檢視頁

  Rule: 後置（狀態）- 付款回跳後儲存 payment_identity 防重複處理
    Example: 顧客從 SLP 付款頁回跳至 order-received 頁
      Given 用戶 "Customer" 已登入
      And 訂單 #100 的 _pc_payment_identity 為空
      When 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001"
      Then 訂單 #100 的 _pc_payment_identity 為 "trade_001"

    Example: 重複回跳時不重複儲存
      Given 用戶 "Customer" 已登入
      And 訂單 #100 的 _pc_payment_identity 已為 "trade_001"
      When 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001"
      Then 不執行任何更新操作
