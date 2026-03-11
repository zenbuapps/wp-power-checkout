@ignore
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
      And 回應中包含 "gateways" 陣列
      And 回應中包含 "invoices" 陣列
      And 回應中包含 "logistics" 陣列
      And "gateways" 包含 provider_id 為 "shopline_payment_redirect" 的項目
      And "invoices" 包含 provider_id 為 "amego" 的項目

  Rule: 後置（回應）- Gateway 清單中不暴露敏感金鑰
    Example: Gateway 設定不含 API 金鑰
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings
      Then 回應狀態碼為 200
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "apiKey"
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "clientKey"
      And "gateways" 中的 "shopline_payment_redirect" 不包含 "signKey"
