@ignore @command
Feature: 處理 Webhook 通知

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role     |
      | 2      | Customer | customer@example.com | customer |
    And "shopline_payment_redirect" 已啟用
    And SLP signKey 為 "test_sign_key_123"
    And 系統中有以下訂單：
      | orderId | userId | total | status  | payment_method            | _pc_payment_identity |
      | 100     | 2      | 1000  | pending | shopline_payment_redirect | trade_order_001      |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- timestamp 必須在 5 分鐘容許範圍內
    Example: timestamp 超過容許範圍
      Given 非本地環境
      When SLP 發送 POST /wp-json/power-checkout/slp/webhook
        | header    | value                |
        | timestamp | 1000000000000        |
        | sign      | valid_sign           |
      Then 回應狀態碼為 500
      And 回應包含 "Invalid timestamp"

    Example: 本地環境跳過 timestamp 驗證
      Given 本地環境
      When SLP 發送 POST /wp-json/power-checkout/slp/webhook
        | header    | value                |
        | timestamp | 1000000000000        |
        | sign      | any_sign             |
      Then 不觸發 timestamp 驗證錯誤

  Rule: 前置（參數）- HMAC-SHA256 簽章必須驗證通過
    Example: 簽章不正確
      Given 非本地環境
      And 當前時間戳為有效範圍內
      When SLP 發送 POST /wp-json/power-checkout/slp/webhook
        | header    | value                |
        | timestamp | valid_timestamp      |
        | sign      | invalid_sign_value   |
      Then 回應狀態碼為 500
      And 回應包含 "Invalid sign"

    Example: 簽章計算方式
      Given signKey 為 "test_sign_key_123"
      And timestamp 為 "1700000000000"
      And body 為 '{"eventType":"trade.succeeded","data":{}}'
      When 計算簽章
      Then 簽章為 hash_hmac("sha256", "1700000000000.{body}", "test_sign_key_123")

  Rule: 前置（參數）- apiVersion header 應為 V1
    Example: apiVersion 非 V1 時記錄 warning 但不阻擋
      Given 非本地環境
      And 當前時間戳和簽章均有效
      When SLP 發送 POST /wp-json/power-checkout/slp/webhook，apiVersion 為 "V2"
      Then 記錄 warning log "版本與預期 V1 不符"
      And 繼續處理 Webhook

  Rule: 前置（參數）- 必須透過 tradeOrderId 找到對應訂單
    Example: 找不到對應訂單
      Given 當前時間戳和簽章均有效
      When SLP 發送付款成功 Webhook，tradeOrderId 為 "nonexistent_trade_id"
      Then 回應狀態碼為 500
      And 回應包含 "找不到訂單"

  # ========== 後置（狀態）- 付款交易 ==========
  Rule: 後置（狀態）- 付款成功（SUCCEEDED）則訂單狀態改為 processing
    Example: 收到付款成功通知
      Given 當前時間戳和簽章均有效
      When SLP 發送付款 Webhook：
        | tradeOrderId    | status    |
        | trade_order_001 | SUCCEEDED |
      Then 回應狀態碼為 200
      And 訂單 #100 狀態為 "processing"
      And 訂單 #100 有 order note 包含付款詳情 HTML
      And 訂單 #100 的 _pc_payment_detail 有值

  Rule: 後置（狀態）- 付款過期（EXPIRED）則訂單狀態改為 cancelled
    Example: 收到付款過期通知
      Given 當前時間戳和簽章均有效
      When SLP 發送付款 Webhook：
        | tradeOrderId    | status  |
        | trade_order_001 | EXPIRED |
      Then 回應狀態碼為 200
      And 訂單 #100 狀態為 "cancelled"

  Rule: 後置（狀態）- 其他付款狀態則訂單狀態保持 pending
    Example: 收到處理中通知
      Given 當前時間戳和簽章均有效
      When SLP 發送付款 Webhook：
        | tradeOrderId    | status     |
        | trade_order_001 | PROCESSING |
      Then 回應狀態碼為 200
      And 訂單 #100 狀態為 "pending"

  Rule: 後置（狀態）- 僅處理 SUCCEEDED 或 FAILED 的付款交易
    Example: 收到 CUSTOMER_ACTION 狀態的付款交易不更新訂單
      Given 當前時間戳和簽章均有效
      When SLP 發送付款 Webhook，status 為 "CUSTOMER_ACTION" 且 is_successed_or_failed() 為 false
      Then 回應狀態碼為 200
      And 訂單 #100 狀態不變

  # ========== 後置（狀態）- 退款交易 ==========
  Rule: 後置（狀態）- 退款失敗則刪除最近一筆退款記錄
    Example: 收到退款失敗通知
      Given 當前時間戳和簽章均有效
      And 訂單 #100 有一筆退款記錄 refund_id = 50
      When SLP 發送退款 Webhook：
        | tradeOrderId    | status |
        | trade_order_001 | FAILED |
      Then 回應狀態碼為 200
      And 退款記錄 #50 被刪除

  Rule: 後置（狀態）- 退款成功則記錄退款詳情並清除暫存原因
    Example: 收到退款成功通知
      Given 當前時間戳和簽章均有效
      And 訂單 #100 有 tmp_refund_reason 為 "客戶要求退款"
      When SLP 發送退款 Webhook：
        | tradeOrderId    | status    |
        | trade_order_001 | SUCCEEDED |
      Then 回應狀態碼為 200
      And 訂單 #100 的 _pc_refund_detail 有值
      And 訂單 #100 有 order note 包含退款資訊 HTML
      And 訂單 #100 的 tmp_refund_reason 已被刪除

  # ========== 後置（回應）==========
  Rule: 後置（回應）- 始終回傳 HTTP 200 避免 SLP 重試
    Example: 處理成功回傳 200
      Given 當前時間戳和簽章均有效
      When SLP 發送有效的付款成功 Webhook
      Then 回應狀態碼為 200

    Example: 處理失敗時也回傳 200（或 500 附帶錯誤訊息）
      Given 當前時間戳和簽章均有效
      When SLP 發送付款 Webhook 但內部處理出錯（如找不到訂單）
      Then 回應狀態碼為 500
      And 回應包含 "mapping_order_failed"
