# language: zh-TW
功能: Shopline Payment Webhook 處理
  作為 系統
  我想要 正確處理 SLP 的 Webhook 通知
  以便 同步訂單付款狀態與退款結果

  背景:
    假設 SLP Webhook 端點為 POST /wp-json/power-checkout/slp/webhook
    而且 系統已設定有效的 signKey 為 "test_sign_key_123"
    而且 系統中有以下訂單：
      | orderId | userId | total | status  | payment_method            | _pc_payment_identity |
      | 100     | 2      | 1000  | pending | shopline_payment_redirect | trade_order_001      |

  規則: timestamp 必須在 5 分鐘容許範圍內

    場景: timestamp 超過容許範圍
      假設 非本地環境
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，timestamp 為 "1000000000000"、sign 為 "valid_sign"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "Invalid timestamp"

    場景: 本地環境跳過 timestamp 驗證
      假設 本地環境
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，timestamp 為 "1000000000000"、sign 為 "any_sign"
      那麼 不觸發 timestamp 驗證錯誤

    場景: 有效 timestamp 和 sign 通過驗證
      假設 請求 header 包含有效的 timestamp 和 sign
      而且 timestamp 與伺服器時間差異在 5 分鐘內
      當 系統收到 Webhook 請求
      那麼 簽章驗證通過

  規則: HMAC-SHA256 簽章必須驗證通過

    場景: 簽章不正確
      假設 非本地環境
      而且 當前時間戳為有效範圍內
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，timestamp 為 "valid_timestamp"、sign 為 "invalid_sign_value"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "Invalid sign"

    場景: 簽章計算方式
      假設 signKey 為 "test_sign_key_123"
      而且 timestamp 為 "1700000000000"
      而且 body 為 '{"eventType":"trade.succeeded","data":{}}'
      當 計算簽章
      那麼 簽章為 hash_hmac("sha256", "1700000000000.{body}", "test_sign_key_123")

    場景: 本地環境跳過簽章驗證
      假設 Plugin 環境為 local
      當 系統收到 Webhook 請求
      那麼 跳過簽章驗證直接處理

  規則: apiVersion header 預期為 V1

    場景: apiVersion 非 V1 時記錄 warning 但不阻擋
      假設 非本地環境
      而且 當前時間戳和簽章均有效
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，apiVersion 為 "V2"
      那麼 記錄 warning log "版本與預期 V1 不符"
      而且 繼續處理 Webhook

  規則: 必須透過 tradeOrderId 找到對應訂單

    場景: 找不到對應訂單
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "nonexistent_trade_id"
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "找不到訂單"

  規則: 付款狀態更新

    場景: 付款成功（SUCCEEDED）訂單狀態改為 processing
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook：
        | tradeOrderId    | status    |
        | trade_order_001 | SUCCEEDED |
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "processing"
      而且 訂單 #100 有 order note 包含付款詳情 HTML
      而且 訂單 #100 的 _pc_payment_detail 有值

    場景: 付款過期（EXPIRED）訂單狀態改為 cancelled
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook：
        | tradeOrderId    | status  |
        | trade_order_001 | EXPIRED |
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "cancelled"

    場景: 其他付款狀態則訂單狀態保持 pending
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook：
        | tradeOrderId    | status     |
        | trade_order_001 | PROCESSING |
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "pending"

    場景: 僅處理 SUCCEEDED 或 FAILED 的付款交易
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook，status 為 "CUSTOMER_ACTION" 且 is_successed_or_failed() 為 false
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態不變

  規則: 退款 Webhook

    場景: 退款失敗則刪除最近一筆退款記錄
      假設 當前時間戳和簽章均有效
      而且 訂單 #100 有一筆退款記錄 refund_id = 50
      當 SLP 發送退款 Webhook：
        | tradeOrderId    | status |
        | trade_order_001 | FAILED |
      那麼 回應狀態碼為 200
      而且 退款記錄 #50 被刪除

    場景: 退款成功則記錄退款詳情並清除暫存原因
      假設 當前時間戳和簽章均有效
      而且 訂單 #100 有 tmp_refund_reason 為 "客戶要求退款"
      當 SLP 發送退款 Webhook：
        | tradeOrderId    | status    |
        | trade_order_001 | SUCCEEDED |
      那麼 回應狀態碼為 200
      而且 訂單 #100 的 _pc_refund_detail 有值
      而且 訂單 #100 有 order note 包含退款資訊 HTML
      而且 訂單 #100 的 tmp_refund_reason 已被刪除

  規則: 回應處理

    場景: 處理成功回傳 200
      假設 當前時間戳和簽章均有效
      當 SLP 發送有效的付款成功 Webhook
      那麼 回應狀態碼為 200

    場景: 處理失敗時回傳 500 附帶錯誤訊息
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook 但內部處理出錯（如找不到訂單）
      那麼 回應狀態碼為 500
      而且 回應 code 為 "mapping_order_failed"
