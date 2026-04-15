# language: zh-TW
功能: 電子發票作廢
  作為 系統 / 網站管理員
  我想要 自動或手動作廢電子發票
  以便 處理退貨或訂單取消情境

  背景:
    假設 Amego 電子發票服務已啟用
    而且 訂單已開立過電子發票

  規則: 自動作廢

    場景: 訂單狀態觸發自動作廢
      假設 auto_cancel_order_statuses 設定包含 "cancelled"
      當 訂單狀態變更為 cancelled
      那麼 系統呼叫 Amego API 作廢發票
      而且 原開立資料被清除

    場景: 已作廢過不重複作廢 (冪等)
      假設 _pc_cancelled_invoice_data 已有資料
      當 系統再次觸發 cancel()
      那麼 直接回傳已存在的資料

  規則: 手動作廢

    場景: 管理員手動作廢
      當 管理員點擊 "作廢發票"
      那麼 系統呼叫 cancel API
      而且 發票作廢成功
