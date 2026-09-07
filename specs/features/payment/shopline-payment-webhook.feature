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

  規則: 回應碼判準為「同一份 payload 之後有沒有可能成功」

    # 驗簽不符極可能是商家 signKey 設定錯誤 → 回 401 保留 SLP 重送機會
    # 其餘失敗重送必然也失敗 → 一律回 200 止血，絕不回 500 造成 SLP 無限重試

    場景: 驗簽不符回 401
      假設 非本地環境
      而且 當前時間戳為有效範圍內
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，sign 為 "invalid_sign_value"
      那麼 回應狀態碼為 401
      而且 回應 code 為 "invalid_signature"
      而且 訂單 #100 狀態為 "pending"
      而且 訂單 #100 的 _pc_payment_detail 為空

    場景: 401 回應不洩漏計算出來的正確簽章
      假設 非本地環境
      當 SLP 發送簽章不符的 Webhook
      那麼 回應 message 為 "Invalid signature"
      而且 回應不包含計算出來的簽章

    場景: timestamp 超過容許範圍回 200
      假設 非本地環境
      當 SLP 發送 POST /wp-json/power-checkout/slp/webhook，timestamp 為 "1000000000000"、sign 為 "valid_sign"
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "pending"
      而且 記錄 error log 包含 "Invalid timestamp"

    場景: 找不到訂單回 200
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "nonexistent_trade_id" 且 referenceOrderId 為 "999999"
      那麼 回應狀態碼為 200
      而且 記錄 error log 包含 "找不到訂單"

    場景: 未知 EventType 回 200
      假設 當前時間戳和簽章均有效
      當 SLP 發送 type 為 "unknown.event.type" 的 Webhook
      那麼 回應狀態碼為 200

    場景: data 缺必填欄位回 200
      假設 當前時間戳和簽章均有效
      當 SLP 發送 data 缺少 order 與 payment 的付款 Webhook
      那麼 回應狀態碼為 200

    場景: 處理成功回 200
      假設 當前時間戳和簽章均有效
      當 SLP 發送有效的付款成功 Webhook
      那麼 回應狀態碼為 200

  規則: timestamp 必須在 5 分鐘容許範圍內

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

  規則: 查單採 identity 優先、referenceOrderId 備援

    場景: identity 命中時回傳該訂單
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "trade_order_001" 且 referenceOrderId 為 "100"
      那麼 找到訂單 #100

    場景: 客戶未導回時仍能以 referenceOrderId 認列
      假設 系統中有訂單 #101，payment_method 為 "shopline_payment_redirect"，_pc_payment_identity 為空
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "trade_new" 且 referenceOrderId 為 "101"
      那麼 找到訂單 #101
      而且 訂單 #101 的 _pc_payment_identity 被回填為 "trade_new"

    場景: referenceOrderId 為非數字字串時安全回 null
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，referenceOrderId 為 "not-a-number"
      那麼 回應狀態碼為 200
      而且 不拋出例外

    場景: referenceOrderId 命中但付款方式非 SLP 時拒絕誤配
      假設 系統中有訂單 #102，payment_method 為 "ecpay_aio"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，referenceOrderId 為 "102"
      那麼 回應狀態碼為 200
      而且 訂單 #102 狀態不變

    場景: identity 被汙染時以 referenceOrderId 為準
      假設 攻擊者訂單 #103 的 _pc_payment_identity 被寫入受害者的 "trade_victim"
      而且 系統中有受害者訂單 #104，payment_method 為 "shopline_payment_redirect"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "trade_victim" 且 referenceOrderId 為 "104"
      那麼 找到訂單 #104
      而且 訂單 #103 狀態不變
      而且 記錄 warning log 包含 "疑似 meta 汙染"

  規則: 付款狀態更新

    場景: 付款成功（SUCCEEDED）訂單狀態改為 processing
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款 Webhook：
        | tradeOrderId    | status    |
        | trade_order_001 | SUCCEEDED |
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "processing"
      而且 訂單 #100 有 order note 包含付款詳情 HTML
      而且 order note 標題冠上 "[Webhook]"
      而且 訂單 #100 的 _pc_payment_detail 有值
      而且 訂單 #100 的 transaction_id 為 "trade_order_001"

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

  規則: 付款成功前的冪等與四道守衛

    # 守衛順序：a. 已處理中 → b. 終態 → c. 幣別 → d. 金額
    # 四道各自獨立 return，且只在 SUCCEEDED 分支被呼叫

    場景: 同一筆付款狀態只處理一次
      假設 訂單 #100 的 _pc_payment_processed_status 已包含 "trade_order_001:SUCCEEDED"
      而且 當前時間戳和簽章均有效
      當 SLP 再次發送相同的付款成功 Webhook
      那麼 回應狀態碼為 200
      而且 不新增 order note
      而且 不再執行狀態轉換

    場景: 認列後寫入冪等鍵
      假設 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，tradeOrderId 為 "trade_order_001"
      那麼 訂單 #100 的 _pc_payment_processed_status 包含 "trade_order_001:SUCCEEDED"

    場景: 金額不符時拒絕認列
      假設 訂單 #100 應收 1000 元
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，paidAmount.value 為 100
      那麼 回應狀態碼為 200
      而且 訂單 #100 狀態為 "pending"
      而且 訂單 #100 有 order note 包含 "疑似竄改"

    場景: 金額容許 1 cent 浮點誤差
      假設 訂單 #100 應收 19.99 元
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，paidAmount.value 為 1998
      那麼 訂單 #100 狀態為 "processing"

    場景: 幣別不符時拒絕認列
      假設 訂單 #100 幣別為 "USD"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，paidAmount.currency 為 "TWD"
      那麼 訂單 #100 狀態為 "pending"
      而且 訂單 #100 有 order note 包含 "疑似竄改"

    場景: 終態訂單不得被重放復活
      假設 訂單 #100 狀態為 "refunded"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook
      那麼 訂單 #100 狀態為 "refunded"
      而且 訂單 #100 有 order note 包含 "終態"

  規則: 金額守衛比對顧客實付金額，而非我方送出的應收回音

    # order->amount 是我方建立 session 時送出、SLP 原樣回傳的「回音」，
    # 拿它做防竄改等於拿自己送出去的值驗自己。
    # 守衛以 payment->paidAmount（實付）為準，對齊 PAYUNi（TradeAmt）與 PayNow（Amount）。

    場景: 實付金額不符時拒絕認列，即使應收回音相符
      假設 訂單 #100 應收 1000 元
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，order.amount.value 為 100000 但 paidAmount.value 為 100
      那麼 訂單 #100 狀態為 "pending"
      而且 訂單 #100 有 order note 包含 "疑似竄改"
      而且 order note 標明比對來源為 "（來源：實付金額）"

    場景: 守衛 order note 必須標明比對來源
      假設 守衛因金額或幣別不符而攔截
      當 寫入告警 order note
      那麼 note 標明來源為 "實付金額" 或 "訂單應收金額（回音、降級）"
      而且 客服可據此判斷守衛比對的是哪個欄位

    場景: 實付幣別不符時拒絕認列，即使應收回音幣別相符
      假設 訂單 #100 幣別為 "TWD"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款成功 Webhook，order.amount.currency 為 "TWD" 但 paidAmount.currency 為 "USD"
      那麼 訂單 #100 狀態為 "pending"
      而且 訂單 #100 有 order note 包含 "實付金額"

    場景: 實付金額與幣別必須同源
      假設 當前時間戳和簽章均有效
      當 守衛取得比對用金額
      那麼 金額與幣別出自同一個 Amount 物件
      而且 不得金額取 paidAmount、幣別取 order.amount

    場景: 實付金額取不到時降級比對應收回音，不得誤擋合法通知
      假設 訂單 #100 應收 1000 元
      而且 通知的 paidAmount 缺少 value（殘缺 payload，DTO 於非 local 環境吞掉建構錯誤）
      而且 order.amount.value 為 100000 且與訂單金額相符
      當 SLP 發送付款成功 Webhook
      那麼 訂單 #100 狀態為 "processing"
      而且 訂單 #100 有 order note 包含 "通知內容不完整"

    場景: 降級不等於放行，回音不符時仍須擋下
      假設 訂單 #100 應收 1000 元
      而且 通知的 paidAmount 缺少 value
      而且 order.amount.value 為 99000（與訂單金額不符）
      當 SLP 發送付款成功 Webhook
      那麼 訂單 #100 狀態為 "pending"
      而且 訂單 #100 有 order note 包含 "疑似竄改"
      而且 訂單 #100 有 order note 包含 "降級"

    場景: paidAmount 為 0 不視為缺席
      假設 訂單 #100 應收 1000 元
      當 SLP 發送付款成功 Webhook，paidAmount.value 為 0
      那麼 訂單 #100 狀態為 "pending"
      而且 不得因此降級改用應收回音比對

  規則: 已處理中守衛不得吃掉異常訊號的可觀測性

    # 訂單已 processing 代表先前某筆通知已認列。
    # 此守衛只作用於 SUCCEEDED 路徑（呼叫點即為 SUCCEEDED 分支），
    # FAILED / EXPIRED 走不到它，order note 照常寫。

    場景: 已 processing 收到不同 tradeOrderId 的成功通知時不覆寫
      假設 訂單 #100 已由 tradeOrderId "trade_T1" 認列為 "processing"
      而且 當前時間戳和簽章均有效
      當 SLP 發送 tradeOrderId 為 "trade_T2" 的合法付款成功 Webhook，金額與幣別皆相符
      那麼 訂單 #100 狀態為 "processing"
      而且 訂單 #100 的 transaction_id 仍為 "trade_T1"
      而且 訂單 #100 的 _pc_payment_detail 未被覆寫
      而且 不新增 order note

    場景: 已 processing 收到 FAILED 通知時仍留下 order note
      假設 訂單 #100 已認列為 "processing"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款 FAILED Webhook
      那麼 訂單 #100 有新增 order note
      而且 order note 包含 "失敗"

    場景: 已 processing 收到 EXPIRED 通知時仍留下 order note
      假設 訂單 #100 已認列為 "processing"
      而且 當前時間戳和簽章均有效
      當 SLP 發送付款 EXPIRED Webhook
      那麼 訂單 #100 有新增 order note

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

    場景: 退款 Webhook 亦支援 referenceOrderId 備援查單
      假設 系統中有訂單 #105，payment_method 為 "shopline_payment_redirect"，_pc_payment_identity 為空
      而且 當前時間戳和簽章均有效
      當 SLP 發送退款 Webhook，referenceOrderId 為 "105"
      那麼 找到訂單 #105
      而且 回應狀態碼為 200
