@ignore
Feature: 作廢電子發票

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And "amego" 已啟用
    And Amego 設定如下：
      | key                          | value            |
      | mode                         | test             |
      | auto_cancel_order_statuses   | ["wc-refunded"]  |
    And 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |
    And 訂單 #100 已開立發票：
      | _pc_issued_invoice_data   | {"invoice_number": "AB12345678"} |
      | _pc_invoice_provider_id   | amego                            |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- 訂單必須存在
    Example: 訂單不存在
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/cancel/9999
      Then 回應狀態碼為 500
      And 回應包含 "找不到訂單"

  Rule: 前置（參數）- 必須有對應的 invoice provider
    Example: 訂單的 _pc_invoice_provider_id 對應不到已啟用的 provider
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 的 _pc_invoice_provider_id 為 "nonexistent"
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      Then 回應狀態碼為 500
      And 回應包含 "不是 Invoice Service"

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 已作廢過的發票不重複作廢
    Example: 重複作廢直接回傳已有資料
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 的 _pc_cancelled_invoice_data 已有值
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      Then 回應狀態碼為 200
      And Amego API 未被呼叫

  Rule: 後置（狀態）- 成功作廢時清除開立資料並儲存作廢資料
    Example: 首次作廢發票成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 尚未作廢發票
      And Amego API 作廢發票回傳成功
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/cancel/100
      Then 回應狀態碼為 200
      And 訂單 #100 的 _pc_issue_invoice_params 已被清除
      And 訂單 #100 的 _pc_issued_invoice_data 已被清除
      And 訂單 #100 的 _pc_invoice_provider_id 已被清除
      And 訂單 #100 的 _pc_cancelled_invoice_data 有值

  Rule: 後置（狀態）- 訂單狀態變更時自動作廢發票
    Example: 訂單進入 refunded 狀態時自動作廢
      Given Amego auto_cancel_order_statuses 包含 "wc-refunded"
      And 訂單 #100 已開立發票
      When 訂單 #100 狀態從 "processing" 變為 "refunded"
      Then WooCommerce 觸發 woocommerce_order_status_refunded hook
      And Amego Provider 的 cancel 方法被呼叫
      And 訂單 #100 的 _pc_cancelled_invoice_data 有值
