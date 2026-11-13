# language: zh-TW
@command
功能: 結帳體驗強化設定
  作為 管理員
  我想要 在 Power Checkout 設定頁開關台灣結帳強化功能
  以便 依商店需求啟用姓名排序、台灣地址、手機檢查、虛擬商品與免運提示

  # 領域：Domains/Checkout（新）；設定以虛擬 provider id `checkout_enhancement` 存 wp_options `woocommerce_checkout_enhancement_settings`。
  # 重用既有 GET/POST /wp-json/power-checkout/v1/settings/{provider_id}（provider 未實例化時直接讀寫 option），不新增 REST 端點。
  # Vue SPA 左側選單新增「結帳」→ /checkout（js/src/pages/Checkout/index.vue），ROUTER_MAPPER 新增 checkout_enhancement。
  # 權限沿用 ApiBase 預設（manage_options 或 manage_woocommerce）+ X-WP-Nonce。
  # 預設值（Q3=全部關閉、Q6 文案）：
  #   last_name_first=no / tw_address=no / tw_phone_validation=no / virtual_hide_address=no / virtual_auto_complete=no / free_shipping_hint=no
  #   free_shipping_text_left="再買 {price} 元即可享免運" / free_shipping_text_achieved="已享免運"
  #   free_shipping_bg_color="#d36f6f" / free_shipping_text_color="#ffffff"
  # 設定頁另顯示提示：「若同站 woomp 已開啟同名功能，請擇一啟用以免重複顯示或重複驗證」（純 UI 文案，不做偵測）。

  背景:
    假設 管理員已登入並取得 Nonce

  規則: 前置（狀態）- 未登入或無 manage_options / manage_woocommerce 權限者不可讀寫設定

    場景大綱: <身分> 存取結帳強化設定被拒
      假設 目前身分為 "<身分>"
      當 發送 <方法> /wp-json/power-checkout/v1/settings/checkout_enhancement
      那麼 回應狀態碼為 <狀態碼>

      例子:
        | 身分     | 方法 | 狀態碼 |
        | 訪客     | GET  | 401    |
        | 訪客     | POST | 401    |
        | 一般顧客 | POST | 403    |

  規則: 後置（回應）- 從未儲存過設定時，讀取回傳全部預設值（開關皆為 no）

    場景: 首次讀取取得預設設定
      假設 wp_options 中不存在 "woocommerce_checkout_enhancement_settings"
      當 管理員發送 GET /wp-json/power-checkout/v1/settings/checkout_enhancement
      那麼 回應狀態碼為 200
      而且 回應 data 應為：
        | key                         | value                    |
        | last_name_first             | no                       |
        | tw_address                  | no                       |
        | tw_phone_validation         | no                       |
        | virtual_hide_address        | no                       |
        | virtual_auto_complete       | no                       |
        | free_shipping_hint          | no                       |
        | free_shipping_text_left     | 再買 {price} 元即可享免運 |
        | free_shipping_text_achieved | 已享免運                  |
        | free_shipping_bg_color      | #d36f6f                  |
        | free_shipping_text_color    | #ffffff                  |

  規則: 後置（狀態）- 儲存後寫入 woocommerce_checkout_enhancement_settings，未送出的鍵保留原值

    場景: 只開啟手機檢查與免運提示，其餘維持原值
      假設 woocommerce_checkout_enhancement_settings 為：
        | key                 | value |
        | last_name_first     | yes   |
        | tw_phone_validation | no    |
        | free_shipping_hint  | no    |
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/checkout_enhancement：
        | key                 | value |
        | tw_phone_validation | yes   |
        | free_shipping_hint  | yes   |
      那麼 回應狀態碼為 200
      而且 回應 message 為 "儲存成功"
      而且 woocommerce_checkout_enhancement_settings 應包含：
        | key                 | value |
        | last_name_first     | yes   |
        | tw_phone_validation | yes   |
        | free_shipping_hint  | yes   |

  規則: 前置（參數）- 開關值須為 yes/no、色碼須為 #RRGGBB、未達門檻文案須含 {price}；不合法的鍵忽略並維持原值，其餘合法鍵照常儲存

    場景大綱: 送出不合法的 <key> 時維持原值
      假設 woocommerce_checkout_enhancement_settings 的 "<key>" 為 "<原值>"
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/checkout_enhancement：
        | key   | value  |
        | <key> | <新值> |
      那麼 回應狀態碼為 200
      而且 woocommerce_checkout_enhancement_settings 的 "<key>" 應為 "<原值>"

      例子:
        | key                      | 原值                     | 新值       |
        | tw_address               | no                       | true       |
        | free_shipping_bg_color   | #d36f6f                  | red        |
        | free_shipping_text_color | #ffffff                  | #fff       |
        | free_shipping_text_left  | 再買 {price} 元即可享免運 | 快達免運了 |

  規則: 前置（參數）- 文案中的 HTML 標籤於儲存時移除（沿用 sanitize_text_field_deep）

    場景: 文案中的 script 標籤被移除後儲存
      當 管理員發送 POST /wp-json/power-checkout/v1/settings/checkout_enhancement：
        | key                         | value                           |
        | free_shipping_text_achieved | <script>alert(1)</script>免運啦 |
      那麼 woocommerce_checkout_enhancement_settings 的 "free_shipping_text_achieved" 應為 "免運啦"
