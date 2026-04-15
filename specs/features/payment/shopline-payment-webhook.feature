# language: zh-TW
功能: Shopline Payment Webhook 處理
  作為 系統
  我想要 正確處理 SLP 的 Webhook 通知
  以便 同步訂單付款狀態

  背景:
    假設 SLP Webhook 端點為 POST /wp-json/power-checkout/slp/webhook
    而且 系統已設定有效的 signKey

  規則: 簽章驗證

    場景: 有效簽章通過驗證
      假設 請求 header 包含有效的 timestamp 和 sign
      而且 timestamp 與伺服器時間差異在 5 分鐘內
      當 系統收到 Webhook 請求
      那麼 簽章驗證通過

    場景: 本地環境跳過簽章驗證
      假設 Plugin 環境為 local
      當 系統收到 Webhook 請求
      那麼 跳過簽章驗證直接處理

  規則: 付款狀態更新

    場景: 付款成功
      假設 存在一筆 pending 訂單且 tradeOrderId 已儲存
      當 收到 payment webhook 且 status 為 SUCCEEDED
      那麼 訂單狀態變更為 processing
      而且 付款詳情儲存至 _pc_payment_detail

    場景: 付款過期
      當 收到 payment webhook 且 status 為 EXPIRED
      那麼 訂單狀態變更為 cancelled

  規則: 退款 Webhook

    場景: 退款成功
      當 收到 refund webhook 且 status 不是 FAILED
      那麼 退款詳情寫入 Order Note 和 _pc_refund_detail

    場景: 退款失敗
      當 收到 refund webhook 且 status 為 FAILED
      那麼 刪除最新一筆退款紀錄
