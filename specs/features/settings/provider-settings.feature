# language: zh-TW
功能: Provider 設定管理
  作為 網站管理員
  我想要 管理金流和發票服務的設定
  以便 控制結帳體驗

  背景:
    假設 管理員已登入後台
    而且 已進入 WooCommerce > 設定 > Power Checkout 設定 分頁

  場景: 查看所有 Provider 設定
    當 管理員開啟設定頁
    那麼 REST API GET /settings 回傳 gateways, invoices, logistics

  場景: 切換 Provider 啟用狀態
    當 管理員點擊啟用開關
    那麼 POST /settings/{provider_id}/toggle 切換 enabled 值

  場景: 更新 Provider 設定
    當 管理員修改設定並儲存
    那麼 POST /settings/{provider_id} 寫入 WC option

  場景: SLP 最小金額驗證
    當 管理員將最小金額設為 3
    那麼 儲存失敗顯示 "minimum amount out of range"

  場景: SLP 最大金額驗證
    當 管理員將最大金額設為 60000
    那麼 儲存失敗顯示 "maximum amount out of range"

  規則: LINE Pay 付款方式設定

    場景: 啟用 LINE Pay 付款方式
      當 管理員在 SLP 設定頁面勾選 LINE Pay
      而且 儲存設定
      那麼 allowPaymentMethodList 包含 "LinePay"

    場景: LINE Pay 勾選後顯示警告提示
      當 管理員勾選 LINE Pay
      那麼 顯示 warning 類型警告 "請先確認已在 SLP 後台啟用 LINE Pay"

    場景: LINE Pay 預設未啟用（向下相容）
      假設 商店從未設定過 allowPaymentMethodList
      那麼 預設的 allowPaymentMethodList 包含 "LinePay"
      但是 既有商店升級後不會自動將 "LinePay" 加入已儲存的 allowPaymentMethodList

    場景: LINE Pay 不需要 paymentMethodOptions
      當 管理員啟用 LINE Pay
      那麼 paymentMethodOptions 不包含 LinePay 的設定項
      而且 不顯示 LINE Pay 的分期期數選項
