@ignore @query
Feature: 取得所有設定

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And 系統中有以下 Provider 設定：
      | provider_id                    | enabled | title                        |
      | shopline_payment_redirect      | yes     | Shopline Payment 線上付款     |
      | amego                          | yes     | 光貿電子發票                  |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 必須具備管理員權限
    Example: 未登入的訪客無法取得設定
      Given 用戶未登入
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 401

  # ========== 後置（回應）==========
  Rule: 後置（回應）- 回傳 gateways、invoices、logistics 三個分類
    Example: 成功取得所有設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And 回應 code 為 "get_settings_success"
      And 回應 message 為 "取得設定成功"
      And 回應 data 包含 "gateways" 陣列
      And 回應 data 包含 "invoices" 陣列
      And 回應 data 包含 "logistics" 陣列

    Example: gateways 包含已註冊的 Power Checkout Gateway
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And "gateways" 包含 id 為 "shopline_payment_redirect" 的項目
      And 該項目包含 "title", "enabled", "icon", "method_title" 欄位

    Example: invoices 包含已註冊的 Invoice Provider
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And "invoices" 包含 id 為 "amego" 的項目

  Rule: 後置（回應）- Gateway 清單從 WC_Payment_Gateways 取得，不暴露敏感金鑰
    Example: Gateway 摘要不含 API 金鑰
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "apiKey"
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "clientKey"
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "signKey"

  Rule: 後置（回應）- Invoice 清單從 ProviderRegister::$invoice_providers 建立 DTO
    Example: Invoice Provider 使用 BaseSettingsDTO 格式
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And "invoices" 中的每個項目包含 "id", "title", "enabled", "icon", "method_title" 欄位
