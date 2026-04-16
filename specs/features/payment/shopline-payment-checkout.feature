# language: zh-TW
功能: Shopline Payment 結帳付款
  作為 網站訪客
  我想要 使用 Shopline Payment 進行線上付款
  以便 完成訂單結帳

  背景:
    假設 Shopline Payment Redirect 金流已啟用
    而且 金流設定中已填入有效的 platformId, merchantId, apiKey, clientKey, signKey

  場景: 成功建立付款 Session 並跳轉
    假設 購物車中有商品且金額在 5~50000 範圍內
    當 顧客在結帳頁選擇 Shopline Payment 並點擊下單
    那麼 系統呼叫 SLP API POST /trade/sessions/create
    而且 系統記錄 Order Note "Pay via Shopline Payment 線上付款"
    而且 系統扣減庫存
    而且 頁面跳轉至 SLP 託管付款頁面 (sessionUrl)

  場景: Session 已過期
    假設 購物車中有商品
    當 顧客下單但 SLP 回傳 session 狀態為 EXPIRED
    那麼 訂單狀態變更為 cancelled
    而且 顯示錯誤訊息 "已超過 Shopline Payment 付款期限，請重新下單"

  場景: 金額低於最小限制
    假設 金流設定最小金額為 5 元
    而且 購物車商品總額為 3 元
    那麼 Shopline Payment 付款方式不可用

  場景: 金額超過最大限制
    假設 金流設定最大金額為 50000 元
    而且 購物車商品總額為 60000 元
    那麼 Shopline Payment 付款方式不可用

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
