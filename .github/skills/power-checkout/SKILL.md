---
name: power-checkout
description: "Power Checkout — WooCommerce 金流與電子發票整合外掛開發指引。Shopline Payment 跳轉式金流、光貿電子發票、Vue 3 後台設定介面、Provider 模式架構。使用 /power-checkout 觸發。"
origin: project-analyze
---

# power-checkout — 開發指引

> WordPress Plugin，整合 Shopline Payment（跳轉式金流）與光貿（Amego）電子發票，採用 Provider 模式支援多金流/發票提供商，Vue 3 後台管理介面。

## When to Activate

當使用者在此專案中：
- 修改 `inc/classes/**/*.php`（金流、發票、Provider 邏輯）
- 修改 `js/src/**/*.vue` 或 `js/src/**/*.ts`（Vue 3 後台介面）
- 新增金流或發票提供商
- 詢問 Webhook 驗簽、HPOS 相容性、WooCommerce Blocks 整合問題

## 架構概覽

**技術棧：**
- **語言**: PHP 8.1+（`declare(strict_types=1)`）
- **框架**: WordPress 5.7+、WooCommerce 8.3.0+
- **關鍵依賴**: `j7-dev/wp-utils ^0.3`、`giggsey/libphonenumber-for-php-lite ^9.0`
- **前端**: Vue 3 + TypeScript + Element Plus + TanStack Vue Query + Vue Router 4
- **建置**: Vite 6.3（開發 port 5182）
- **代碼風格**: PHPCS（WordPress-Core）、PHPStan Level 9、ESLint + Prettier

## 目錄結構

```
power-checkout/
├── plugin.php                                       # 主入口（PluginTrait + SingletonTrait）
├── inc/classes/
│   ├── Bootstrap.php                                # 初始化（相容性檢查 + 註冊所有 Domain）
│   ├── Domains/
│   │   ├── Payment/
│   │   │   ├── ProviderRegister.php                 # 支付方式註冊（gateway_services map）
│   │   │   ├── ShoplinePayment/
│   │   │   │   ├── Services/RedirectGateway.php     # Shopline 跳轉式 Gateway（ID: shopline_payment_redirect）
│   │   │   │   ├── Http/
│   │   │   │   │   ├── ApiClient.php                # Shopline API 客戶端
│   │   │   │   │   └── WebHook.php                  # Webhook 接收 + HMAC 驗簽
│   │   │   │   ├── Managers/
│   │   │   │   │   ├── StatusManager.php            # 訂單狀態映射
│   │   │   │   │   └── EventTypeManager.php         # Webhook 事件類型
│   │   │   │   ├── DTOs/                            # Shopline DTO（RedirectSettingsDTO、RequestHeader 等）
│   │   │   │   └── Shared/Enums/                   # 支付方式、貨幣、錯誤碼 Enum
│   │   │   ├── EcpayAIO/                            # ECPay AIO（開發中）
│   │   │   └── Shared/
│   │   │       ├── Abstracts/AbstractPaymentGateway.php  # WooCommerce Gateway 基類
│   │   │       ├── Interfaces/IGateway.php              # Provider 介面
│   │   │       ├── Services/PaymentApiService.php        # 金流 REST API 端點
│   │   │       └── Helpers/MetaKeys.php                  # Meta Key 常數
│   │   ├── Invoice/
│   │   │   ├── ProviderRegister.php                 # 發票提供商註冊
│   │   │   ├── Amego/
│   │   │   │   ├── Services/AmegoProvider.php        # 光貿電子發票（ID: amego）
│   │   │   │   ├── Http/ApiClient.php                # 光貿 API 客戶端
│   │   │   │   └── DTOs/                             # 發票相關 DTO
│   │   │   └── Shared/
│   │   │       ├── Interfaces/IInvoiceService.php    # 發票 Provider 介面
│   │   │       └── Services/InvoiceApiService.php    # 發票 REST API 端點
│   │   └── Settings/Services/
│   │       ├── SettingTabService.php                 # WooCommerce 設定分頁
│   │       └── SettingApiService.php                 # 設定 REST API（GET/POST /settings/{id}）
│   └── Shared/
│       ├── Abstracts/BaseService.php                 # 基底服務
│       └── Utils/
│           ├── ProviderUtils.php                     # Provider 容器（$container、is_enabled、toggle）
│           ├── OrderUtils.php                        # HPOS 相容工具（$order->get_meta()）
│           └── CheckoutFields.php                    # 結帳欄位註冊
├── js/src/
│   ├── index.ts                                      # 入口，掛載 3 個 Vue App
│   ├── App.vue                                       # 根元件（側邊導航 + Router）
│   ├── router/index.ts                               # Vue Router（Hash 模式）
│   ├── api/index.ts                                  # Axios 實例（baseURL: /power-checkout/v1/）
│   ├── pages/
│   │   ├── Payments/
│   │   │   ├── index.vue                             # 金流列表頁
│   │   │   └── SLP/index.vue                         # Shopline Payment 設定頁（useMutation + useQuery）
│   │   └── Invoices/
│   │       ├── index.vue                             # 發票列表頁
│   │       └── Amego/index.vue                       # 光貿設定頁
│   ├── external/
│   │   ├── RefundDialog/                             # 訂單退款 Dialog（掛載於訂單詳情頁）
│   │   └── InvoiceApp/                               # 發票應用（訂單詳情 + 結帳頁）
│   └── types/global.d.ts                             # Window 全域型別宣告
└── inc/assets/blocks/
    └── shopline_payment_redirect.tsx                 # WooCommerce Checkout Block
```

## REST API 端點

| 方法 | 端點 | 說明 |
|------|------|------|
| GET | `/power-checkout/v1/settings` | 取得所有 Provider 設定 |
| GET | `/power-checkout/v1/settings/{id}` | 取得單一 Provider 設定 |
| POST | `/power-checkout/v1/settings/{id}` | 更新 Provider 設定 |
| POST | `/power-checkout/v1/settings/{id}/toggle` | 開關 Provider |
| POST | `/power-checkout/v1/refund` | Gateway 退款 |
| POST | `/power-checkout/v1/invoices/issue/{order_id}` | 開立電子發票 |
| POST | `/power-checkout/v1/invoices/cancel/{order_id}` | 作廢電子發票 |
| POST | `/power-checkout/slp/webhook` | Shopline Webhook（無需 Nonce） |

所有需認證端點需 `X-WP-Nonce` header。

## Provider 模式

```php
// ProviderUtils - Provider 容器
abstract class ProviderUtils {
    public static array $container = [];  // ID => 實例

    public static function is_enabled(string $id): bool { ... }
    public static function get_provider(string $id): mixed { ... }
    public static function toggle(string $id): void { ... }
}

// 新增 Provider 只需實作 IGateway 或 IInvoiceService
// 並在 ProviderRegister::gateway_services 中註冊 ID => Class
```

## Vue 3 前端模式

```typescript
// TanStack Vue Query - API 查詢
const { data } = useQuery({
    queryKey: ['settings', 'shopline_payment_redirect'],
    queryFn: () => apiClient.get('/settings/shopline_payment_redirect'),
    staleTime: 15 * 60 * 1000,  // 15 分鐘
})

// Axios 攔截器自動顯示 ElNotification
// Response 攔截：成功顯示綠色通知，失敗顯示錯誤通知
```

## 命名慣例

| 類型 | 慣例 | 範例 |
|------|------|------|
| PHP Namespace | PascalCase | `J7\PowerCheckout\Domains\Payment\ShoplinePayment` |
| PHP 類別 | PascalCase（final） | `final class RedirectGateway` |
| Provider ID | snake_case | `shopline_payment_redirect`、`amego` |
| Vue 元件 | PascalCase.vue | `SLP/index.vue` |
| Composable | use 前綴 | `useQuery`、`useMutation` |
| Text Domain | snake_case | `power_checkout` |

## 開發規範

1. 不使用 `get_post_meta()`，改用 `$order->get_meta()`（HPOS 相容）
2. 新金流需實作 `IGateway`，新發票需實作 `IInvoiceService`
3. Webhook 驗簽必須使用 HMAC，拒絕不合法請求
4. Vue 前端使用 `@tanstack/vue-query` 管理 API 狀態，不使用 Vuex/Pinia
5. PHPStan Level 9 必須通過

## 常用指令

```bash
composer install           # 安裝 PHP 依賴
pnpm install               # 安裝 Node 依賴
pnpm dev                   # Vite 開發伺服器（port 5182）
pnpm build                 # 建置到 js/dist/
vendor/bin/phpcs           # PHP 代碼風格檢查
vendor/bin/phpstan analyse # PHPStan 靜態分析（Level 9）
pnpm release               # 發佈 patch 版本
```

## 相關 SKILL

- `wordpress-master` — WordPress Plugin 開發通用指引
- `wp-rest-api` — REST API 設計規範
