@ignore @query
Feature: 取得單一服務設定

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And 系統中 "shopline_payment_redirect" 已啟用
    And 系統中 "amego" 已啟用

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 必須具備管理員權限
    Example: 未登入的訪客無法取得設定
      Given 用戶未登入
      When 用戶發送 GET /wp-json/power-checkout/v1/settings/shopline_payment_redirect
      Then 回應狀態碼為 401

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
      And 回應 code 為 "success"
      And 回應 data 包含以下欄位：
        | field                    |
        | enabled                  |
        | title                    |
        | description              |
        | icon                     |
        | mode                     |
        | merchantId               |
        | apiKey                   |
        | clientKey                |
        | signKey                  |
        | apiUrl                   |
        | allowPaymentMethodList   |
        | paymentMethodOptions     |
        | expire_min               |
        | min_amount               |
        | max_amount               |
        | order_button_text        |

    Example: 成功取得 Amego 電子發票設定
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 GET /wp-json/power-checkout/v1/settings/amego
      Then 回應狀態碼為 200
      And 回應 data 包含以下欄位：
        | field                        |
        | enabled                      |
        | title                        |
        | description                  |
        | icon                         |
        | mode                         |
        | invoice                      |
        | app_key                      |
        | tax_rate                     |
        | auto_issue_order_statuses    |
        | auto_cancel_order_statuses   |
