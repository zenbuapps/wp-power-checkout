# 可執行範例（TypeScript / NestJS）

> 官方文件**未提供任何程式碼範例**（唯一的官方 code 是 `MapSample.zip` 裡的一個 HTML form，見 §6）。以下為依規格書撰寫的實作範例，非官方產物。

---

## 1. 型別定義

```typescript
// tcat.types.ts

/** 共通回應外殼。DownloadOBT 成功時為 binary，不套用此型別。 */
export interface TcatResponse<T = never> {
  SrvTranId: string;
  IsOK: 'Y' | 'N';
  Message: string;
  Data: T | null;          // IsOK = 'N' 時為 null
}

export type Thermosphere = '0001' | '0002' | '0003';            // 常溫 / 冷藏 / 冷凍
export type Spec         = '0001' | '0002' | '0003' | '0004';   // 60 / 90 / 120 / 150 cm
export type DeliveryTime = '01' | '02' | '04';                  // 13時前 / 14-18時 / 不指定（無 03）
export type ReceiptLocation = '01' | '02';                      // 到宅 / 到所
export type YN = 'Y' | 'N';

/** v2.1.2 新增：ParsingAddress 郵號格式。★ ParsingMedicalLocation 只吃 01/02/03 */
export type PostType = '01' | '02' | '03' | '04' | '05';

/** 三種列印端點的 PrintOBTType 值域不同，型別分開才不會混用 */
export type PrintOBTTypeHome    = '01' | '02' | '03';           // 01 A4二模 / 02 A4三模 / 03 熱轉印
export type PrintOBTTypePicking = '01' | '02';                  // ★ 01 A4三模 / 02 A4二模（與宅配相反）
export type PrintOBTTypeB2S     = '01' | '02' | '03' | '04';    // v2.1.2 從 2 值擴為 4 值

export const PRODUCT_TYPE = {
  '0001': '一般食品',    '0002': '名特產/甜產', '0003': '酒/油/醋/醬', '0004': '穀物蔬果',
  '0005': '水產/肉品',   '0006': '3C',          '0007': '家電',        '0008': '服飾配件',
  '0009': '生活用品',    '0010': '美容彩妝',    '0011': '保健食品',    '0012': '醫療相關用品',
  '0013': '寵物用品飼料', '0014': '印刷品',      '0015': '其他',
} as const;
export type ProductTypeId = keyof typeof PRODUCT_TYPE;

export interface PrintOBTOrder {
  OBTNumber: string;          // PrintType='01' 時填 ''
  OrderId: string;            // 不可含空白
  Thermosphere: Thermosphere;
  Spec: Spec;
  ReceiptLocation: ReceiptLocation;
  ReceiptStationNo: string;   // 到所時填站所編號，否則 ''
  RecipientName: string; RecipientTel: string; RecipientMobile: string; RecipientAddress: string;
  SenderName: string; SenderTel: string; SenderMobile: string;
  SenderZipCode: string;      // ★ 六碼郵號
  SenderAddress: string;
  ShipmentDate: string;       // yyyyMMdd
  DeliveryDate: string;       // yyyyMMdd
  DeliveryTime: DeliveryTime;
  IsFreight: YN; IsCollection: YN;
  CollectionAmount: number;   // ★ Int，不加引號
  IsSwipe: YN; IsMobilePay?: YN; IsDeclare: YN;
  DeclareAmount: number;      // ★ Int
  ProductTypeId: ProductTypeId;
  ProductName: string;
  Memo?: string;
}

export interface PrintOBTData {
  PrintDateTime: string;      // yyyyMMddHHmmss
  Orders: Array<{ OBTNumber: string; OrderId: string }>;
  FileNo: string;             // ★ 與 Orders 同層，不在陣列內；★ v2.1.2：24 小時內須下載完
}

/** 逆物流訂單。v2.1.2 新增兩個選填郵碼欄位 */
export interface ReserveOrder {
  OBTNumber: string;          // ReserveEdit 時放最上層，不在 Order 內
  OrderId?: string;
  Thermosphere: Thermosphere;
  Spec: Spec;
  RecipientName: string; RecipientTel: string; RecipientMobile: string; RecipientAddress: string;
  SenderName: string; SenderTel: string; SenderMobile: string; SenderAddress: string;
  TakeDate: string;           // yyyyMMdd，D+1 ~ D+7
  DeliveryTime: DeliveryTime;
  IsFreight: YN;
  ProductName?: string;
  Memo?: string;
  RecipientZipCode?: string;  // ★ v2.1.2 新增，六碼含理貨碼，選填
  SenderZipCode?: string;     // ★ v2.1.2 新增，六碼含理貨碼，選填
}

/** v2.1.2 新增端點 ParsingMedicalLocation 的回傳 */
export interface MedicalLocationData {
  Addresses: Array<{
    Search: string;
    PostNumber: string;       // 查不到時：單筆 IsOK='N'、多筆為空字串
    PostCodeName: string;     // ★ 據點名稱，例 '林口-4'。ParsingAddress 沒有這欄
  }>;
}
```

---

## 2. Client（含 binary 分支與錯誤處理）

```typescript
// tcat.client.ts
import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

/** v2.1.2 錯誤碼分類（完整表見 api-reference.md §16）*/
const RETRYABLE = new Set(['E010', 'E067', 'E072', 'E078', 'E081', 'E084']);
const THROTTLED = new Set(['E085', 'E086', 'E087']);

export class TcatApiError extends Error {
  /** v2.1.2 起 Message 可能帶 E001–E091；官方未定義回傳形式，故用寬鬆解析 */
  readonly code?: string;
  /** 限流訊息中的「請稍等 XXX 分鐘」 */
  readonly retryAfterMinutes?: number;

  constructor(readonly service: string, readonly srvTranId: string, message: string) {
    super(`[T-cat ${service}] ${message}`);
    this.name = 'TcatApiError';
    this.code = message.match(/\bE0\d{2}\b/)?.[0];
    const mins = message.match(/請稍等\s*(\d+)\s*分鐘/);
    if (mins) this.retryAfterMinutes = Number(mins[1]);
  }

  /** 暫時性錯誤 → 指數退避重試 */
  get isRetryable(): boolean {
    return this.code ? RETRYABLE.has(this.code) : /暫時無法|請重試|系統發生錯誤/.test(this.message);
  }

  /** 限流 → 依 retryAfterMinutes 等待；E086 當日不再重試 */
  get isThrottled(): boolean {
    return this.code ? THROTTLED.has(this.code) : /太密集|已達上限/.test(this.message);
  }

  /** 當日額度用盡（每日 3000 次） */
  get isDailyQuotaExhausted(): boolean {
    return this.code === 'E086' || /請求次數已達上限/.test(this.message);
  }
}

@Injectable()
export class TcatClient {
  private readonly logger = new Logger(TcatClient.name);
  private readonly baseUrl: string;
  private readonly auth: { CustomerId: string; CustomerToken: string };

  constructor(config: ConfigService) {
    // 正式-1 https://api.suda.com.tw/api/Egs
    // 正式-2 https://api.suda.net.tw:9443/api/Egs（v2.1.2 新增，官方未說明與正式-1 的關係，別隨機分流）
    // 測試   https://egs.suda.com.tw:8443/api/Egs
    this.baseUrl = config.getOrThrow<string>('TCAT_BASE_URL');
    this.auth = {
      CustomerId: config.getOrThrow<string>('TCAT_CUSTOMER_ID'),      // 10 或 12 碼
      CustomerToken: config.getOrThrow<string>('TCAT_CUSTOMER_TOKEN'), // 正式/測試各一組
    };
  }

  /** 一般 JSON 端點。IsOK !== 'Y' 一律拋錯。 */
  async call<T>(service: string, payload: Record<string, unknown>): Promise<T> {
    const res = await fetch(`${this.baseUrl}/${service}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...this.auth, ...payload }),
      signal: AbortSignal.timeout(120_000),   // ★ 官方要求逾時 ≥ 120 秒
    });

    const body = (await res.json()) as TcatResponse<T>;
    if (body.IsOK !== 'Y' || body.Data === null) {
      // v2.1.2 起 Message 對應 E001–E091，但回應外殼無獨立錯誤碼欄位
      // → TcatApiError 內以 regex 寬鬆解析，兩種形式都吃
      throw new TcatApiError(service, body.SrvTranId, body.Message);
    }
    return body.Data;
  }

  /**
   * DownloadOBT 專用：成功 = HTTP 200 + binary，失敗 = HTTP 400 + JSON。
   * ★ 這是唯一用 HTTP 狀態碼區分成敗的端點，不可無腦 .json()。
   */
  async downloadObt(fileNo: string, obtNumbers?: string[]): Promise<Buffer> {
    const payload: Record<string, unknown> = { ...this.auth, FileNo: fileNo };
    if (obtNumbers?.length) payload.Orders = obtNumbers.map(n => ({ OBTNumber: n }));

    const res = await fetch(`${this.baseUrl}/DownloadOBT`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(120_000),
    });

    if (!res.ok) {
      const err = (await res.json()) as TcatResponse;
      // E070 超過列印截止時間 = FileNo 已逾 24 小時失效，重試無用，必須重新建單
      throw new TcatApiError('DownloadOBT', err.SrvTranId, err.Message);
    }
    return Buffer.from(await res.arrayBuffer());
  }
}
```

### 2.1 `FileNo` 24 小時期限（v2.1.2）

```typescript
const FILE_NO_TTL_MS = 24 * 60 * 60 * 1000;   // 官方：自 PrintOBT* 成功時點起算

/** 落庫時記時戳，下載前先檢查，別浪費一次 API 呼叫 */
function isFileNoExpired(printedAt: Date, now = new Date()): boolean {
  return now.getTime() - printedAt.getTime() >= FILE_NO_TTL_MS;
}

async function downloadWithGuard(rec: { fileNo: string; printedAt: Date }) {
  if (isFileNoExpired(rec.printedAt)) {
    // ★ 逾期不能「重新下載」，只能重新建單 → 會配到新的託運單號，舊單號需作廢對帳
    throw new Error('FileNo 已逾 24 小時失效，請重新呼叫 PrintOBT 建單');
  }
  return client.downloadObt(rec.fileNo);
}
```

---

## 3. 查郵號 → 建單 → 取 PDF（完整流程）

```typescript
// tcat-shipping.service.ts
import { Injectable } from '@nestjs/common';
import { TcatClient, PrintOBTData, PrintOBTOrder } from './tcat.client';

@Injectable()
export class TcatShippingService {
  constructor(private readonly tcat: TcatClient) {}

  /**
   * ★ 六碼郵號截取：ParsingAddress 回 '71-802-24-B' → SenderZipCode 要 '80224B'
   * 直接把 PostNumber 塞進 SenderZipCode 是最常見的整合錯誤。
   */
  async resolveZipCode(address: string): Promise<string> {
    const data = await this.tcat.call<{ Addresses: Array<{ Search: string; PostNumber: string }> }>(
      'ParsingAddress',
      { Addresses: [{ Search: address }] },   // 上限 100 筆
    );
    return data.Addresses[0].PostNumber.replace(/-/g, '').slice(-6);
  }

  /** 建立宅配託運單並取回 PDF。orders 上限 100 筆。 */
  async createHomeDelivery(orders: PrintOBTOrder[]): Promise<{
    pdf: Buffer;
    obtNumbers: Array<{ OBTNumber: string; OrderId: string }>;
    fileNo: string;
  }> {
    const data = await this.tcat.call<PrintOBTData>('PrintOBT', {
      PrintType: '01',      // 速達標準版型，由系統配號
      PrintOBTType: '02',   // 01=A4二模 / 02=A4三模 / 03=熱轉印
      Orders: orders,
    });

    const pdf = await this.tcat.downloadObt(data.FileNo);
    return { pdf, obtNumbers: data.Orders, fileNo: data.FileNo };
  }

  /** 補印：只抽 FileNo 批次內的指定單號 */
  reprint(fileNo: string, obtNumbers: string[]): Promise<Buffer> {
    return this.tcat.downloadObt(fileNo, obtNumbers);
  }
}
```

---

## 4. 送出前驗證（把規格書的商業規則變成 code）

v2.1.2 雖然有了 91 組錯誤碼，**在本地先擋掉可預測的錯誤**仍能大幅降低除錯成本與無謂的 API 呼叫（限流額度很珍貴）。下方每條規則後面標註對應的官方錯誤碼。

```typescript
// tcat.validation.ts

/** 商品名稱禁用字串（19 個，子字串比對） */
const FORBIDDEN_PRODUCT_WORDS = [
  '文件', '標單', '明信片', '郵簡', '信函', '資料', '發票', '訴訟文件', '公文',
  '身分證明文件', '報名表', '申請書', '帳單', '訂單', '報價單', '繳費單',
  '通知單', '保險單', '文書',
] as const;

/** 姓名允許符號（附錄二，29 個） */
const NAME_SYMBOLS = ' `~!@#$%^&*()_+=-[]{};:\'",.?/';

const NAME_RE = new RegExp(
  `^[\\u4e00-\\u9fffA-Za-z0-9${NAME_SYMBOLS.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}]+$`,
);

export function validatePrintOBTOrder(o: PrintOBTOrder): string[] {
  const errs: string[] = [];

  if (/\s/.test(o.OrderId)) errs.push('OrderId 不可含空白字元');

  const hit = FORBIDDEN_PRODUCT_WORDS.find(w => o.ProductName.includes(w));
  if (hit) errs.push(`ProductName 含禁用字串「${hit}」`);

  if (!NAME_RE.test(o.RecipientName)) errs.push('RecipientName 含不允許的字元');
  if (!NAME_RE.test(o.SenderName)) errs.push('SenderName 含不允許的字元');

  // 電話 / 手機擇一必填
  if (!o.RecipientTel && !o.RecipientMobile) errs.push('收件人電話、手機擇一必填');
  if (!o.SenderTel && !o.SenderMobile) errs.push('寄件人電話、手機擇一必填');
  if (o.RecipientMobile && !/^09\d{8}$/.test(o.RecipientMobile)) errs.push('RecipientMobile 須為 09 開頭手機格式');
  if (o.SenderMobile && !/^09\d{8}$/.test(o.SenderMobile)) errs.push('SenderMobile 須為 09 開頭手機格式');
  // v2.1.2：兩者統一為 數字 + 「-」「#」「,」，長度上限 30（E001）
  const TEL_RE = /^[\d\-#,]+$/;
  if (o.RecipientTel && !TEL_RE.test(o.RecipientTel)) errs.push('RecipientTel 僅允許數字及「-」「#」「,」');
  if (o.SenderTel && !TEL_RE.test(o.SenderTel)) errs.push('SenderTel 僅允許數字及「-」「#」「,」');
  if (o.RecipientTel && o.RecipientTel.length > 30) errs.push('RecipientTel 超過 30 字元');
  if (o.SenderTel && o.SenderTel.length > 30) errs.push('SenderTel 超過 30 字元');

  // 到付 / 代收互斥
  if (o.IsFreight === 'Y' && o.IsCollection === 'Y') errs.push('到付與代收貨款不可同時為 Y');
  if (o.IsFreight === 'Y' && o.CollectionAmount > 0) errs.push('到付時代收金額必須為 0');

  // 代收金額（宅配上限 10 萬；快速到店為 2 萬，另行驗證）
  if (o.IsCollection === 'Y' && !(o.CollectionAmount > 0 && o.CollectionAmount <= 100_000))
    errs.push('代收金額須 0 < x ≤ 100000');
  if (o.IsCollection === 'N' && o.CollectionAmount !== 0) errs.push('未代收時代收金額須為 0');

  // 報值：代收 ≤ 2 萬不提供報值；> 2 萬時代收金額 = 報值金額
  if (o.IsDeclare === 'Y') {
    if (o.CollectionAmount <= 20_000) errs.push('代收金額 ≤ 20000 不提供報值服務');
    if (!(o.DeclareAmount > 20_000 && o.DeclareAmount <= 100_000)) errs.push('報值金額須 20000 < x ≤ 100000');
    if (o.CollectionAmount > 20_000 && o.DeclareAmount !== o.CollectionAmount)
      errs.push('代收金額 > 20000 時，代收金額須等於報值金額');
  } else if (o.DeclareAmount !== 0) {
    errs.push('未報值時報值金額須為 0');
  }

  // 到所 / 到宅
  if (o.ReceiptLocation === '02') {
    if (!o.ReceiptStationNo) errs.push('到所時須填 ReceiptStationNo');
    if (o.RecipientAddress) errs.push('到所時收件人地址須留空');
  } else {
    if (o.ReceiptStationNo) errs.push('非到所時 ReceiptStationNo 須留空');
    if (!o.RecipientAddress) errs.push('到宅時收件人地址必填');
  }

  // v2.1.2 欄位長度已收緊為 String(6)（E001「SenderZipCode 欄位長度最少為6」）
  if (o.SenderZipCode.length !== 6)
    errs.push(`SenderZipCode 應為六碼郵號（目前 ${o.SenderZipCode.length} 碼，是否忘了截取後六碼？）`);

  // ── v2.1.2 新增：DeliveryDate 四條硬規則（E088 / E089 / E090 / E091）
  errs.push(...validateDeliveryDate(o.ShipmentDate, o.DeliveryDate));

  return errs;
}

/** yyyyMMdd → Date（本地時區 00:00） */
const parseYmd = (s: string) => new Date(+s.slice(0, 4), +s.slice(4, 6) - 1, +s.slice(6, 8));
const DAY_MS = 86_400_000;

/** v2.1.2 §2.2 DeliveryDate 規則。回傳錯誤訊息陣列（空 = 通過）。 */
export function validateDeliveryDate(shipmentDate: string, deliveryDate: string, today = new Date()): string[] {
  const errs: string[] = [];
  if (!/^\d{8}$/.test(deliveryDate)) return ['DeliveryDate 須為 yyyyMMdd（E001）'];

  const ship = parseYmd(shipmentDate);
  const deliver = parseYmd(deliveryDate);
  const midnight = new Date(today.getFullYear(), today.getMonth(), today.getDate());

  if (deliver < midnight) errs.push('希望配達日期不可為過去日期（E088）');
  if (deliver < ship) errs.push('希望配達日期不可小於出貨日期（E089）');
  if (deliver.getTime() === ship.getTime()) errs.push('希望配達日期不可與出貨日期同一天（E090）');
  if (deliver.getTime() - ship.getTime() > 7 * DAY_MS) errs.push('希望配達日期不可超過出貨日期 7 天（E091）');

  return errs;
}
```

> **日期欄位另需檢查國定假日與週日**（`ShipmentDate` / `DeliveryDate` 官方明載「國定假日、周日不配送」，違反時回 `E032`）。速達未提供假日 API，需自備行事曆資料。
> **撿貨明細的 `Detail.Body[].ProductName` 超過 32 字會被靜默截斷、不回報錯誤**（v2.1.2），送出前務必自行驗長度，否則印出來的單據名稱會被切掉而你不會知道。

---

## 5. 貨態查詢與正規化

```typescript
// tcat-tracking.service.ts

interface ObtStatus {
  OBTNumber: string; OrderId: string;
  StationName: string; CreateDateTime: string; CustomerId: string;
  StatusId: string; StatusName: string;
  StatusList: Array<{ StatusId: string; StatusName: string; CreateDateTime: string; StationName: string }>;
}

type ShippingState =
  | 'PENDING' | 'IN_TRANSIT' | 'HELD' | 'ATTEMPT_FAILED' | 'AT_STORE'
  | 'DELIVERED' | 'RETURNING' | 'RETURNED' | 'EXCEPTION' | 'UNKNOWN';

/** 二代代碼（OBTStatus API）→ 內部狀態。完整表見 cargo-status.md §6 */
const V2_STATE: Record<string, ShippingState> = {
  '111': 'IN_TRANSIT', '151': 'IN_TRANSIT', '152': 'IN_TRANSIT', '153': 'IN_TRANSIT',
  '154': 'IN_TRANSIT', '155': 'IN_TRANSIT', '202': 'IN_TRANSIT', '204': 'IN_TRANSIT',
  '205': 'IN_TRANSIT', '181': 'IN_TRANSIT', '182': 'IN_TRANSIT',
  '159': 'HELD', '168': 'HELD', '302': 'HELD',
  '211': 'ATTEMPT_FAILED', '212': 'ATTEMPT_FAILED', '213': 'ATTEMPT_FAILED',
  '214': 'ATTEMPT_FAILED', '215': 'ATTEMPT_FAILED', '216': 'ATTEMPT_FAILED',
  '208': 'AT_STORE', '209': 'AT_STORE', '308': 'AT_STORE', '420': 'AT_STORE',
  '301': 'DELIVERED',
  '161': 'RETURNING', '303': 'RETURNING', '309': 'RETURNING',
  '305': 'RETURNED',
  '183': 'EXCEPTION', '184': 'EXCEPTION', '185': 'EXCEPTION', '186': 'EXCEPTION',
  '241': 'EXCEPTION', '242': 'EXCEPTION', '243': 'EXCEPTION', '244': 'EXCEPTION',
  '245': 'EXCEPTION', '246': 'EXCEPTION',
};

@Injectable()
export class TcatTrackingService {
  private readonly logger = new Logger(TcatTrackingService.name);
  constructor(
    private readonly tcat: TcatClient,
    private readonly cache: ObtStatusCache,   // 見 §5.1
    private readonly quota: DailyQuota,       // 見 §5.1
  ) {}

  /**
   * ★ 一次上限 10 筆；查無貨態時 IsOK='N'（不是錯誤）；只回有貨態的單號。
   * ★★ v2.1.2 限流：每日 3,000 次 / 併發 3 / 同單號 2 小時 1 次。
   *    下方 query() 已含三層防護——快取過濾、序列化分批、每日額度熔斷。
   */
  async query(obtNumbers: string[]): Promise<Map<string, ObtStatus | null>> {
    const result = new Map<string, ObtStatus | null>(obtNumbers.map(n => [n, null]));

    // ① 先吃快取：同一單號 2 小時內不得重查（E087）
    const toFetch: string[] = [];
    for (const n of obtNumbers) {
      const cached = await this.cache.get(n);        // 見下方 §5.1
      if (cached) result.set(n, cached);
      else toFetch.push(n);
    }
    if (!toFetch.length) return result;

    // ② 每日額度熔斷（E086）——接近上限就停，別把額度耗在背景輪詢
    if (!(await this.quota.tryConsume(Math.ceil(toFetch.length / 10)))) {
      this.logger.warn('OBTStatus 每日額度不足，本次略過查詢（改由 FTP .sod 回檔補齊）');
      return result;
    }

    // ③ 逐批序列化（併發上限 3，這裡取最保守的 1；★ 不要用 Promise.all 展開）
    for (let i = 0; i < toFetch.length; i += 10) {
      const chunk = toFetch.slice(i, i + 10);        // ★ 10 筆算 1 次呼叫，比逐筆查省 10 倍額度
      try {
        const data = await this.tcat.call<{ OBTs: ObtStatus[] }>('OBTStatus', { OBTNumbers: chunk });
        for (const obt of data.OBTs) {
          result.set(obt.OBTNumber, obt);            // ★ 自行對位
          await this.cache.set(obt.OBTNumber, obt);  // 寫回快取，滿足 2 小時冷卻
        }
      } catch (e) {
        const err = e as TcatApiError;
        if (err.isDailyQuotaExhausted) {             // E086：當日不再重試
          this.logger.error('OBTStatus 每日 3000 次上限已達，停止今日所有查詢');
          this.quota.markExhausted();
          break;
        }
        if (err.isThrottled) {                       // E084 / E085 / E087：等待後重試
          this.logger.warn(`被限流（${err.code}），建議 ${err.retryAfterMinutes ?? 120} 分鐘後重試`);
          break;
        }
        // 整批查無貨態 → IsOK='N' → client 拋錯。這是正常情形，不是系統故障。
        this.logger.debug(`OBTStatus 無貨態或失敗: ${chunk.join(',')} — ${err.message}`);
      }
    }
    return result;
  }

  /** ★ 未知代碼必須 fallback，絕不可 throw（代碼表已證實非窮舉）。 */
  toShippingState(statusId: string): ShippingState {
    const state = V2_STATE[statusId.trim()];
    if (!state) this.logger.warn(`未知的黑貓貨態代碼: ${statusId}`);
    return state ?? 'UNKNOWN';
  }
}
```

### 5.1 快取與每日額度（v2.1.2 限流的最小實作）

```typescript
// obt-status-cache.ts —— 滿足「同一託運單號每 2 小時僅能查詢一次」
const COOLDOWN_MS = 2 * 60 * 60 * 1000;

@Injectable()
export class ObtStatusCache {
  constructor(private readonly redis: Redis) {}

  async get(obtNumber: string): Promise<ObtStatus | null> {
    const raw = await this.redis.get(`tcat:obt:${obtNumber}`);
    return raw ? (JSON.parse(raw) as ObtStatus) : null;
  }

  /** TTL 設 2 小時 = 冷卻期。期間內任何查詢都讀快取，不打 API。 */
  async set(obtNumber: string, status: ObtStatus): Promise<void> {
    await this.redis.set(`tcat:obt:${obtNumber}`, JSON.stringify(status), 'PX', COOLDOWN_MS);
  }
}

// daily-quota.ts —— 滿足「每個契約客戶每日最多 3,000 次」
const DAILY_LIMIT = 3_000;
const SAFETY_MARGIN = 100;    // 留給使用者主動觸發的查詢

@Injectable()
export class DailyQuota {
  constructor(private readonly redis: Redis) {}

  private key(): string {
    // ★ 不要用 Date.now() 以外的時區來源；黑貓以 GMT+8 為準
    return `tcat:quota:${new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Taipei' })}`;
  }

  /** 消耗 n 次額度；不足回 false（呼叫端應改走 FTP 批次回檔） */
  async tryConsume(n: number): Promise<boolean> {
    const used = await this.redis.incrby(this.key(), n);
    if (used === n) await this.redis.expire(this.key(), 26 * 3600);
    return used <= DAILY_LIMIT - SAFETY_MARGIN;
  }

  /** 收到 E086 時直接把當日額度打滿，停掉後續所有背景查詢 */
  async markExhausted(): Promise<void> {
    await this.redis.set(this.key(), String(DAILY_LIMIT), 'EX', 26 * 3600);
  }
}
```

**額度規劃**（假設每日 1,000 張單、每張追蹤 5 天）：

| 策略 | 每日呼叫數 | 是否可行 |
|---|---|---|
| 逐筆查、每小時輪詢 | 5,000 單 × 24 = 120,000 | ❌ 爆表 40 倍 |
| 逐筆查、每 2 小時輪詢（貼齊冷卻）| 5,000 × 12 = 60,000 | ❌ 爆表 20 倍 |
| **批次 10 筆、每 2 小時輪詢** | 500 × 12 = 6,000 | ❌ 仍超過 |
| **批次 10 筆 + 只查未結案單 + 每 4 小時** | 約 750 | ✅ |
| **FTP `.sod` 批次為主、`OBTStatus` 只供使用者即時查詢** | < 200 | ✅ **建議** |

> **結論：`OBTStatus` 不適合當全量輪詢的資料來源。** 大量對帳走 FTP `.sod`（每日 05:00 / 18:00），`OBTStatus` 留給「使用者點了查詢按鈕」這種即時場景。

---

## 6. 7-11 電子地圖導轉

### 6.1 官方範例原文（`MapSample.zip` → `MapSample.html`，唯一的官方程式碼）

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title></title>
</head>
<body>
    <form id="form1" method="post" action="http://egs.suda.com.tw:8080/Map.aspx">
            <input name="CustomerID" value="9017292601" />
            <input name="ReturnUrl" value="https://tcloud-daxi.crownonly.com/XXX接收門店網頁" />
            <input name="TempVar" value="這是測試資料" />
            <input name="StoreCategory" value="13" />
            <button type="submit">開啟地圖</button>
    </form>
</body>
</html>
```

### 6.2 實務版（自動送出 + 一次性 token）

```typescript
// 後端：產生一次性 TempVar（★ 上限 20 字元，UUID 會被截斷）
const tempVar = randomBytes(9).toString('base64url');      // 12 字元
await cache.set(`tcat:map:${tempVar}`, orderId, { ttl: 900 });  // 15 分鐘

// ★ StoreCategory 與 Thermosphere 的對照是「反的」，務必查表不要靠直覺
const STORE_CATEGORY = { '0001': '13', '0002': '15', '0003': '14' } as const;
//                       常溫→13      冷藏→15       冷凍→14
```

```html
<!-- 前端：自動送出的導轉頁 -->
<form id="tcatMap" method="post" action="https://appservice.ezcat.com.tw/Map.aspx">
  <input type="hidden" name="CustomerID"    value="{{customerId}}" />
  <input type="hidden" name="ReturnUrl"     value="{{returnUrl}}" />
  <input type="hidden" name="TempVar"       value="{{tempVar}}" />
  <input type="hidden" name="StoreCategory" value="{{storeCategory}}" />
</form>
<script>document.getElementById('tcatMap').submit();</script>
```

```typescript
// 後端：接收回傳（★ 是 POST，不是 GET；需豁免 CSRF 並改用 TempVar 驗證）
@Post('tcat/map-callback')
async mapCallback(@Body() body: {
  TempVar?: string; storeid: string; storename: string;
  storeaddress: string; outside: '0' | '1'; ship: string;   // ★ 回傳欄位是小寫
}) {
  const orderId = await this.cache.get(`tcat:map:${body.TempVar}`);
  if (!orderId) throw new BadRequestException('地圖工作階段已過期');
  await this.cache.del(`tcat:map:${body.TempVar}`);          // 一次性

  // ★ 官方明載：傳出的 storeid 可能與傳入不同，一律以回傳值為準
  await this.orders.setStore(orderId, {
    storeId: body.storeid, storeName: body.storename, storeAddress: body.storeaddress,
    isOutlyingIsland: body.outside === '1',
    shipDays: [...body.ship].map((c, i) => (c === '1' ? i : -1)).filter(d => d >= 0), // 0=日…6=六
  });
}
```

---

## 7. 回檔檔案 parser（`.sod` / `.nod` / `.nom` / `.adj` / `.ret`）

```typescript
// tcat-callback-parser.ts
import iconv from 'iconv-lite';   // ★ Node 內建不支援 Big5，必須用 iconv-lite

/**
 * 通用 pipe 檔切行。
 * ★ 分隔符是半形 |（文件寫全形 │ 是排版用字）
 * ★ 行尾有 trailing |，split 後要丟掉尾端空元素
 * ★ 最後一行可能沒有換行符
 */
export function parsePipeFile(buf: Buffer, encoding: 'big5' | 'utf8' = 'big5'): string[][] {
  return iconv.decode(buf, encoding)
    .split(/\r?\n/)
    .filter(line => line.trim() !== '')
    .map(line => {
      const cols = line.split('|');
      if (cols.at(-1) === '') cols.pop();
      return cols;
    });
}

/** .sod 貨態回檔 — ★ 欄位數是 7 或 8（規格欄預設不回覆時整欄缺席） */
export interface SodRow {
  obtNumber: string; orderId: string; stationName: string;
  statusDateTime: string;   // 可能是 14 碼或 8 碼
  customerId: string;
  statusId: string;         // ★ 一代代碼，保留前導零，字串處理
  statusName: string;
  spec?: string;            // 01/02/03/04 → 60/90/120/150 cm；常缺席
}

export function parseSod(buf: Buffer, encoding: 'big5' | 'utf8' = 'big5'): SodRow[] {
  return parsePipeFile(buf, encoding).map(c => ({
    obtNumber: c[0].trim(),
    orderId: c[1].trim(),          // ★ 可能是 ' '（空白）而非 ''
    stationName: c[2].trim(),      // ★ 可能含全形空白 U+3000
    statusDateTime: c[3].trim(),
    customerId: c[4].trim(),
    statusId: c[5].trim(),         // ★ '216　' 帶全形空白，必須 trim
    statusName: c[6].trim(),
    spec: c[7]?.trim() || undefined,
  }));
}

/** 客樂得三檔欄位位移不同，★ 必須按副檔名分派，不可共用 */
export interface KeledeRow {
  date: string; obtNumber: string; orderId: string;
  collectionAmount: number; fee: number; freight: number;
  originalObtNumber?: string;   // 僅 .ret
}

export function parseKelede(buf: Buffer, kind: 'nom' | 'adj' | 'ret'): KeledeRow[] {
  return parsePipeFile(buf).map(c =>
    kind === 'ret'
      // .ret 有 7 欄：日期 | 原始單號 | 訂單編號 | 託運單號 | 代收 | 手續費 | 運費
      ? { date: c[0], originalObtNumber: c[1], orderId: c[2], obtNumber: c[3],
          collectionAmount: +c[4], fee: +c[5], freight: +c[6] }
      // .nom / .adj 有 6 欄：日期 | 託運單號 | 訂單編號 | 代收 | 手續費 | 運費
      : { date: c[0], obtNumber: c[1], orderId: c[2],
          collectionAmount: +c[3], fee: +c[4], freight: +c[5] },
  );
}

/**
 * ★ 對帳：匯款金額 = NOM + ADJ。
 * RET 不可相加 —— 官方明載「退貨單號會重覆出現在 NOM 與 RET 檔裡」。
 */
export function calcRemittance(nom: KeledeRow[], adj: KeledeRow[]): number {
  const sum = (rows: KeledeRow[]) => rows.reduce((t, r) => t + r.collectionAmount - r.fee, 0);
  return sum(nom) + sum(adj);
}
```

---

## 8. 逆物流：先查再改（避免對已集貨單操作）

```typescript
/** ★ Status='99'（已送出集貨）不可修改 / 刪除，先查再動作。 */
async function safeUpdateReserve(tcat: TcatClient, obtNumber: string, patch: object) {
  const { Order } = await tcat.call<{ Order: { Status: '01' | '99' } }>(
    'ReserveQuery', { OBTNumber: obtNumber },
  );
  if (Order.Status === '99') throw new Error('已送出集貨，不可修改或刪除');

  // ★ ReserveEdit 的 OBTNumber 在最上層，不在 Order 內
  return tcat.call('ReserveEdit', { OBTNumber: obtNumber, Order: patch });
}
```

---

## 9. 常見陷阱速查

| # | 陷阱 | 正確做法 |
|---|---|---|
| 1 | 把 `PostNumber` 直接當 `SenderZipCode` | 去連字號後**取末六碼** |
| 2 | 只看 HTTP 200 就當成功 | **必須判 `IsOK === 'Y'`** |
| 3 | `DownloadOBT` 無腦 `.json()` | **200=binary / 400=JSON**，先看 status code |
| 4 | 金額欄位序列化成字串 | `CollectionAmount` 等為 **`Int`**，不加引號 |
| 5 | `FileNo` 去 `Orders[]` 裡撈 | `FileNo` 是 **`Data` 的直接子欄位** |
| 6 | `OBTStatus` 一次送超過 10 筆 | **分批，每批 ≤ 10** |
| 7 | `OBTStatus` 查無貨態當成系統錯誤 | 回 `IsOK='N'` 是**正常情形**（尚未集貨）|
| 8 | 假設 `OBTs` 筆數 = 送出筆數 | **只回有貨態的**，要自行對位 |
| 9 | 一代 / 二代貨態代碼混用 | 兩套不同；有 11 個代碼**數值巧合相同**，測試可能矇混過關 |
| 10 | 未知貨態代碼直接 throw | 代碼表**非窮舉**（`00000` 為證），必須 fallback |
| 11 | `DeliveryTime` 填 `03` | 只有 **`01` / `02` / `04`**，無 `03` |
| 12 | `PrintOBTByPickingList` 的 `01`/`02` 當成二模/三模 | **撿貨單是 `01`=三模、`02`=二模**（與宅配相反）|
| 13 | 快速到店代收填到 10 萬 | **B2S 上限 2 萬** |
| 14 | 快速到店選 150cm | **B2S 無 `0004`**，上限 120cm |
| 15 | `StoreCategory` 冷藏冷凍搞反 | **`14`=冷凍、`15`=冷藏**（與 `Thermosphere` 順序相反）|
| 16 | `TempVar` 塞 UUID | **上限 20 字元**，會被截斷 |
| 17 | 回檔用全形 `│` 切欄 | 實際是**半形 `|`** |
| 18 | `.ret` 與 `.nom` 共用 parser | **`.ret` 有 7 欄且第 2/4 欄都是單號** |
| 19 | 對帳把 NOM + RET 相加 | **匯款 = NOM + ADJ**，退貨單號在兩檔重複出現 |
| 20 | Node 直接讀 Big5 回檔 | 內建不支援，需 **`iconv-lite`** |
| 21 | `.eod` 重用檔名序號 | **後蓋前且不備份**，序號必須唯一 |
| 22 | `.eod` 用 2 碼時段代碼 | EOD 是 **1 碼**（`1`/`2`/`4`），API 才是 2 碼 |
| 23 | 逆物流用 `Orders` 陣列 | 逆物流是 **`Order` 單一物件** |
| 24 | `ReserveEdit` 把 `OBTNumber` 放進 `Order` | **`OBTNumber` 在最上層** |
| 25 | 沿用 v1.9.3 的 Azure 測試網址 | 已改為 **`https://egs.suda.com.tw:8443`** |

**v2.1.2 新增的陷阱**

| # | 陷阱 | 正確做法 |
|---|---|---|
| 26 | 用 `Promise.all` 併發查 `OBTStatus` | **併發上限 3**，超過直接被拒（`E084`）。用佇列或序列化 |
| 27 | 每小時輪詢同一批單號 | **同單號 2 小時內只能查 1 次**（`E087`）。快取 TTL 設 2 小時 |
| 28 | 背景任務把每日額度吃光 | **每日 3,000 次**（`E086`）。留安全水位給使用者即時查詢，大量對帳走 FTP `.sod` |
| 29 | 建單後隔天才下載 PDF | **`FileNo` 24 小時失效**（`E070`），逾期只能重新建單配新單號 |
| 30 | 同一收貨點一天叫車兩次 | **`Call` 每個收貨點每日 1 次**，且**無法指定收貨時間**，UI 別給時段選項 |
| 31 | `DeliveryDate` 填成與 `ShipmentDate` 同一天 | **不可同天**（`E090`）、不可過去（`E088`）、不可超過出貨日 7 天（`E091`）|
| 32 | 撿貨明細商品名超過 32 字 | **系統靜默截斷、不回報錯誤**，送出前自行驗長度 |
| 33 | `SenderZipCode` 仍照 `String(15)` 設計欄位 | v2.1.2 收緊為 **`String(6)`** |
| 34 | 假設「逆物流不需郵號」 | v2.1.2 起 `ReserveAdd`/`Edit`/`Query` **有** `RecipientZipCode` / `SenderZipCode`（選填）|
| 35 | 電話欄位驗證只允許 `-` 和 `#` | v2.1.2 起**還允許 `,`**，且長度統一放寬到 30 |
| 36 | 在兩組正式環境間隨機分流 | 官方未說明兩者關係，**固定用正式-1**，正式-2 當手動 failover |
| 37 | 只比對 `Message` 文字判斷錯誤 | v2.1.2 有 `E001`–`E091`；但**回應無獨立錯誤碼欄位**，兩種解析都要支援 |
