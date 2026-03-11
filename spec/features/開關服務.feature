@ignore
Feature: 開關服務

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 必須具備管理員權限
    Example: 未登入的訪客無法切換服務
      Given 用戶未登入
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/amego/toggle
      Then 回應狀態碼為 401

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- enabled 值在 yes/no 之間切換
    Example: 將已啟用的服務停用
      Given 用戶 "Admin" 已登入並取得 Nonce
      And "amego" 目前 enabled 為 "yes"
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/amego/toggle
      Then 回應狀態碼為 200
      And 回應 message 包含 "禁用成功"
      And wp_options 中 "woocommerce_amego_settings" 的 "enabled" 為 "no"

    Example: 將已停用的服務啟用
      Given 用戶 "Admin" 已登入並取得 Nonce
      And "shopline_payment_redirect" 目前 enabled 為 "no"
      When 用戶發送 POST /wp-json/power-checkout/v1/settings/shopline_payment_redirect/toggle
      Then 回應狀態碼為 200
      And 回應 message 包含 "啟用成功"
      And wp_options 中 "woocommerce_shopline_payment_redirect_settings" 的 "enabled" 為 "yes"
