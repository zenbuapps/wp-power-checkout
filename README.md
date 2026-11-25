# Power Checkout

> **讓結帳頁，為你的轉換率加上渦輪**
> 從金流、電子發票到結帳欄位，全面優化 WooCommerce 的結帳體驗

**Version:** 1.0.27 | **Requires WooCommerce:** 8.3.0+ | **Requires PHP:** 8.1+ | **License:** GPL-2.0

---

## 功能特色

### 💳 SHOPLINE Payments 金流整合
- 跳轉式付款（Redirect Gateway）
- 支援付款方式：信用卡一次/分期、ATM 虛擬帳號、Apple Pay、LINE Pay、街口支付、中租零卡分期
- HMAC-SHA256 Webhook 驗簽
- 自動退款 API 串接（含退款限制驗證）
- WooCommerce Blocks 結帳相容

### 🧾 Amego 光貿電子發票
- 電子發票開立（手動/自動）
- 電子發票作廢
- 依訂單狀態自動觸發開立/作廢
- 後台訂單 MetaBox 顯示發票狀態
- 傳統結帳頁發票資訊欄位

### ⚙️ 統一設定介面
- WooCommerce 設定分頁（Vue 3 SPA）
- 所有金流/發票服務的啟用開關
- REST API 驅動設定更新

### 🔧 其他
- HPOS (High-Performance Order Storage) 完整支援
- 台灣地址格式優化
- 訂單 MetaBox 精美 grid 格式顯示付款/退款詳情

---

## 系統需求

| 項目 | 需求 |
|---|---|
| PHP | 8.1+ |
| WordPress | 5.7+ |
| WooCommerce | 8.3.0+ |
| Powerhouse 外掛 | 3.3.38+（選用） |

---

## 安裝

1. 下載外掛 zip 或從 GitHub 安裝
2. 上傳並啟用外掛
3. 前往 **WooCommerce → 設定 → Power Checkout 設定** 進行設定
4. 啟用需要的金流/發票服務並填入 API 金鑰

---

## 設定說明

### SHOPLINE Payments 設定

前往 **WooCommerce → 設定 → Power Checkout 設定 → 金流 → SHOPLINE Payments（跳轉支付）**（嵌入支付共用這份設定）

| 設定 | 說明 |
|---|---|
| 特店 ID（Merchant ID） | [SHOPLINE Payments 商家後台](https://login.shoplinepayments.com/zh-Hant/signin/)「設定 → 開發者管理」 |
| API Key | 同上 |
| Client Key | 同上（嵌入支付必填） |
| Sign Key | 設定下方 Webhook URL 後取得 |
| 模式 | `test`（沙箱）或 `prod`（正式） |
| 付款方式 | 勾選允許的付款方式 |
| 最小/最大金額 | 限制訂單金額範圍 |
| 付款期限 | ATM/CVS 付款截止時間（分鐘） |

**Webhook URL（需設定至 SHOPLINE Payments 後台）：**
```
https://your-site.com/wp-json/power-checkout/slp/webhook
```

### Amego 電子發票設定

前往 **WooCommerce → 設定 → Power Checkout 設定 → 電子發票 → 光貿電子發票**

| 設定 | 說明 |
|---|---|
| 統一編號 | 你公司（開發票方）的統編 |
| App Key | 向光貿客服申請「API 串接」取得 |
| 稅率 | 預設 5%（0.05） |
| 模式 | `test` 或 `prod` |
| 自動開立狀態 | 訂單進入哪些狀態時自動開立發票 |
| 自動作廢狀態 | 訂單進入哪些狀態時自動作廢發票 |

---

## 開發指南

### 環境設置

```bash
# 安裝所有依賴
pnpm bootstrap

# 啟動前端開發伺服器（port 5182）
pnpm dev

# 建置前端
pnpm build
```

### 專案結構

```
power-checkout/
├── plugin.php                # 外掛入口
├── inc/classes/              # PHP 後端 (PSR-4: J7\PowerCheckout\)
│   ├── Bootstrap.php         # 初始化所有 Domain
│   ├── Domains/
│   │   ├── Payment/          # 金流 Domain（SLP）
│   │   ├── Invoice/          # 發票 Domain（Amego）
│   │   └── Settings/         # 設定 Domain
│   └── Shared/               # 共用工具
├── inc/tests/                # PHP 測試
├── js/src/                   # Vue 3 前端
│   ├── index.ts              # 入口
│   ├── App.vue               # 根元件
│   ├── router/               # Vue Router
│   ├── pages/                # 設定頁面
│   └── external/             # 訂單頁 Vue Apps
└── js/dist/                  # 建置輸出
```

### 技術棧

**後端**
- PHP 8.1+、PSR-4 Autoloading
- `j7-dev/wp-utils` — Singleton、DTO、ApiBase 工具
- `giggsey/libphonenumber-for-php-lite` — 電話號碼處理
- PHPUnit 測試、PHPCS (WordPress Coding Standards)、PHPStan level 9

**前端**
- Vue 3 + TypeScript + Vite
- Element Plus（UI 組件庫）
- @tanstack/vue-query（API 狀態管理）
- Vue Router 4（Hash 模式）
- Axios（HTTP 客戶端）

### PHP 開發規範

```php
<?php
declare(strict_types=1);          // 必須

namespace J7\PowerCheckout\Domains\Payment;

// ✅ 正確：final class
final class MyGateway extends AbstractPaymentGateway {
    public const ID = 'my_gateway';

    // ✅ 正確：靜態 hook callback
    public static function register_hooks(): void {
        \add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_method']);
    }

    // ✅ 正確：覆寫 before_process_payment，不要覆寫 process_payment
    protected function before_process_payment(\WC_Order $order): string {
        // 回傳付款跳轉 URL
        return 'https://payment.example.com/pay?id=...';
    }
}
```

### 新增金流 Provider

1. 建立 `inc/classes/Domains/Payment/{Name}/Services/{Name}Gateway.php`
   - 繼承 `AbstractPaymentGateway`
   - 定義 `public const ID`
   - 實作 `before_process_payment(WC_Order $order): string`
   - 實作 `static get_settings(bool $with_default = true): array`
2. 建立對應的 Settings DTO
3. 在 `ProviderRegister::$gateway_services` 中加入
4. 在 `js/src/router/index.ts` 新增路由

### 新增電子發票 Provider

1. 建立 `inc/classes/Domains/Invoice/{Name}/Services/{Name}Provider.php`
   - 繼承 `BaseService`
   - 實作 `IInvoiceService` 介面（`issue()`、`cancel()`、`get_invoice_number()`）
2. 在 `Invoice\ProviderRegister::$invoice_providers` 中加入
3. 在 `js/src/router/index.ts` 新增路由

### Vue 3 前端開發

```typescript
// ✅ 使用 Composition API
<script setup lang="ts">
import { ref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import apiClient from '@/api'

const { data, isLoading } = useQuery({
  queryKey: ['settings', providerId],
  queryFn: () => apiClient.get(`settings/${providerId}`),
})
</script>

// ✅ 使用 @ 路徑別名
import { env } from '@/utils/env'
// ❌ 不要用相對路徑
import { env } from '../../../utils/env'
```

### REST API

所有 API 端點需要 WP REST Nonce（`X-WP-Nonce` header）：

```javascript
// 已由 js/src/api/index.ts 自動處理
const response = await apiClient.get('settings')
const result = await apiClient.post('settings/shopline_payment_redirect', formData)
await apiClient.post('settings/amego/toggle')
```

### 代碼品質指令

```bash
# PHP
composer lint              # PHPCS 檢查
composer test              # PHPUnit (API_MODE=mock)
composer test:sandbox      # PHPUnit + 沙箱 API
composer test:coverage     # 測試覆蓋率報告

# 前端
pnpm lint                  # ESLint
pnpm lint:fix              # 自動修正
pnpm format                # Prettier
```

---

## REST API 文件

### Settings API

```
GET    /wp-json/power-checkout/v1/settings
GET    /wp-json/power-checkout/v1/settings/{provider_id}
POST   /wp-json/power-checkout/v1/settings/{provider_id}
POST   /wp-json/power-checkout/v1/settings/{provider_id}/toggle
```

### Payment API

```
POST   /wp-json/power-checkout/v1/refund          # Body: { order_id }
POST   /wp-json/power-checkout/v1/refund/manual   # Body: { order_id }
```

### Invoice API

```
POST   /wp-json/power-checkout/v1/invoices/issue/{order_id}
POST   /wp-json/power-checkout/v1/invoices/cancel/{order_id}
```

### Webhook (SHOPLINE Payments)

```
POST   /wp-json/power-checkout/slp/webhook
```

---

## 訂單 Meta 資料

| Meta Key | 說明 |
|---|---|
| `pc_payment_identity` | Shopline tradeOrderId（防重複處理用） |
| `pc_payment_detail` | 付款詳情（顯示在後台訂單頁）|
| `pc_refund_detail` | 退款詳情 |
| `pc_issued_data` | 電子發票開立回傳資料 |
| `pc_cancelled_data` | 電子發票作廢回傳資料 |
| `pc_provider_id` | 使用的發票 Provider ID |
| `pc_issue_params` | 結帳頁填寫的發票資訊 |
| `_pc_tax_type` | 商品稅別（電子發票用） |

---

## 版本發佈

```bash
pnpm sync:version      # 同步 package.json 版本 → plugin.php
pnpm release           # 發佈 patch 版本（自動 build + tag + GitHub release）
pnpm release:minor     # 發佈 minor 版本
pnpm release:major     # 發佈 major 版本
pnpm zip               # 只建立 zip（不發佈）
```

---

## 待辦事項

- [ ] 區塊結帳（Blocks Checkout）自訂欄位支援
- [ ] 物流功能整合
- [ ] 全域設定頁面
- [ ] ECPay AIO 金流（程式碼已存在，待啟用）

---

## 授權

GPL-2.0-only — see [LICENSE](LICENSE)

## 作者

[J7 / JerryLiu](https://github.com/j7-dev)
