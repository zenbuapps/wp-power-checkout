# language: zh-TW
功能: Shopline Payment 結帳付款
  作為 網站訪客
  我想要 使用 Shopline Payment 進行線上付款
  以便 完成訂單結帳

  背景:
    假設 "shopline_payment_redirect" 已啟用
    而且 SLP 設定如下：
      | key        | value         |
      | platformId | test_platform |
      | merchantId | test_merchant |
      | apiKey     | test_api_key  |
      | clientKey  | test_client   |
      | signKey    | test_sign_key |
      | mode       | test          |
      | min_amount | 5             |
      | max_amount | 50000         |
      | expire_min | 360           |

  規則: 前置（狀態）- 訂單必須存在

    場景: 訂單不存在時處理失敗
      假設 顧客已登入
      當 WooCommerce 呼叫 process_payment(999)
      那麼 回傳 result 為 "failure"
      而且 前台顯示錯誤通知 "處理結帳時發生錯誤，請查閱 Shopline Payment 線上付款 的 log 紀錄了解詳情"

  規則: Gateway 必須啟用且訂單金額在允許範圍內

    場景: 金額低於 min_amount 時 Gateway 不可用
      假設 系統中有訂單 #101，total 為 3，payment_method 為 "shopline_payment_redirect"
      當 檢查 Gateway is_available
      那麼 回傳 false

    場景: 金額高於 max_amount 時 Gateway 不可用
      假設 系統中有訂單 #102，total 為 60000，payment_method 為 "shopline_payment_redirect"
      當 檢查 Gateway is_available
      那麼 回傳 false

    場景: 金額為零時 Gateway 不可用
      假設 系統中有訂單 #103，total 為 0，payment_method 為 "shopline_payment_redirect"
      當 檢查 Gateway is_available
      那麼 回傳 false

    場景: 金額在範圍內時 Gateway 可用
      假設 系統中有訂單 #104，total 為 1000，payment_method 為 "shopline_payment_redirect"
      當 檢查 Gateway is_available
      那麼 回傳 true

  規則: 成功時建立 SLP Session 並導向付款頁

    場景: 正常結帳流程
      假設 系統中有訂單 #100，total 為 1000，status 為 "pending"
      而且 顧客已登入
      而且 SLP API create_session 回傳 sessionUrl "https://payment.example.com/pay" 且 status 為 "CREATED"
      當 WooCommerce 呼叫 process_payment(100)
      那麼 回傳 result 為 "success"
      而且 回傳 redirect 為 "https://payment.example.com/pay"
      而且 系統呼叫 SLP API POST /trade/sessions/create
      而且 訂單 #100 有 order note "Pay via Shopline Payment 線上付款"
      而且 庫存被扣減
      而且 頁面跳轉至 SLP 託管付款頁面 (sessionUrl)

  規則: Session 過期時訂單取消

    場景: SLP Session 已過期
      假設 系統中有訂單 #100，status 為 "pending"
      而且 顧客已登入
      而且 SLP API create_session 回傳 status 為 "EXPIRED" 且 sessionId 為 "sess_123"
      當 WooCommerce 呼叫 process_payment(100)
      那麼 訂單 #100 狀態為 "cancelled"
      而且 訂單 #100 有 order note 包含 "已超過 Shopline Payment 付款期限"
      而且 訂單 #100 有 order note 包含 "session_id: sess_123"
      而且 前台顯示錯誤通知 "已超過 Shopline Payment 付款期限，請重新下單"
      而且 頁面導向訂單檢視頁

  規則: 付款回跳後儲存 payment_identity 防重複處理

    場景: 顧客從 SLP 付款頁回跳至 order-received 頁
      假設 系統中有訂單 #100
      而且 訂單 #100 的 _pc_payment_identity 為空
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001"
      那麼 訂單 #100 的 _pc_payment_identity 為 "trade_001"

    場景: 重複回跳時不重複儲存
      假設 系統中有訂單 #100
      而且 訂單 #100 的 _pc_payment_identity 已為 "trade_001"
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001"
      那麼 不執行任何更新操作

  規則: LINE Pay 結帳

    場景: LINE Pay 已啟用時顯示於 SLP 託管頁面
      假設 金流設定中 allowPaymentMethodList 包含 "LinePay"
      當 顧客在結帳頁選擇 Shopline Payment 並點擊下單
      那麼 系統呼叫 SLP API POST /trade/sessions/create
      而且 請求 allowPaymentMethodList 包含 "LinePay"
      而且 請求不包含 LINE Pay 的 paymentMethodOptions
      而且 SLP 託管頁面顯示 LINE Pay 付款選項

    場景: LINE Pay 未啟用時不顯示
      假設 金流設定中 allowPaymentMethodList 不包含 "LinePay"
      當 顧客在結帳頁選擇 Shopline Payment 並點擊下單
      那麼 請求 allowPaymentMethodList 不包含 "LinePay"
      而且 SLP 託管頁面不顯示 LINE Pay 付款選項
