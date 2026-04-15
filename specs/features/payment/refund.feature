# language: zh-TW
功能: 退款處理
  作為 網站管理員
  我想要 對訂單進行退款
  以便 處理客戶退貨或取消需求

  背景:
    假設 管理員已登入後台
    而且 存在一筆使用 Shopline Payment 付款且狀態為 processing 的訂單

  規則: 透過金流退款

    場景: 信用卡全額退款
      當 管理員選擇 "使用 Shopline Payment 自動退款"
      那麼 系統呼叫 SLP API POST /trade/refund/create
      而且 退款過程使用資料庫交易

    場景: ATM 不支援退款
      假設 訂單付款方式為 ATM 虛擬帳號
      當 管理員嘗試退款
      那麼 process_refund 回傳 false

    場景: 退款 API 失敗回滾
      當 SLP API 回傳失敗
      那麼 資料庫交易 ROLLBACK
      而且 退款紀錄被刪除

  規則: 手動退款

    場景: 手動退款
      當 管理員選擇 "手動退款"
      那麼 不呼叫任何金流 API
      而且 Order Note 記錄手動退款金額
