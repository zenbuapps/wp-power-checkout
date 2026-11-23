# 程式範例：curl + TypeScript（Node 20+）

> **何時讀這份**：要開始寫嘉里大榮串接程式、或想先用 curl 在測試環境手動打一次時。PHP / WordPress 版見 `code-examples-php.md`。
> 欄位、合法值、必填：官方《客戶 API 串接規格書》v1.1.0（2025-03-19）。範例**尚未以真實帳密端到端跑過**（拿到的規格書帳號表是空白），第一次聯調時逐項核對。

## 目錄

- [1. 環境變數](#1-環境變數)
- [2. curl：測試環境逐支打](#2-curl測試環境逐支打)
- [3. TypeScript 型別](#3-typescript-型別)
- [4. 欄位正規化與單號工具](#4-欄位正規化與單號工具)
- [5. TypeScript client](#5-typescript-client)
- [6. 貨態映射](#6-貨態映射)
- [7. 號段配號（交易內取號）](#7-號段配號交易內取號)
- [8. 完整流程](#8-完整流程)

---

## 1. 環境變數

```dotenv
KERRYTJ_ENV=test                                            # test | production
KERRYTJ_EDI_BASE_URL=https://api.kerrytj.com/EDIEntranceTest  # 正式：https://api.kerrytj.com/EDIEntrance
KERRYTJ_TRACING_BASE_URL=https://KerryTracingEDI.kerrytj.com  # 規格書只有一組，無測試網址
KERRYTJ_CUSTOMER_NO=        # 客戶編號 STRING(8)
KERRYTJ_USERNAME=           # Basic Auth 帳號（測試 / 正式各一組）
KERRYTJ_PASSWORD=
KERRYTJ_BLN_RANGE_START=    # 常溫 11 碼號段
KERRYTJ_BLN_RANGE_END=
```

本專案範本：`.tmp/[物流]嘉里大榮/.env.local`（已 gitignore）。

---

## 2. curl：測試環境逐支打

```bash
EDI=https://api.kerrytj.com/EDIEntranceTest
TRK=https://KerryTracingEDI.kerrytj.com
AUTH="$KERRYTJ_USERNAME:$KERRYTJ_PASSWORD"

# 0) 連通性：無憑證應得 401（代表主機 / 路由正常）
curl -i -X POST "$EDI/ShipmentDelivery" -H "Content-Type: application/json" -d "[]"
# → 401 Unauthorized ; WWW-Authenticate: Basic realm="api.kerrytj.com"

# 1) 上傳託運資料（必填 13 欄；選填可省略或送 ""）
curl -s -u "$AUTH" -X POST "$EDI/ShipmentDelivery" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '[{
    "ShipDate":"20261029","CustomerNo":"'"$KERRYTJ_CUSTOMER_NO"'","BLN":"<號段內 11 碼>",
    "Consignee":"王小明","ConsigneePost":"407","ConsigneeAdd":"台中市西屯區某路2號","ConsigneePhone":"0912345678",
    "Shipper":"某某商行","ShipperPost":"104","ShipperAdd":"台北市中山區某路1號","ShipperTel":"0223456789",
    "Temperature":"1","Piece":"1","CON":"ORD20261029001","AC":"","ETA":"4","PaymentType":""
  }]'
# → {"Result":"01","Count":1,"Data":[],"AreaData":[{...StationName...}]}

# 2) 修改託運資料（送完整 34 欄中的必填 + 要改的欄位；已交站所則無法改）
curl -s -u "$AUTH" -X POST "$EDI/UpdateShipmentDelivery" -H "Content-Type: application/json" -d @update.json

# 3) A4 標籤（3×2，一次最多 6 筆；成功回 PDF，失敗回 JSON）
curl -s -u "$AUTH" -X POST "$EDI/Report/GetLabelPdfA4" -H "Content-Type: application/json" \
  -d @shipments.json -o label.pdf -w "%{http_code} %{content_type}\n"

# 4) 單張標籤：body 是「單一物件」不是陣列
curl -s -u "$AUTH" -X POST "$EDI/Report/GetLabelPdf" -H "Content-Type: application/json" \
  -d @one-shipment.json -o label-1.pdf

# 5) 託運明細表
curl -s -u "$AUTH" -X POST "$EDI/Report/GetDetailPdf" -H "Content-Type: application/json" \
  -d @shipments.json -o detail.pdf

# 6) 常溫取件（退貨）：Shipper* = 取件地（消費者），Consignee* = 送回地（商家）
curl -s -u "$AUTH" -X POST "$EDI/PickupV2" -H "Content-Type: application/json" -d '[{
  "BLN":"<號段內 11 碼>","PickupNo":"RMA0001","CustomerNo":"'"$KERRYTJ_CUSTOMER_NO"'",
  "Shipper":"王小明","ShipperTel1":"0912345678","ShipperPost":"407","ShipperAdd":"台中市西屯區某路2號",
  "Consignee":"某某商行","ConsigneeTel1":"0223456789","ConsigneePost":"104","ConsigneeAdd":"台北市中山區某路1號",
  "Piece":"1","Remark":"退貨"
}]'

# 7) 貨況：常溫 11 碼 / 低溫 10 碼分開打（沒有測試網址）
curl -s -u "$AUTH" -X POST "$TRK/api/Tracing/BLNListTracing"     -H "Content-Type: application/json" -d '[{"BLN":"<11碼>"}]'
curl -s -u "$AUTH" -X POST "$TRK/api/Tracing/ColdBLNListTracing" -H "Content-Type: application/json" -d '[{"BLN":"<10碼>"}]'
```

> 先在 **`EDIEntranceTest`** 跑通，再把 base URL 換成 `EDIEntrance`。測試環境的帳密 / 客編 / 號段與正式環境不同（規格書帳號表分兩欄），不要混用。

---

## 3. TypeScript 型別

```typescript
// kerrytj.types.ts — 欄位 / 長度 / 合法值：官方規格書 v1.1.0。全部值皆為 string。

/** 溫層：1 常溫 / 2 冷藏 / 3 冷凍 */
export type KerryTemperature = '1' | '2' | '3';

/** ShipmentDelivery / UpdateShipmentDelivery 共用（34 欄；必填 13 欄） */
export interface KerryShipment {
  ShipDate: string;        // ✅ STRING(8) yyyyMMdd
  CustomerNo: string;      // ✅ STRING(8)
  Consignee: string;       // ✅ STRING(6) ← 只有 6 字元
  ConsigneePost: string;   // ✅ STRING(3)
  ConsigneeAdd: string;    // ✅ STRING(60)
  ConsigneePhone: string;  // ✅ STRING(15)
  BLN: string;             // ✅ STRING(11) 常溫 11 / 低溫 10
  Shipper: string;         // ✅ STRING(6)
  ShipperPost: string;     // ✅ STRING(3)
  ShipperAdd: string;      // ✅ STRING(60)
  ShipperTel: string;      // ✅ STRING(15)
  Temperature: KerryTemperature; // ✅
  Piece: string;           // ✅ STRING(3) 1–999
  Weight?: string;         // STRING(4) 0–9999
  Volume?: string;         // STRING(4) 才積 0–9999
  CON?: string;            // STRING(12) 出貨單號（低溫 ≤ 10）
  AC?: string;             // STRING(6) 代收貨款 ≤ 50000
  PickupTime?: '' | '1' | '2' | '3' | '4';   // 上午 / 下午 / 晚上 / 不指定
  ETA?: '' | '1' | '2' | '4';                // 08-12 / 14-19 / 不指定
  Remark?: string;         // STRING(60)
  DeclaredValue?: string;  // STRING(6) 報值 ≤ 50000
  SpecialService?: '' | '1';
  StationEnable?: '' | '1';                  // 站止（到站自領）
  Receipt?: string;        // 回單 0–9
  HolidayId?: '' | '6' | '7';                // 假日配送：週六 / 週日
  MemberId?: string;       // STRING(30)
  BoxNumber?: string;      // STRING(30)
  IsValuable?: '' | '1';
  CityType?: '' | '00' | '01' | '02';        // 同縣市 / 外縣市 / 離島
  Specification?: '' | '0001' | '0002' | '0003' | '0004'; // 60/90/120/150CM
  BoxSize?: string;        // STRING(3) 長+寬+高 cm
  DailyDelyId?: '' | 'Y' | 'N';
  PaymentType?: '' | '0' | '1';              // 到付 / 現收
  ETP?: string;            // STRING(8) 指送日期 yyyyMMdd
}

/** PickupV2（常溫取件；必填 11 欄） */
export interface KerryPickup {
  BLN: string;            // ✅ STRING(11)
  PickupNo?: string;      // STRING(30)
  CustomerNo: string;     // ✅ STRING(8)
  Consignee: string;      // ✅ STRING(6)   收貨人（貨送回的一方）
  ConsigneeTel1: string;  // ✅ STRING(20)
  ConsigneeTel2?: string;
  ConsigneePost: string;  // ✅ STRING(3)
  ConsigneeAdd: string;   // ✅ STRING(100)
  Shipper: string;        // ✅ STRING(20)  取件人（去哪裡取件）
  ShipperTel1: string;    // ✅ STRING(20)
  ShipperTel2?: string;
  ShipperPost: string;    // ✅ STRING(3)
  ShipperAdd: string;     // ✅ STRING(120)
  Volume?: string;        // STRING(7) 整數
  Weight?: string;        // STRING(7) 整數
  Remark?: string;        // STRING(100)
  Piece: string;          // ✅ STRING(7) 整數
}

/** GetLabelPdf / GetLabelPdfA4 只吃這 16 欄；GetDetailPdf 另加 Weight/Volume/ETA/DeclaredValue/SpecialService/StationEnable/Receipt/HolidayId/PaymentType/ETP */
export type KerryLabel = Pick<KerryShipment,
  'ShipDate' | 'CustomerNo' | 'Consignee' | 'ConsigneePost' | 'ConsigneeAdd' | 'ConsigneePhone' | 'BLN' |
  'Shipper' | 'ShipperPost' | 'ShipperAdd' | 'ShipperTel' | 'Piece' | 'CON' | 'AC' | 'Remark' | 'Temperature'>;

export type KerryResultCode = '01' | '02' | '03'; // 全部成功 / 部分成功 / 錯誤

export interface KerryWriteResponse {
  Result: KerryResultCode;
  Count: number;                                // 推得：成功筆數
  Data: Array<{ BLN: string; Error: string }>;  // 失敗明細
}

export interface KerryAreaData {
  Id: string; Address: string; StationCode: string; StationShotCode: string; StationName: string;
  ZipCode: string; DestDelyArea: string; HomeDelyArea: string; BikeDelyArea: string;
  DelyFeeArea: string; SpecialServiceFeeAreaOne: string; SpecialServiceFeeAreaTwo: string;
}

export interface KerryShipmentResponse extends KerryWriteResponse {
  AreaData: KerryAreaData[];
}

export interface KerryCargoEvent {
  BLN: string;
  Station?: string;          // 官方範例有缺此鍵的情況
  Date: string;              // yyyyMMdd
  Time: string;              // HHmmss
  StatusCode: string;        // 英數混合（A1、F0、8E）
  Status: string;
  StatusCodeType?: 1 | 2;    // 只有常溫有；1 配送 / 2 取件退貨
}

export interface KerryTracingResponse {
  Status: '1' | '0';
  Error: string;
  Result: Array<{ BLN: string; CargoTracing: KerryCargoEvent[] }>;
}

/** Report 失敗時的 JSON（ASP.NET ModelState） */
export interface KerryModelStateError {
  Message: string;                       // "要求無效。"
  ModelState: Record<string, string[]>;  // { "request[0].BLN": ["託運單號必填"] }
}
```

---

## 4. 欄位正規化與單號工具

```typescript
// kerrytj.utils.ts
import type { KerryShipment } from './kerrytj.types';

/** 依 Unicode 字元截斷（中文 1 字 = 1 字元；規格書 STRING(n) 以字元計，推得） */
export const cut = (s: string | undefined, n: number) => Array.from((s ?? '').trim()).slice(0, n).join('');

/** 郵遞區號只收 3 碼 */
export const zip3 = (zip: string) => zip.replace(/\D/g, '').slice(0, 3);

/** 電話去掉 - 與空白 */
export const phone = (p: string, n: number) => cut(p.replace(/[\s-]/g, ''), n);

/** 台灣時區 yyyyMMdd */
export const ymd = (d = new Date()) =>
  new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Taipei' }).format(d).replaceAll('-', '');

/** 送出前把託運資料修成符合官方長度；超長的收件人全名搬到 Remark */
export function normalizeShipment(s: KerryShipment): KerryShipment {
  const fullName = s.Consignee.trim();
  const remark = Array.from(fullName).length > 6 ? `收件人:${fullName} ${s.Remark ?? ''}` : (s.Remark ?? '');
  return {
    ...s,
    Consignee: cut(s.Consignee, 6),
    ConsigneePost: zip3(s.ConsigneePost),
    ConsigneeAdd: cut(s.ConsigneeAdd, 60),
    ConsigneePhone: phone(s.ConsigneePhone, 15),
    Shipper: cut(s.Shipper, 6),
    ShipperPost: zip3(s.ShipperPost),
    ShipperAdd: cut(s.ShipperAdd, 60),
    ShipperTel: phone(s.ShipperTel, 15),
    CON: cut(s.CON, s.Temperature === '1' ? 12 : 10),   // 低溫出貨單號僅 10 字元
    Remark: cut(remark, 60),
  };
}

/** 驗證（送出前自檢；伺服器 03 不會告訴你原因） */
export function validateShipment(s: KerryShipment): string[] {
  const errs: string[] = [];
  const req: (keyof KerryShipment)[] = ['ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd',
    'ConsigneePhone', 'BLN', 'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'Temperature', 'Piece'];
  for (const k of req) if (!s[k]) errs.push(`${k} 必填`);
  if (!/^\d{8}$/.test(s.ShipDate)) errs.push('ShipDate 格式 yyyyMMdd');
  const blnLen = s.Temperature === '1' ? 11 : 10;
  if (!new RegExp(`^\\d{${blnLen}}$`).test(s.BLN)) errs.push(`BLN 應為 ${blnLen} 碼數字`);
  if (s.Temperature !== '1' && !isValidColdBln(s.BLN)) errs.push('低溫 BLN 檢查碼錯誤');
  const piece = Number(s.Piece);
  if (!Number.isInteger(piece) || piece < 1 || piece > 999) errs.push('Piece 須 1–999');
  for (const k of ['AC', 'DeclaredValue'] as const) if (s[k] && Number(s[k]) > 50000) errs.push(`${k} 不可超過 50000`);
  return errs;
}

/** 低溫單號：9 碼 + 1 碼檢查碼（前 9 碼 mod 7）。例 667000000 → 6670000002 */
export const coldBln = (serial9: string | bigint) => {
  const s = String(serial9).padStart(9, '0');
  return s + String(BigInt(s) % 7n);
};
export const isValidColdBln = (bln: string) => /^\d{10}$/.test(bln) && coldBln(bln.slice(0, 9)) === bln;

/** 常溫單號：11 碼流水號 +1（無檢查碼）。例 89000000000 → 89000000001 */
export const nextNormalBln = (bln: string) => String(BigInt(bln) + 1n).padStart(11, '0');
```

---

## 5. TypeScript client

```typescript
// kerrytj.client.ts — Node 20+（原生 fetch）
import type {
  KerryShipment, KerryPickup, KerryLabel, KerryShipmentResponse, KerryWriteResponse,
  KerryTracingResponse, KerryCargoEvent, KerryModelStateError,
} from './kerrytj.types';

export interface KerryConfig {
  username: string;
  password: string;
  ediBaseUrl: string;       // https://api.kerrytj.com/EDIEntranceTest 或 .../EDIEntrance
  tracingBaseUrl?: string;  // 預設 https://KerryTracingEDI.kerrytj.com
  timeoutMs?: number;
}

export class KerryTjError extends Error {
  constructor(message: string, readonly status?: number, readonly body?: unknown) { super(message); }
}

/** 寫入類結果：逐筆標示成功 / 失敗，讓呼叫端處理部分成功（Result 02） */
export interface KerryWriteOutcome<T extends KerryWriteResponse = KerryWriteResponse> {
  raw: T;
  succeeded: string[];                       // 成功的 BLN
  failed: Array<{ BLN: string; Error: string }>;
}

export class KerryTjClient {
  private readonly edi: string;
  private readonly tracing: string;
  private readonly auth: string;
  private readonly timeoutMs: number;

  constructor(cfg: KerryConfig) {
    this.edi = cfg.ediBaseUrl.replace(/\/$/, '');
    this.tracing = (cfg.tracingBaseUrl ?? 'https://KerryTracingEDI.kerrytj.com').replace(/\/$/, '');
    this.auth = 'Basic ' + Buffer.from(`${cfg.username}:${cfg.password}`, 'utf8').toString('base64');
    this.timeoutMs = cfg.timeoutMs ?? 60_000;
  }

  private async post(url: string, body: unknown): Promise<Response> {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8', Authorization: this.auth },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(this.timeoutMs),
    });
    if (res.status === 401) throw new KerryTjError('嘉里大榮 Basic Auth 被拒（帳密錯誤、環境用錯或未開通）', 401);
    return res;
  }

  private async write<T extends KerryWriteResponse>(path: string, items: { BLN: string }[]): Promise<KerryWriteOutcome<T>> {
    if (items.length === 0) throw new KerryTjError(`${path}：至少一筆`);
    const res = await this.post(`${this.edi}/${path}`, items);
    if (!res.ok) throw new KerryTjError(`${path} HTTP ${res.status}`, res.status, await res.text());
    const raw = (await res.json()) as T;
    if (raw.Result === '03') throw new KerryTjError(`${path} 錯誤（Result 03，未提供原因）`, res.status, raw);
    const failed = raw.Data ?? [];
    const failedSet = new Set(failed.map((f) => f.BLN));
    return { raw, failed, succeeded: items.map((i) => i.BLN).filter((b) => !failedSet.has(b)) };
  }

  /** 上傳託運資料（Result 01/02 都回傳 outcome；03 拋錯） */
  shipmentDelivery(items: KerryShipment[]) {
    return this.write<KerryShipmentResponse>('ShipmentDelivery', items);
  }

  /** 修改託運資料（已交站所則無法修改） */
  updateShipmentDelivery(items: KerryShipment[]) {
    return this.write<KerryWriteResponse>('UpdateShipmentDelivery', items);
  }

  /** 常溫取件（退貨）。中午 12 點後送出 → D+1 派遣 */
  pickupV2(items: KerryPickup[]) {
    return this.write<KerryWriteResponse>('PickupV2', items);
  }

  /** 單張標籤：body 是單一物件 */
  labelPdf(item: KerryLabel): Promise<Buffer> {
    return this.pdf('Report/GetLabelPdf', item);
  }

  /** A4 3×2 標籤：一次最多 6 筆，超過自動分批，回傳多個 PDF */
  async labelPdfA4(items: KerryLabel[]): Promise<Buffer[]> {
    const out: Buffer[] = [];
    for (let i = 0; i < items.length; i += 6) out.push(await this.pdf('Report/GetLabelPdfA4', items.slice(i, i + 6)));
    return out;
  }

  /** 託運明細表 */
  detailPdf(items: KerryShipment[]): Promise<Buffer> {
    return this.pdf('Report/GetDetailPdf', items);
  }

  private async pdf(path: string, body: unknown): Promise<Buffer> {
    if (Array.isArray(body) && body.length === 0) throw new KerryTjError(`${path}：空陣列會回空白 PDF`);
    const res = await this.post(`${this.edi}/${path}`, body);
    const type = res.headers.get('content-type') ?? '';
    const buf = Buffer.from(await res.arrayBuffer());
    if (!type.includes('application/pdf')) {
      let detail: KerryModelStateError | string = buf.toString('utf8');
      try { detail = JSON.parse(detail) as KerryModelStateError; } catch { /* 保留原文 */ }
      throw new KerryTjError(`${path} 未回傳 PDF`, res.status, detail);
    }
    return buf;
  }

  /** 常溫貨況（11 碼） */
  traceNormal(blns: string[]) { return this.trace('BLNListTracing', blns); }

  /** 低溫貨況（10 碼） */
  traceCold(blns: string[]) { return this.trace('ColdBLNListTracing', blns); }

  private async trace(path: string, blns: string[]): Promise<Map<string, KerryCargoEvent[]>> {
    const res = await this.post(`${this.tracing}/api/Tracing/${path}`, blns.map((BLN) => ({ BLN })));
    const json = (await res.json()) as KerryTracingResponse;
    if (json.Status !== '1') throw new KerryTjError(`${path} 失敗：${json.Error}`, res.status, json);
    const map = new Map<string, KerryCargoEvent[]>();
    for (const r of json.Result ?? []) {
      // 官方範例同一 BLN 可能出現兩次：合併 + 去重 + 新到舊排序
      const seen = new Map((map.get(r.BLN) ?? []).map((e) => [`${e.Date}${e.Time}${e.StatusCode}`, e]));
      for (const e of r.CargoTracing ?? []) seen.set(`${e.Date}${e.Time}${e.StatusCode}`, e);
      map.set(r.BLN, [...seen.values()].sort((a, b) => (b.Date + b.Time).localeCompare(a.Date + a.Time)));
    }
    return map;
  }
}
```

---

## 6. 貨態映射

```typescript
// kerrytj.status.ts — 依 tracking.md §4 的「建議映射」（推得，非官方）
import type { KerryCargoEvent } from './kerrytj.types';

export type ShipState = 'in_transit' | 'out_for_delivery' | 'ready_for_pickup' | 'delivered'
  | 'exception' | 'returning' | 'returned' | 'cancelled' | 'info';

const NORMAL_1: Record<string, ShipState> = {
  '02': 'delivered', '09': 'delivered', '30': 'delivered', '36': 'delivered', '38': 'delivered', '62': 'delivered', '78': 'delivered',
  '77': 'returned', '53': 'returning', '58': 'returning', '65': 'returning', '67': 'returning',
  '19': 'out_for_delivery', '73': 'out_for_delivery', '74': 'out_for_delivery', '76': 'out_for_delivery', '88': 'out_for_delivery',
  '08': 'ready_for_pickup', '24': 'ready_for_pickup', '72': 'ready_for_pickup', '96': 'ready_for_pickup', 'B1': 'ready_for_pickup', 'F1': 'ready_for_pickup',
  '04': 'info', '63': 'info', '68': 'info', 'A1': 'info',
  ...Object.fromEntries(['00','05','06','10','11','12','13','14','15','16','17','18','22','29','32','34','35','40','42','43','46','50',
    '64','66','69','79','82','89','97','A4','B2','B3','F2','F3'].map((c) => [c, 'exception' as const])),
};
const NORMAL_2: Record<string, ShipState> = {
  '01': 'cancelled', 'C2': 'cancelled', 'C1': 'delivered', '02': 'in_transit', '03': 'in_transit', 'P2': 'in_transit',
  'P1': 'out_for_delivery', 'D1': 'info', 'D2': 'info', '99': 'info',
  '04': 'exception', '05': 'exception', '06': 'exception', '07': 'exception', '08': 'exception', '09': 'exception',
};
const COLD: Record<string, ShipState> = {
  '90': 'delivered', '91': 'delivered', '92': 'delivered', '71': 'out_for_delivery', '73': 'out_for_delivery',
  '84': 'ready_for_pickup', '85': 'returning', '93': 'returning',
  ...Object.fromEntries(['8E','80','81','82','83','86','87','88','89','96','97'].map((c) => [c, 'exception' as const])),
};

/** 未列出的代碼：常溫 / 低溫一律視為 in_transit（不會誤判為終態） */
export function mapStatus(e: KerryCargoEvent, cold: boolean): ShipState {
  const table = cold ? COLD : e.StatusCodeType === 2 ? NORMAL_2 : NORMAL_1;
  return table[e.StatusCode] ?? 'in_transit';
}

export const isTerminal = (s: ShipState) => s === 'delivered' || s === 'returned' || s === 'cancelled';
```

---

## 7. 號段配號（交易內取號）

```typescript
// 以 PostgreSQL 為例：同一交易 row-lock 號段並遞增，避免同號被兩筆訂單取得。
import type { Pool } from 'pg';
import { coldBln } from './kerrytj.utils';

/**
 * kerrytj_bln_ranges(id, env, customer_no, is_cold boolean, start_no bigint, end_no bigint, next_no bigint)
 * 常溫：存 11 碼完整單號；低溫：存「前 9 碼」流水號，取號時補檢查碼。
 */
export async function allocateBln(pool: Pool, env: 'test' | 'production', customerNo: string, temperature: '1' | '2' | '3') {
  const isCold = temperature !== '1';
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const { rows } = await client.query(
      `SELECT id, next_no FROM kerrytj_bln_ranges
        WHERE env = $1 AND customer_no = $2 AND is_cold = $3 AND next_no <= end_no
        ORDER BY start_no LIMIT 1 FOR UPDATE`,
      [env, customerNo, isCold],
    );
    if (rows.length === 0) throw new Error('嘉里大榮託運單號段已用罄，請向營業所申請新號段');
    const { id, next_no } = rows[0] as { id: number; next_no: string };
    await client.query('UPDATE kerrytj_bln_ranges SET next_no = next_no + 1 WHERE id = $1', [id]);
    await client.query('COMMIT');
    return isCold ? coldBln(next_no) : String(next_no).padStart(11, '0');
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  } finally {
    client.release();
  }
}
```

- 號段分 **env** 存：測試號段與正式號段由嘉里大榮分開核發。
- 取號後建單失敗 → **沿用同一 BLN 重送**，不要再取新號。
- 低溫單號**不可重複使用**；常溫單號每年可重複使用（官方規格），但仍建議申請新號段避免查詢混淆。

---

## 8. 完整流程

```typescript
import { KerryTjClient } from './kerrytj.client';
import { normalizeShipment, validateShipment, ymd } from './kerrytj.utils';
import { mapStatus, isTerminal } from './kerrytj.status';

const kerry = new KerryTjClient({
  username: process.env.KERRYTJ_USERNAME!,
  password: process.env.KERRYTJ_PASSWORD!,
  ediBaseUrl: process.env.KERRYTJ_EDI_BASE_URL!,   // 先用 EDIEntranceTest
});

const bln = await allocateBln(pool, 'test', process.env.KERRYTJ_CUSTOMER_NO!, '1');
const shipment = normalizeShipment({
  ShipDate: ymd(), CustomerNo: process.env.KERRYTJ_CUSTOMER_NO!, BLN: bln,
  Shipper: '某某商行', ShipperTel: '02-2345-6789', ShipperPost: '10491', ShipperAdd: '台北市中山區某路 1 號',
  Consignee: '王小明', ConsigneePhone: '0912-345-678', ConsigneePost: '40756', ConsigneeAdd: '台中市西屯區某路 2 號',
  Temperature: '1', Piece: '1', CON: 'ORD20261029001', AC: '', ETA: '4',
});
const errs = validateShipment(shipment);
if (errs.length) throw new Error(errs.join('；'));

// 1) 建單：處理部分成功
const { succeeded, failed, raw } = await kerry.shipmentDelivery([shipment]);
if (failed.length) console.warn('嘉里大榮建單失敗', failed);       // 失敗的修正後用同一 BLN 重送
const area = raw.AreaData?.find((a) => a.Id === bln);             // 到著站資訊（Id 推得 = BLN）

// 2) 標籤（用同一份資料）
const [labelPdf] = await kerry.labelPdfA4([shipment]);

// 3) 排程輪詢貨況（常溫 / 低溫分開）
const events = (await kerry.traceNormal([bln])).get(bln) ?? [];
const latest = events[0];
if (latest) {
  const state = mapStatus(latest, false);
  if (isTerminal(state)) { /* 停止輪詢、更新訂單 */ }
}
```
