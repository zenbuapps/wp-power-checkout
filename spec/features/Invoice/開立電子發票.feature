@ignore @command
Feature: 開立電子發票

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@example.com | administrator |
    And "amego" 已啟用
    And Amego 設定如下：
      | key                        | value              |
      | invoice                    | 12345678           |
      | app_key                    | test_app_key       |
      | tax_rate                   | 0.05               |
      | mode                       | test               |
      | auto_issue_order_statuses  | ["wc-processing"]  |
    And 系統中有以下訂單：
      | orderId | userId | total | status     |
      | 100     | 1      | 1000  | processing |

  # ========== 前置（參數）==========
  Rule: 前置（參數）- provider 必須是已啟用的 Invoice Provider
    Example: 指定不存在的 provider
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key      | value           |
        | provider | nonexistent     |
      Then 回應狀態碼為 500
      And 回應包含 "找不到電子發票服務"

    Example: provider 不是 IInvoiceService 實例
      Given 用戶 "Admin" 已登入並取得 Nonce
      And "fake_provider" 在容器中但不是 IInvoiceService
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key      | value           |
        | provider | fake_provider   |
      Then 回應狀態碼為 500
      And 回應包含 "不是 Invoice Service"

  Rule: 前置（參數）- 訂單必須存在
    Example: 訂單不存在
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/9999
        | key      | value |
        | provider | amego |
      Then 回應狀態碼為 500
      And 回應包含 "找不到訂單"

  # ========== 後置（狀態）==========
  Rule: 後置（狀態）- 已開立過的發票不重複開立
    Example: 重複開立直接回傳已有資料
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 的 _pc_issued_invoice_data 已有值
        | key            | value        |
        | invoice_number | AB12345678   |
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key      | value |
        | provider | amego |
      Then 回應狀態碼為 200
      And 回應 data 包含 "invoice_number" 為 "AB12345678"
      And Amego API 未被呼叫

  Rule: 後置（狀態）- 成功開立發票時儲存相關 meta
    Example: 首次開立個人雲端發票成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 尚未開立發票
      And Amego API 開立發票回傳成功
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key         | value      |
        | provider    | amego      |
        | invoiceType | individual |
        | individual  | cloud      |
      Then 回應狀態碼為 200
      And 訂單 #100 的 _pc_issued_invoice_data 有值
      And 訂單 #100 的 _pc_invoice_provider_id 為 "amego"
      And 訂單 #100 的 _pc_issue_invoice_params 有值

    Example: 首次開立公司發票成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 尚未開立發票
      And Amego API 開立發票回傳成功
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key         | value      |
        | provider    | amego      |
        | invoiceType | company    |
        | companyName | 測試公司    |
        | companyId   | 87654321   |
      Then 回應狀態碼為 200
      And 訂單 #100 的 _pc_issued_invoice_data 有值

    Example: 首次開立捐贈發票成功
      Given 用戶 "Admin" 已登入並取得 Nonce
      And 訂單 #100 尚未開立發票
      And Amego API 開立發票回傳成功
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key         | value   |
        | provider    | amego   |
        | invoiceType | donate  |
        | donateCode  | 7788    |
      Then 回應狀態碼為 200
      And 訂單 #100 的 _pc_issued_invoice_data 有值

  Rule: 後置（狀態）- 訂單狀態變更時自動開立發票
    Example: 訂單進入 processing 狀態時自動開立
      Given Amego auto_issue_order_statuses 包含 "wc-processing"
      And 訂單 #100 尚未開立發票
      When 訂單 #100 狀態從 "pending" 變為 "processing"
      Then WooCommerce 觸發 woocommerce_order_status_processing hook
      And Amego Provider 的 issue 方法被呼叫
      And 訂單 #100 的 _pc_issued_invoice_data 有值

  Rule: 後置（狀態）- API 呼叫時同步儲存發票參數到 order meta
    Example: issue API 呼叫前先儲存 issue_params
      Given 用戶 "Admin" 已登入並取得 Nonce
      When 用戶發送 POST /wp-json/power-checkout/v1/invoices/issue/100
        | key         | value      |
        | provider    | amego      |
        | invoiceType | individual |
        | individual  | barcode    |
        | carrier     | /ABC1234   |
      Then 訂單 #100 的 _pc_issue_invoice_params 包含 carrier 為 "/ABC1234"
