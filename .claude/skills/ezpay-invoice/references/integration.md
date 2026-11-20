# ezPay 電子發票 — NestJS / TypeScript 整合

對應 EZP_INVI_1.2.1 標準版。本檔為 ezPay 電子發票的 NestJS / TypeScript **參考實作**，
供對照演算法步驟用。**PHP 版本（本 repo 規劃的 WordPress 外掛技術棧）見 `php-client.md`**。

> 以下程式碼為依官方規格推導的 TypeScript 實作；AES 參數、CheckCode 規則、
> API 路徑、欄位名稱均忠實對齊 PDF。

## 1. AES-256-CBC 加密 / 解密

ezPay 規定 blocksize 為 **32**，須自行補 PKCS#7，加密時關閉自動 padding。

```typescript
import * as crypto from 'crypto';

const BLOCK_SIZE = 32; // ezPay 規定 blocksize = 32（非 AES 標準 16）

/** PKCS#7 padding（blocksize 32） */
function addPkcs7Padding(buf: Buffer, blockSize = BLOCK_SIZE): Buffer {
  const pad = blockSize - (buf.length % blockSize);
  return Buffer.concat([buf, Buffer.alloc(pad, pad)]);
}

function removePkcs7Padding(buf: Buffer): Buffer {
  const pad = buf[buf.length - 1];
  return buf.subarray(0, buf.length - pad);
}

/**
 * 加密 PostData_：query string → PKCS#7(32) → AES-256-CBC → 小寫 hex
 * hashKey 必須 32 bytes、hashIv 必須 16 bytes。
 */
export function encryptPostData(
  params: Record<string, string | number>,
  hashKey: string,
  hashIv: string,
): string {
  // 1. 組 query string（值需 url encode；與 PHP http_build_query 一致）
  const queryString = new URLSearchParams(
    Object.fromEntries(
      Object.entries(params).map(([k, v]) => [k, String(v)]),
    ),
  ).toString();

  // 2. 補 PKCS#7 padding（blocksize 32）
  const padded = addPkcs7Padding(Buffer.from(queryString, 'utf8'));

  // 3. AES-256-CBC，關閉自動 padding（padding 已自行補）
  const cipher = crypto.createCipheriv('aes-256-cbc', hashKey, hashIv);
  cipher.setAutoPadding(false);
  const encrypted = Buffer.concat([cipher.update(padded), cipher.final()]);

  return encrypted.toString('hex'); // 小寫 hex
}

/** 解密（驗證 / debug 用） */
export function decryptPostData(
  hex: string,
  hashKey: string,
  hashIv: string,
): string {
  const decipher = crypto.createDecipheriv('aes-256-cbc', hashKey, hashIv);
  decipher.setAutoPadding(false);
  const decrypted = Buffer.concat([
    decipher.update(Buffer.from(hex, 'hex')),
    decipher.final(),
  ]);
  return removePkcs7Padding(decrypted).toString('utf8');
}
```

## 2. CheckCode 回應驗證（SHA256）

只用在 `invoice_issue` / `invoice_touch_issue` / `invoice_search` 的回應；作廢與折讓類回應
沒有這 5 個欄位，**不要**拿來驗（原因見 concepts.md「哪些 API 能驗 CheckCode」）。

```typescript
import * as crypto from 'crypto';

const CHECK_FIELDS = ['InvoiceTransNo', 'MerchantID', 'MerchantOrderNo', 'RandomNum', 'TotalAmt'] as const;

/** 計算回應 CheckCode：取 5 欄位 → A~Z 排序 → 前後加 HashIV/HashKey → SHA256 大寫 */
export function computeCheckCode(
  result: Record<string, unknown>,
  hashKey: string,
  hashIv: string,
): string {
  const sorted = [...CHECK_FIELDS]
    .sort()
    // 缺值一律代空字串，避免出現 "RandomNum=undefined"
    .map((k) => `${k}=${result[k] == null ? '' : String(result[k])}`)
    .join('&');

  const raw = `HashIV=${hashIv}&${sorted}&HashKey=${hashKey}`;
  return crypto.createHash('sha256').update(raw, 'utf8').digest('hex').toUpperCase();
}

/** 用 timing-safe 比對驗證回應 CheckCode */
export function verifyCheckCode(
  result: Record<string, unknown> & { CheckCode?: string },
  hashKey: string,
  hashIv: string,
): boolean {
  const expected = Buffer.from(computeCheckCode(result, hashKey, hashIv));
  const actual = Buffer.from(result.CheckCode ?? '');
  return expected.length === actual.length && crypto.timingSafeEqual(expected, actual);
}
```

## 3. EzpayInvoiceService 骨架

重點：開立分成「組 PostData_ 並存檔」與「送出」兩步——重送時必須送**同一份** `PostData_`，
不能重新組參數（`TimeStamp` 會變，撞 `LIB10003`）。流程說明見 concepts.md「重送與冪等」。

```typescript
import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { encryptPostData, verifyCheckCode } from './ezpay-crypto';

interface EzpayConfig {
  baseUrl: string;   // https://cinv.ezpay.com.tw（測試）/ https://inv.ezpay.com.tw（正式）
  merchantId: string;
  hashKey: string;   // 32 bytes
  hashIv: string;    // 16 bytes
}

type EzpayResponse = { Status: string; Message: string; Result: string };

/** 暫時性錯誤：可用同一份 PostData_ 重送 */
export class EzpayTransientError extends Error {}

@Injectable()
export class EzpayInvoiceService {
  private readonly logger = new Logger(EzpayInvoiceService.name);

  constructor(private readonly config: ConfigService) {}

  private getConfig(): EzpayConfig {
    const isProd = this.config.get('EZPAY_ENV') === 'production';
    return {
      baseUrl: isProd ? 'https://inv.ezpay.com.tw' : 'https://cinv.ezpay.com.tw',
      merchantId: this.config.getOrThrow<string>('EZPAY_MERCHANT_ID'),
      hashKey: this.config.getOrThrow<string>('EZPAY_HASH_KEY'),
      hashIv: this.config.getOrThrow<string>('EZPAY_HASH_IV'),
    };
  }

  /** 送出已加密的 PostData_；網路錯誤、HTTP 非 2xx、非 JSON 回應都視為暫時性錯誤 */
  private async send(path: string, postData: string): Promise<EzpayResponse> {
    const cfg = this.getConfig();
    const body = new URLSearchParams({
      MerchantID_: cfg.merchantId, // 注意底線
      PostData_: postData,         // 注意底線
    });

    let text: string;
    try {
      const res = await fetch(`${cfg.baseUrl}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        signal: AbortSignal.timeout(30_000),
      });
      if (!res.ok) throw new EzpayTransientError(`HTTP ${res.status}`);
      text = await res.text();
    } catch (e) {
      if (e instanceof EzpayTransientError) throw e;
      throw new EzpayTransientError(`network: ${(e as Error).message}`);
    }

    let json: EzpayResponse;
    try {
      json = JSON.parse(text) as EzpayResponse;
    } catch {
      throw new EzpayTransientError('non-JSON response');
    }
    // 不要在 logger 印 hashKey / hashIv / postData 全文
    this.logger.debug(`ezPay ${path} -> ${json.Status}`);
    return json;
  }

  private post(path: string, bizParams: Record<string, string | number>) {
    const cfg = this.getConfig();
    return this.send(path, encryptPostData(bizParams, cfg.hashKey, cfg.hashIv));
  }

  /**
   * 第 1 步：組開立參數並加密。呼叫端必須在呼叫 sendIssue 之前，
   * 把回傳的 postData 連同 merchantOrderNo / totalAmt 存進資料庫（狀態「開立中」）。
   */
  buildIssuePostData(input: {
    merchantOrderNo: string;
    buyerName: string;
    buyerEmail?: string;
    category: 'B2B' | 'B2C';
    buyerUbn?: string;        // B2B 必填
    items: { name: string; count: number; unit: string; price: number; amt: number }[];
    amt: number;              // 銷售額（未稅）
    taxAmt: number;           // 稅額
    totalAmt: number;         // 含稅總額
    carrierType?: '0' | '1' | '2';
    carrierNum?: string;
    loveCode?: string;
    printFlag: 'Y' | 'N';
  }): string {
    const cfg = this.getConfig();
    const bizParams: Record<string, string | number> = {
      RespondType: 'JSON',
      Version: '1.5',                                  // 開立發票固定 1.5
      TimeStamp: Math.floor(Date.now() / 1000),        // 只在這裡取一次，之後重送沿用
      MerchantOrderNo: input.merchantOrderNo,
      Status: '1',                                     // 1=即時開立
      Category: input.category,
      BuyerName: input.buyerName,
      ...(input.buyerUbn ? { BuyerUBN: input.buyerUbn } : {}),
      ...(input.buyerEmail ? { BuyerEmail: input.buyerEmail } : {}),
      ...(input.carrierType ? { CarrierType: input.carrierType } : {}),
      // 官方要求 rawurlencode；外層 URLSearchParams 會再編一次，與 PHP 範例一致
      ...(input.carrierNum ? { CarrierNum: encodeURIComponent(input.carrierNum.trim()) } : {}),
      ...(input.loveCode ? { LoveCode: input.loveCode } : {}),
      PrintFlag: input.printFlag,
      TaxType: '1',
      TaxRate: '5',
      Amt: input.amt,
      TaxAmt: input.taxAmt,
      TotalAmt: input.totalAmt,
      ItemName: input.items.map((i) => i.name).join('|'),
      ItemCount: input.items.map((i) => i.count).join('|'),
      ItemUnit: input.items.map((i) => i.unit).join('|'),
      ItemPrice: input.items.map((i) => i.price).join('|'),
      ItemAmt: input.items.map((i) => i.amt).join('|'),
    };
    return encryptPostData(bizParams, cfg.hashKey, cfg.hashIv);
  }

  /**
   * 第 2 步：送出（首次與重送都呼叫這支，傳入存檔的同一份 postData）。
   * 暫時性錯誤會丟 EzpayTransientError，呼叫端保持「開立中」稍後重送。
   */
  async sendIssue(saved: { postData: string; merchantOrderNo: string; totalAmt: number }) {
    const res = await this.send('/Api/invoice_issue', saved.postData);

    if (res.Status === 'LIB10003') {
      // 多半是前一次其實成功了：用自訂編號 + 金額取回原發票
      return this.searchByOrderNo(saved.merchantOrderNo, saved.totalAmt);
    }
    if (res.Status !== 'SUCCESS') {
      // res.Status 為錯誤代碼，見 error-codes.md；NOR10001 / KEY10014 屬暫時性
      if (res.Status === 'NOR10001' || res.Status === 'KEY10014') {
        throw new EzpayTransientError(res.Status);
      }
      throw new Error(`ezPay invoice_issue failed: ${res.Status} ${res.Message}`);
    }

    const result = JSON.parse(res.Result);
    const cfg = this.getConfig();
    if (!verifyCheckCode(result, cfg.hashKey, cfg.hashIv)) {
      throw new Error('ezPay response CheckCode mismatch');
    }
    return {
      invoiceNumber: result.InvoiceNumber as string,   // 只有 Status=1 才有
      invoiceTransNo: result.InvoiceTransNo as string,
      randomNum: result.RandomNum as string,
    };
  }

  /** 作廢發票（回應不驗 CheckCode；LIB10005 已作廢過視為成功） */
  async invalidInvoice(invoiceNumber: string, reason: string): Promise<void> {
    const res = await this.post('/Api/invoice_invalid', {
      RespondType: 'JSON',
      Version: '1.0',                          // 作廢固定 1.0
      TimeStamp: Math.floor(Date.now() / 1000),
      InvoiceNumber: invoiceNumber,
      InvalidReason: reason,                   // 限中文 6 字 / 英文 20 字
    });
    if (res.Status === 'SUCCESS' || res.Status === 'LIB10005') return;
    throw new Error(`ezPay invoice_invalid failed: ${res.Status} ${res.Message}`);
  }

  /** 查詢發票（發票號碼 + 隨機碼） */
  searchInvoice(invoiceNumber: string, randomNum: string) {
    return this.search({ SearchType: '0', InvoiceNumber: invoiceNumber, RandomNum: randomNum });
  }

  /** 查詢發票（自訂編號 + 發票金額）——開立結果不明時用來取回原發票 */
  async searchByOrderNo(merchantOrderNo: string, totalAmt: number) {
    const result = await this.search({
      SearchType: '1',
      MerchantOrderNo: merchantOrderNo,
      TotalAmt: totalAmt,
    });
    return {
      invoiceNumber: result.InvoiceNumber as string,
      invoiceTransNo: result.InvoiceTransNo as string,
      randomNum: result.RandomNum as string,
    };
  }

  private async search(keys: Record<string, string | number>) {
    const res = await this.post('/Api/invoice_search', {
      RespondType: 'JSON',
      Version: '1.3',                          // 查詢固定 1.3
      TimeStamp: Math.floor(Date.now() / 1000),
      ...keys,
    });
    if (res.Status !== 'SUCCESS') {
      throw new Error(`ezPay invoice_search failed: ${res.Status} ${res.Message}`);
    }
    const result = JSON.parse(res.Result);
    const cfg = this.getConfig();
    if (!verifyCheckCode(result, cfg.hashKey, cfg.hashIv)) {
      throw new Error('ezPay response CheckCode mismatch');
    }
    return result; // 含 UploadStatus / InvoiceStatus / ItemDetail
  }
}
```

## 4. Version 欄位速查（不可填錯）

每支 API 的 `PostData_` 內 `Version` 欄位固定值：

| API | path | Version |
|-----|------|---------|
| 開立發票 | `/Api/invoice_issue` | `1.5` |
| 觸發開立發票 | `/Api/invoice_touch_issue` | `1.0` |
| 作廢發票 | `/Api/invoice_invalid` | `1.0` |
| 開立折讓 | `/Api/allowance_issue` | `1.3` |
| 觸發確認/取消折讓 | `/Api/allowance_touch_issue` | `1.0` |
| 作廢折讓 | `/Api/allowanceInvalid` | `1.0` |
| 查詢發票 | `/Api/invoice_search` | `1.3` |

## 5. 錯誤處理

- 回應 `Status !== 'SUCCESS'` 時，`Status` 即錯誤代碼（見 error-codes.md）。
- `KEY10002`（解密錯誤）→ 多為 AES 實作問題，**不要重試**，檢查 padding / 金鑰。
- 網路錯誤、HTTP 非 2xx、非 JSON 回應、`NOR10001` / `KEY10014` → 暫時性，
  用**存下來的同一份 `PostData_`** 重送（`sendIssue`），不要重新組參數。
- `LIB10003`（自訂編號重覆）→ 用 `searchByOrderNo` 取回原發票，不要換編號重開。

## 6. 安全與隱私

- **不要在 logger 印** `HashKey` / `HashIV` / `PostData_` 全文 / 買受人 PII
  （email / 統編 / 姓名 / 地址）。logger 只記 path、`Status`、`Message`、自訂編號。
- CheckCode 比對用 `crypto.timingSafeEqual`（已在 §2 實作），先比長度。
- `HashKey` / `HashIV` 從環境變數或設定資料表讀取，**禁止 hardcode**、禁止進版控。

## 7. 與訂單流程的銜接建議

- 發票屬「訂單後處理」：在付款確認後（金流通知確認已付款）才開立；
  開立失敗**不可**連帶中斷訂單狀態變更，記錄錯誤、交給排程重試或人工處理。
- `MerchantOrderNo` 建議用 `{訂單編號}_{序號}`：可回溯訂單，作廢後重開換序號不撞號
  （限英數與 `_`、≤ 20 字）。
- 開立成功後把 `invoiceNumber` / `invoiceTransNo` / `randomNum` 存在訂單上，
  供查詢 / 作廢 / 折讓使用。
- 退貨對應 `allowance_issue`（開折讓）；整張取消對應 `invoice_invalid`（作廢）。
  作廢有期限（奇數月 14 日前、前兩個月），跨期只能走折讓。
- 財政部上傳是非同步——每日 06:00 後排程用 `invoice_search` 查 `UploadStatus`，
  失敗（3）或逾時（4）通知管理員（見 concepts.md「財政部上傳失敗」）。
- ezPay 電子發票與 NewebPay 金流同屬藍新集團，但是**獨立服務、獨立 HashKey/HashIV**，
  不可共用 NewebPay 金流的金鑰（見 `newebpay-mpg` skill）。
