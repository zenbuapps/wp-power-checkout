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

  規則: 建立 Session 後必須儲存 sessionId

    場景: 建立 Session 成功時寫入 _pc_identity
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API create_session 回傳 sessionId "sess_abc" 且 status 為 "CREATED"
      當 WooCommerce 呼叫 process_payment(100)
      那麼 訂單 #100 的 _pc_identity 為 "sess_abc"

    場景: Session 逾期時仍寫入 sessionId（寫在逾期判斷之前）
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API create_session 回傳 sessionId "sess_expired" 且 status 為 "EXPIRED"
      當 WooCommerce 呼叫 process_payment(100)
      那麼 訂單 #100 的 _pc_identity 為 "sess_expired"
      而且 訂單 #100 狀態為 "cancelled"

    場景: 寫入 sessionId 失敗不得中斷付款流程
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API create_session 回傳 status 為 "CREATED"
      而且 meta 寫入發生例外
      當 WooCommerce 呼叫 process_payment(100)
      那麼 回傳 result 為 "success"
      而且 記錄 error log

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

  規則: 付款回跳時同步查詢並當場認列付款

    場景: 查詢回 SUCCEEDED 時當場轉為 processing
      假設 系統中有訂單 #100，total 為 1000，status 為 "pending"，幣別為 "TWD"
      而且 SLP API get_payment 回傳 status 為 "SUCCEEDED"、referenceOrderId 為 "100"、amount 為 100000 TWD
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與正確的 order_key
      那麼 系統呼叫 SLP API POST /trade/payment/get，timeout 為 10 秒
      而且 訂單 #100 狀態為 "processing"
      而且 訂單 #100 的 _pc_payment_detail 有值
      而且 訂單 #100 的 _pc_payment_identity 為 "trade_001"
      而且 訂單 #100 的 transaction_id 為 "trade_001"
      而且 order note 標題冠上 "[導回同步]"

    場景: 查詢回非終態時維持 pending
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API get_payment 回傳 status 為 "PENDING"
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與正確的 order_key
      那麼 訂單 #100 狀態為 "pending"

    場景: 沒有 tradeOrderId 時直接跳過
      假設 系統中有訂單 #100
      當 顧客回跳至 order-received 頁面且未帶 tradeOrderId
      那麼 不呼叫 SLP API
      而且 回傳結果為 "SKIPPED_NO_TRADE_ID"

    場景: 已認列的訂單再次回跳時不呼叫 API
      假設 系統中有訂單 #100，status 為 "processing"
      當 顧客重新整理 order-received 頁面帶有 tradeOrderId "trade_001"
      那麼 不呼叫 SLP API
      而且 回傳結果為 "SKIPPED_NOT_PENDING"

    場景: 30 秒節流內第二次回跳不呼叫 API
      假設 系統中有訂單 #100，status 為 "pending"
      而且 30 秒內已同步查詢過一次
      當 顧客重新整理 order-received 頁面帶有 tradeOrderId "trade_001"
      那麼 不呼叫 SLP API
      而且 回傳結果為 "SKIPPED_THROTTLED"

  規則: 導回同步永不讓 thankyou 頁失敗（never-throw）

    # never-throw 的保證由 sync() 自己持有：會拋錯的步驟（⑤ 查詢、⑦⑧ 認列）
    # 各自 catch 並轉為 ReturnSyncResult。
    # before_order_received() 的外層 catch 是第二道保險，不是這項保證的依據。

    場景: 查詢 API 連線失敗
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API get_payment 回傳 WP_Error
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與正確的 order_key
      那麼 不拋出例外
      而且 訂單 #100 狀態為 "pending"
      而且 回傳結果為 "API_FAILED"
      而且 記錄 error log

    場景: 查詢 API 回業務錯誤碼
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API get_payment 回傳 code "4001"
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與正確的 order_key
      那麼 不拋出例外
      而且 回傳結果為 "API_FAILED"

    場景: 查詢成功但認列時發生例外
      假設 系統中有訂單 #100，status 為 "pending"
      而且 SLP API get_payment 回傳 status 為 "SUCCEEDED" 且 referenceOrderId 相符
      而且 寫入 _pc_payment_identity 或轉換訂單狀態時拋出例外
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與正確的 order_key
      那麼 不拋出例外
      而且 回傳結果為 "SETTLE_FAILED"
      而且 記錄 error log 包含 "認列失敗"
      而且 改由 Webhook 認列

    場景: 認列失敗與查詢失敗在 log 中可區分
      假設 導回同步回傳 "SETTLE_FAILED"
      當 before_order_received 記錄 warning log
      那麼 log 訊息包含 "查詢成功但認列時發生錯誤"
      而且 與 "API_FAILED" 的 "查詢 API 未成功回應" 可區分

    場景: thankyou 頁在任何結果下都不得 500
      假設 導回同步回傳任一 ReturnSyncResult
      當 頁面繼續 render
      那麼 不回應 HTTP 500
      而且 before_page_render 的 empty_cart 照常執行

  規則: 導回同步的資安閘門

    # 金額 / 幣別守衛由 StatusManager 與 Webhook 路徑共用，
    # 規格見 shopline-payment-webhook.feature「規則: 金額守衛比對顧客實付金額…」。

    場景: order_key 不符時不打 API 也不寫 meta
      假設 系統中有訂單 #100，status 為 "pending"
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_001" 與錯誤的 order_key
      那麼 不呼叫 SLP API
      而且 訂單 #100 的 _pc_payment_identity 為空
      而且 回傳結果為 "SKIPPED_INVALID_KEY"

    場景: tradeOrderId 含非法字元時直接跳過
      假設 系統中有訂單 #100，status 為 "pending"
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "<script>alert(1)</script>"
      那麼 不呼叫 SLP API
      而且 回傳結果為 "SKIPPED_NO_TRADE_ID"

    # ⚠️ 安全關鍵：下面這個場景鎖住的是「同金額白嫖攻擊」的唯一防線。
    # 攻擊者持自己訂單的合法 order_key + 受害者的 tradeOrderId 時，
    # order_key / 金額 / 幣別三道守衛全會放行（兩筆訂單金額相同時），只有 referenceOrderId 比對擋得住。
    # 移除該比對前請先補等效防線。
    場景: 查詢結果的 referenceOrderId 指向別的訂單時拒絕認列
      假設 系統中有訂單 #100，status 為 "pending"
      而且 攻擊者持有訂單 #100 的合法 order_key
      而且 SLP API get_payment 回傳 referenceOrderId 為 "999"（受害者訂單）
      而且 兩筆訂單金額與幣別相同
      當 顧客回跳至 order-received 頁面帶有 tradeOrderId "trade_victim" 與正確的 order_key
      那麼 訂單 #100 狀態為 "pending"
      而且 訂單 #100 的 _pc_payment_identity 為空
      而且 訂單 #100 有 order note 包含 "不屬於本訂單"
      而且 回傳結果為 "MISMATCHED_ORDER"

    場景: tradeOrderId 長度 40 字元仍可完成同步
      假設 系統中有訂單 #100，status 為 "pending"
      而且 tradeOrderId 長度為 40 字元
      當 顧客回跳至 order-received 頁面帶有該 tradeOrderId 與正確的 order_key
      那麼 系統呼叫 SLP API POST /trade/payment/get
      而且 不因 DTO 長度驗證失敗（上限為 64 字元）

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
