@ignore
Feature: 取得單一服務設定

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And 系統中 "shopline_payment_redirect" 已啟用
    And 系統中 "amego" 已啟用

  # ========== 前置（參數）==========
  Rule: 前置（參數）- provider_id 必須存在於已啟用容器中
    Example: 查詢不存在的 provider 會拋出 Exception
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings/nonexistent_provider
      Then 回應狀態碼為 500
      And 回應訊息包含 "Can't find Provider"

  # ========== 後置（回應）==========
  Rule: 後置（回應）- 回傳指定 provider 的完整設定
    Example: 成功取得 Shopline Payment 設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings/shopline_payment_redirect
      Then 回應狀態碼為 200
      And 回應 data 包含 "merchantId"
      And 回應 data 包含 "allowPaymentMethodList"
      And 回應 data 包含 "mode"

    Example: 成功取得 Amego 電子發票設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings/amego
      Then 回應狀態碼為 200
      And 回應 data 包含 "invoice"
      And 回應 data 包含 "app_key"
      And 回應 data 包含 "tax_rate"
