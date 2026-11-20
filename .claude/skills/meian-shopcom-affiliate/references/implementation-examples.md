# 美安夥伴商店：實作範例

> ⚠️ **本檔程式碼為依官方 PDF 所述邏輯撰寫的實作示範，非 PDF 原文**。
> PDF 本身不含任何程式碼範例。參數名稱、固定值、金額語意皆已對齊 SKILL.md 中的 verbatim 參數表。
> 規格有疑義時，一律以 SKILL.md 的 verbatim 區塊為準。



### 擷取並保存 RID / Click_ID

```typescript
// 進站中介層：首頁與所有商品頁都要掛
// PDF 註3：cookie 保留到使用者關閉瀏覽器為止 → 不設 maxAge/expires 即為 session cookie
const MEIAN_COOKIE_MAX_LEN = 255; // PDF：兩參數皆為文字 255 個字元

function captureMeianParams(req: Request, res: Response, next: NextFunction) {
  const rid = req.query.RID;
  const clickId = req.query.Click_ID;

  // PDF 註1：僅需擷取並記錄，不需檢核格式 → 只做長度截斷，不做 pattern 驗證
  if (typeof rid === "string" && rid.length > 0) {
    res.cookie("meian_rid", rid.slice(0, MEIAN_COOKIE_MAX_LEN), {
      httpOnly: true,
      sameSite: "lax", // 由 tw.shop.com 導流進來，需能在跨站導航後讀到
    });
  }
  if (typeof clickId === "string" && clickId.length > 0) {
    res.cookie("meian_click_id", clickId.slice(0, MEIAN_COOKIE_MAX_LEN), {
      httpOnly: true,
      sameSite: "lax",
    });
  }
  next();
}
```

下單時把 cookie 中的 `meian_rid` / `meian_click_id` 連同訂單一起寫入 DB——**回拋時要用訂單「原始記錄到的」值**（PDF 欄位表第 6、7 列明文如此）。

### 金額計算

```typescript
/**
 * PDF 第 2 頁欄位表第 3 列：
 * 「帶入消費者使用您網站上的折扣之後，實際支付『商品本身』的金額。請勿加上運費、手續費。」
 * 官方範例：1,000 元商品 - 100 元折價券 + 80 元運費 → 傳 900
 */
function meianOrderAmount(order: Order): number {
  return order.itemsSubtotal - order.discountTotal; // 不含 shippingFee、不含 handlingFee
}

/**
 * PDF 第 2 頁欄位表第 4 列：
 * 「帶入佣金金額並計算至小數點第二位，計算方式為訂單金額乘上佣金百分比」
 * commissionRate 由美安於合約中約定，PDF 未載明其值。
 */
function meianCommission(orderAmount: number, commissionRate: number): number {
  return Math.round(orderAmount * commissionRate * 100) / 100; // 小數點第二位
}
```

### 訂單成立回拋

```typescript
import { URLSearchParams } from "node:url";

const MEIAN_API = "https://api.hasoffers.com/Api";

// 這些固定值 verbatim 取自 PDF；NetworkToken 屬憑證，務必由環境變數注入
const MEIAN_FIXED = {
  Format: "json",
  Target: "Conversion",
  Method: "create",
  Service: "HasOffers",
  Version: "2",
  NetworkId: "marktamerica",                    // PDF 原文即為 markt，勿改
  NetworkToken: process.env.MEIAN_NETWORK_TOKEN!, // 實際值見官方 PDF 附件1 第 2 頁
  "data[affiliate_id]": "12",                   // PDF 中為固定字面值
} as const;

interface MeianConversion {
  offerId: string;        // Offer_ID，美安提供
  advertiserId: string;   // Advertiser_ID，美安提供
  amount: number;         // 成立為正值(Order_Amount)；取消為負值(Refund_Amount)
  commission: number;     // 成立為正值；取消為負值
  orderId: string;        // ORDER_ID
  rid: string;            // 訂單原始記錄到的 RID
  clickId: string;        // 訂單原始記錄到的 Click_ID
  orderDate: string;      // YYYY-MM-DD
  isAdjustment?: boolean; // true = 失效/取消/退訂回拋
}

function buildMeianUrl(c: MeianConversion): string {
  const p = new URLSearchParams({
    ...MEIAN_FIXED,
    "data[offer_id]": c.offerId,
    "data[advertiser_id]": c.advertiserId,
    "data[sale_amount]": String(c.amount),
    "data[payout]": c.commission.toFixed(2),   // 小數點第二位
    "data[revenue]": c.commission.toFixed(2),  // payout 與 revenue 送同一個值
    "data[advertiser_info]": c.orderId,
    "data[affiliate_info1]": c.rid,
    "data[ad_id]": c.clickId,
    ...(c.isAdjustment ? { "data[is_adjustment]": "1" } : {}),
    "data[session_datetime]": c.orderDate,
  });
  return `${MEIAN_API}?${p.toString()}`;
}

async function postMeianConversion(c: MeianConversion): Promise<void> {
  const res = await fetch(buildMeianUrl(c));
  const raw = await res.text();

  // PDF 注意事項：「我們會回傳 JSON 格式的資料，請紀錄此資料以便日後除錯時可以使用」
  // PDF 未載明 response schema 與成功判準 → 全文留存 raw，不要只存 parsed 的部分欄位
  await meianLogRepo.save({
    orderId: c.orderId,
    isAdjustment: !!c.isAdjustment,
    requestUrl: redactToken(buildMeianUrl(c)), // 記 log 前遮蔽 NetworkToken
    httpStatus: res.status,
    rawResponse: raw,
    createdAt: new Date(),
  });
}
```

> `URLSearchParams` 會把 `data[offer_id]` 的中括號編碼成 `data%5Boffer_id%5D`。這在標準 HTTP 語意下與未編碼等價，多數伺服器（含 PHP 系的 HasOffers）都能正確解析。**但 PDF 給的是未編碼形式**——若串接初期遇到參數收不到，這是第一個要排除的變因（改送未編碼中括號）。

### 冪等性防護（PDF 明文要求）

PDF 兩處注意事項分別要求：**不得重複回傳訂單成立紀錄**、**取消金額累計不得超出原始成立金額**。建議：

```typescript
// 1. 訂單成立：以 orderId 做唯一鍵，只回拋一次
//    （PDF：勿因操作錯誤或消費者重新整理網頁造成重複回傳）
// 2. 訂單取消：累加該 orderId 已回拋的取消金額，不得超過原始成立金額
//    （PDF：可分次回傳，但總額不得超出）
// 3. 一個月時效：PDF 明文「取消訂單請以一個月內的訂單內為限，
//    超過一個月的訂單請勿再回傳取消資訊」→ 回拋前檢查 orderDate 是否在一個月內
```
