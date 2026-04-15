# language: zh-TW
功能: 電子發票開立
  作為 系統 / 網站管理員
  我想要 自動或手動開立電子發票
  以便 符合台灣電子發票法規

  背景:
    假設 Amego 電子發票服務已啟用

  規則: 自動開立

    場景: 訂單狀態觸發自動開立
      假設 auto_issue_order_statuses 設定包含 "processing"
      當 訂單狀態變更為 processing
      那麼 系統呼叫 Amego API 開立發票
      而且 結果儲存至 _pc_issued_invoice_data

    場景: 已開立過不重複開立 (冪等)
      假設 _pc_issued_invoice_data 已有資料
      當 系統再次觸發 issue()
      那麼 直接回傳已存在的資料

  規則: 結帳頁發票資訊

    場景: 顧客填寫發票資訊
      當 顧客在結帳頁填寫發票類型和載具資訊並完成結帳
      那麼 發票資訊儲存至 _pc_issue_invoice_params
