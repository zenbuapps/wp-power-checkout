@ignore @command
Feature: 更新服務設定

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And 系統中 "shopline_payment_redirect" 已啟用
    And 系統中 "amego" 已啟用

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 必須具備管理員權限
    Example: 未登入的訪客無法更新設定
      Given 用戶未登入
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
        | key        | value          |
        | merchantId | new_merchant   |
      Then 回應狀態碼為 401

  Rule: 前置（參數）- provider_id 必須符合 pattern ^[a-zA-Z_-]+$
    Example: 合法的 provider_id
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
        | key   | value |
        | title | Test  |
      Then 回應狀態碼為 200

  Rule: 前置（參數）- 所有參數值會經過 sanitize_text_field_deep 消毒
    Example: HTML 標籤會被過濾
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
        | key   | value                      |
        | title | <script>alert(1)</script>  |
      Then 回應狀態碼為 200
      And 回應 data 中的 "title" 不包含 "<script>"

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 設定值寫入 wp_options 表
    Example: 成功更新 Shopline Payment 設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect
        | key        | value            |
        | merchantId | test_merchant_id |
        | mode       | test             |
      Then 回應狀態碼為 200
      And 回應 code 為 "success"
      And 回應 message 為 "儲存成功"
      And wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "merchantId" 為 "test_merchant_id"

    Example: 成功更新 Amego 電子發票設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/amego
        | key      | value                |
        | invoice  | 12345678             |
        | app_key  | test_app_key_value   |
        | tax_rate | 0.05                 |
      Then 回應狀態碼為 200
      And wp_options 中 "woocommerce_amego_settings" 的 "invoice" 為 "12345678"

  Rule: 後置（回應）- 回傳更新後的完整設定
    Example: 回傳更新後的設定值
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/amego
        | key     | value     |
        | invoice | 87654321  |
      Then 回應狀態碼為 200
      And 回應 data 中的 "invoice" 為 "87654321"
