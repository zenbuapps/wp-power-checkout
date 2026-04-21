# language: zh-TW
功能: Provider 設定管理
  作為 網站管理員
  我想要 管理金流和發票服務的設定
  以便 控制結帳體驗

  背景:
    假設 管理員已登入後台
    而且 已進入 WooCommerce > 設定 > Power Checkout 設定 分頁
    而且 系統中有以下 Provider 設定：
      | provider_id                | enabled | title                     |
      | shopline_payment_redirect  | yes     | Shopline Payment 線上付款 |
      | amego                      | yes     | 光貿電子發票              |

  規則: 未登入的訪客無法存取設定 API

    場景: 未登入時 GET /settings 回應 401
      假設 用戶未登入
      當 用戶發送 GET /wp-json/power-checkout/v1/settings
      那麼 回應狀態碼為 401

    場景: 未登入時 POST /settings/{id} 回應 401
      假設 用戶未登入
      當 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
      那麼 回應狀態碼為 401

    場景: 未登入時 POST /settings/{id}/toggle 回應 401
      假設 用戶未登入
      當 用戶發送 POST /wp-json/power-checkout/v1/settings/amego/toggle
      那麼 回應狀態碼為 401

  規則: 查看所有 Provider 設定

    場景: 成功取得所有設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings
      那麼 回應狀態碼為 200
      而且 回應 code 為 "get_settings_success"
      而且 回應 message 為 "取得設定成功"
      而且 回應 data 包含 "gateways" 陣列
      而且 回應 data 包含 "invoices" 陣列
      而且 回應 data 包含 "logistics" 陣列

    場景: gateways 包含已註冊的 Power Checkout Gateway
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings
      那麼 "gateways" 包含 id 為 "shopline_payment_redirect" 的項目
      而且 該項目包含 "title", "enabled", "icon", "method_title" 欄位

    場景: invoices 包含已註冊的 Invoice Provider
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings
      那麼 "invoices" 包含 id 為 "amego" 的項目

    場景: Gateway 摘要不暴露敏感金鑰
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings
      那麼 "gateways" 中的 "shopline_payment_redirect" 不包含 "apiKey"
      而且 "gateways" 中的 "shopline_payment_redirect" 不包含 "clientKey"
      而且 "gateways" 中的 "shopline_payment_redirect" 不包含 "signKey"

  規則: 取得單一服務設定

    場景: 成功取得 Shopline Payment 設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings/shopline_payment_redirect
      那麼 回應狀態碼為 200
      而且 回應 code 為 "success"
      而且 回應 data 包含以下欄位：
        | field                  |
        | enabled                |
        | title                  |
        | description            |
        | icon                   |
        | mode                   |
        | platformId             |
        | merchantId             |
        | apiKey                 |
        | clientKey              |
        | signKey                |
        | apiUrl                 |
        | allowPaymentMethodList |
        | paymentMethodOptions   |
        | expire_min             |
        | min_amount             |
        | max_amount             |
        | order_button_text      |

    場景: 成功取得 Amego 電子發票設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings/amego
      那麼 回應狀態碼為 200
      而且 回應 data 包含以下欄位：
        | field                      |
        | enabled                    |
        | title                      |
        | description                |
        | icon                       |
        | mode                       |
        | invoice                    |
        | app_key                    |
        | tax_rate                   |
        | auto_issue_order_statuses  |
        | auto_cancel_order_statuses |

    場景: 查詢不存在的 provider 會拋出 Exception
      假設 管理員已登入並取得 Nonce
      當 管理員發送 GET /wp-json/power-checkout/v1/settings/nonexistent_provider
      那麼 回應狀態碼為 500
      而且 回應訊息包含 "Can't find Provider"

  規則: 更新 Provider 設定

    場景: 成功更新 Shopline Payment 設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key        | value            |
        | merchantId | test_merchant_id |
        | mode       | test             |
      那麼 回應狀態碼為 200
      而且 回應 code 為 "success"
      而且 回應 message 為 "儲存成功"
      而且 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "merchantId" 為 "test_merchant_id"

    場景: 成功更新 Amego 電子發票設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/amego，參數為：
        | key      | value              |
        | invoice  | 12345678           |
        | app_key  | test_app_key_value |
        | tax_rate | 0.05               |
      那麼 回應狀態碼為 200
      而且 wp_options 中 "woocommerce_amego_settings" 的 "invoice" 為 "12345678"

    場景: 回傳更新後的完整設定
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/amego，參數為：
        | key     | value    |
        | invoice | 87654321 |
      那麼 回應 data 中的 "invoice" 為 "87654321"

    場景: 所有參數值會經過 sanitize_text_field_deep 消毒
      假設 管理員已登入並取得 Nonce
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect，參數為：
        | key   | value                     |
        | title | <script>alert(1)</script> |
      那麼 回應狀態碼為 200
      而且 回應 data 中的 "title" 不包含 "<script>"

    場景: SLP 最小金額驗證
      假設 管理員已登入並取得 Nonce
      當 管理員將最小金額設為 3
      那麼 儲存失敗顯示 "minimum amount out of range"

    場景: SLP 最大金額驗證
      假設 管理員已登入並取得 Nonce
      當 管理員將最大金額設為 60000
      那麼 儲存失敗顯示 "maximum amount out of range"

  規則: 切換 Provider 啟用狀態

    場景: 將已啟用的服務停用
      假設 管理員已登入並取得 Nonce
      而且 "amego" 目前 enabled 為 "yes"
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/amego/toggle
      那麼 回應狀態碼為 200
      而且 回應 code 為 "success"
      而且 回應 message 包含 "禁用成功"
      而且 回應 data 為 "amego"
      而且 wp_options 中 "woocommerce_amego_settings" 的 "enabled" 為 "no"

    場景: 將已停用的服務啟用
      假設 管理員已登入並取得 Nonce
      而且 "shopline_payment_redirect" 目前 enabled 為 "no"
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect/toggle
      那麼 回應狀態碼為 200
      而且 回應 message 包含 "啟用成功"
      而且 wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "enabled" 為 "yes"

  規則: LINE Pay 付款方式設定

    場景: 啟用 LINE Pay 付款方式
      假設 管理員已登入並取得 Nonce
      當 管理員在 SLP 設定頁面勾選 LINE Pay
      而且 儲存設定
      那麼 allowPaymentMethodList 包含 "LinePay"

    場景: LINE Pay 勾選後顯示警告提示
      假設 管理員已登入並取得 Nonce
      當 管理員勾選 LINE Pay
      那麼 顯示 warning 類型警告 "請先確認已在 SLP 後台啟用 LINE Pay"

    場景: LINE Pay 預設未啟用（向下相容）
      假設 商店從未設定過 allowPaymentMethodList
      那麼 預設的 allowPaymentMethodList 包含 "LinePay"
      但是 既有商店升級後不會自動將 "LinePay" 加入已儲存的 allowPaymentMethodList

    場景: LINE Pay 不需要 paymentMethodOptions
      假設 管理員已登入並取得 Nonce
      當 管理員啟用 LINE Pay
      那麼 paymentMethodOptions 不包含 LinePay 的設定項
      而且 不顯示 LINE Pay 的分期期數選項
