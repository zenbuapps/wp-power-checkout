# language: zh-TW
功能: Settings SPA 使用 Memory 路由模式
  作為 網站管理員
  我想要 Settings SPA 的路由不使用 URL hash（#）
  以便 URL 保持乾淨，其他頁面不受 hash 變更影響

  背景:
    假設 Power Checkout 外掛已啟用

  規則: 僅 WC Settings 頁面初始化 Vue Router

    場景: Settings SPA 正常使用 Memory Router（Happy Path）
      假設 管理員已登入後台
      當 管理員進入 WooCommerce > 設定 > Power Checkout 設定 分頁
      那麼 Settings SPA 掛載在 #power-checkout-wc-setting-app
      而且 Vue Router 以 createMemoryHistory 模式初始化
      而且 預設路由為 /payments
      而且 URL 不包含 # 符號

    場景: 頁面內導航正常運作
      假設 管理員已在 Power Checkout 設定頁
      當 管理員點擊「發票」分頁
      那麼 畫面切換至 /invoices 路由
      而且 URL 不出現 # 符號
      而且 瀏覽器網址列不變（仍為 admin.php?page=wc-settings&tab=power_checkout_wc_settings）

    場景: 重新整理頁面回到預設路由
      假設 管理員已在 /invoices/amego 路由
      當 管理員重新整理瀏覽器
      那麼 Settings SPA 重新掛載
      而且 路由重置為預設的 /payments
      而且 無 console 錯誤

  規則: 訂單詳情頁不受 Router 影響

    場景: 訂單詳情頁不載入 Vue Router 行為
      假設 管理員已登入後台
      當 管理員進入任一訂單詳情頁
      那麼 RefundDialog 正常掛載並可操作
      而且 InvoiceApp MetaBox 正常掛載並可操作
      而且 瀏覽器 console 無 vue-router 相關警告
      而且 URL hash 變更不觸發任何 Vue 層級行為

  規則: 前台結帳頁不受 Router 影響

    場景: 前台結帳頁不受 Hash Router 影響
      假設 顧客在前台結帳頁
      當 結帳頁面載入
      那麼 InvoiceApp 發票表單正常掛載並可操作
      而且 瀏覽器 console 無 vue-router 相關警告
      而且 URL hash 變更不觸發任何 Vue 層級行為

  規則: 既有功能不受影響

    場景: 退款對話框功能正常
      假設 管理員已在訂單詳情頁
      當 管理員點擊退款按鈕
      那麼 RefundDialog 正常開啟
      而且 退款操作正常執行

    場景: 發票開立功能正常
      假設 管理員已在訂單詳情頁
      當 管理員點擊開立發票按鈕
      那麼 發票開立 API 正常呼叫
      而且 發票狀態正確更新
