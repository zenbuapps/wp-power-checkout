# 新竹物流 整合程式碼範例（TypeScript / NestJS + 官方 C#）

本範例以 NestJS + TypeScript service 形式呈現。對應 V15（2026-04-28）的方法簽章與欄位。

> **協定提醒**：新竹是 SOAP `.asmx` 服務。`TransData_Json` / `addrCompare_Json` 等 `_Json` 變體**仍是 SOAP 呼叫**——只是把 JSON 字串塞進 SOAP body 的 `json` 參數，回傳也是包在 SOAP response 裡的 JSON 字串。因此 HTTP 呼叫需送出 SOAP envelope（`text/xml; charset=utf-8` + `SOAPAction` header），不是直接 POST JSON 到 REST endpoint。
>
> **V15 明載的 HTTP 規格**：Method `POST`、Protocol `HTTPS`、Encoding `UTF-8`、Content-Type `text/xml; charset=utf-8`。
>
> **WSDL / namespace / SOAPAction**：V15 文件**未提供**。實作前先抓 `?wsdl` 確認：
> `https://hctrt.hct.com.tw/edi_webservice2/service1.asmx?wsdl`
> 下方範例的 namespace 為**佔位示意**，務必以實際 WSDL 為準（實打 WSDL 的觀察見 `version-notes.md`）。

## 目錄（TOC）

- [1. 環境設定（NestJS ConfigService）](#1-環境設定nestjs-configservice)
- [2. SOAP client helper（手動組 envelope）](#2-soap-client-helper手動組-envelope)
- [3. TransData_Json — 建立託運單](#3-transdata_json--建立託運單)
- [4. 標籤 image 還原（hex → 圖片）](#4-標籤-image-還原hex--圖片)
- [5. Codabar 物流單號 + 檢查碼](#5-codabar-物流單號--檢查碼)
- [6. UpdData / TransReport / QueryEDELNO](#6-upddata--transreport--queryedelno)
- [7. 逆物流 R_TransData_Json（大寫 key 正規化）](#7-逆物流-r_transdata_json大寫-key-正規化)
- [8. 取消收貨 / 派遣收貨](#8-取消收貨--派遣收貨)
- [9. 完整出貨流程（編排範例）](#9-完整出貨流程編排範例)
- [10. 錯誤處理建議](#10-錯誤處理建議)
- [11. 官方 C# image function（V15 原文）](#11-官方-c-image-functionv15-原文)

---

## 1. 環境設定（NestJS ConfigService）

對齊禁直讀 `process.env` 慣例（走 `ConfigService` / settings）。

```typescript
// hct-config.ts
export interface HctConfig {
  /** 出貨/託運 EDI WebService（V15：PROD / DEV 分離）*/
  ediWebServiceUrl: string; // PROD https://hctrt.hct.com.tw/edi_webservice2/service1.asmx
                            // DEV  https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx
  /** 集貨註區 SOAP（addrCompare，舊機制）*/
  addrCompareUrl: string;   // http://is1fax.hct.com.tw/Webedi_Erstno_NEW2/WS_addrCompare1.asmx
  /** 出貨 API 帳密（測試：test / test1）*/
  company: string;
  password: string;
  /** 集貨註區帳號（測試：TEST2）*/
  addrCompareUser: string;
  /** SOAP namespace（V15 未提供，以實際 WSDL 為準）*/
  ediNamespace: string;
}
```

---

## 2. SOAP client helper（手動組 envelope）

```typescript
// hct-soap.client.ts
import { Injectable, Logger } from '@nestjs/common';

@Injectable()
export class HctSoapClient {
  private readonly logger = new Logger(HctSoapClient.name);

  /**
   * 呼叫 .asmx SOAP 方法。
   * @param url        .asmx 端點
   * @param namespace  SOAP target namespace（以 WSDL 為準）
   * @param method     方法名，如 'TransData_Json'
   * @param params     依文件順序的參數（key=參數名, value=字串）。
   *                   注意：TransReport_Json 第三參數名是 dsCusJson，不是 json。
   * @returns 該方法回傳值（已抽出 SOAP body 內的 <{method}Result>）
   */
  async call(
    url: string,
    namespace: string,
    method: string,
    params: Record<string, string>,
  ): Promise<string> {
    const body = Object.entries(params)
      .map(([k, v]) => `<${k}>${escapeXml(v)}</${k}>`)
      .join('');

    const envelope =
      `<?xml version="1.0" encoding="utf-8"?>` +
      `<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"` +
      ` xmlns:xsd="http://www.w3.org/2001/XMLSchema"` +
      ` xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">` +
      `<soap:Body><${method} xmlns="${namespace}">${body}</${method}></soap:Body>` +
      `</soap:Envelope>`;

    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'text/xml; charset=utf-8',
        // SOAPAction = namespace + '/' + method（尾端格式依 WSDL；asmx 多為 ns/method 無尾斜線）
        SOAPAction: `"${namespace.replace(/\/$/, '')}/${method}"`,
      },
      body: envelope,
    });

    if (!res.ok) {
      throw new Error(`[HCT] SOAP ${method} HTTP ${res.status}`);
    }
    const text = await res.text();
    return extractSoapResult(text, `${method}Result`);
  }
}

function escapeXml(s: string): string {
  return s.replace(/[<>&'"]/g, (c) =>
    ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' }[c]!),
  );
}

/** 從 SOAP response 抽出 <Tag>...</Tag> 內文（含 entity 解碼）*/
function extractSoapResult(xml: string, tag: string): string {
  const re = new RegExp(`<${tag}[^>]*>([\\s\\S]*?)</${tag}>`);
  const m = xml.match(re);
  if (!m) throw new Error(`[HCT] SOAP response missing <${tag}>`);
  return decodeXmlEntities(m[1]);
}

function decodeXmlEntities(s: string): string {
  return s
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');
}
```

> 生產環境可改用成熟 SOAP 套件（如 `soap` npm package）自動解析 WSDL。但若 image 約 2 萬字元易超過 node-soap 預設 `maxReceivedMessageSize`，手組 envelope（如上）可避開該限制。

---

## 3. TransData_Json — 建立託運單

```typescript
// hct-shipping.service.ts
import { Injectable, Logger } from '@nestjs/common';
import { HctSoapClient } from './hct-soap.client';
import type { HctConfig } from './hct-config';

/** TransData 傳入欄位（V15 完整；最小必填 = epino/ercsig/ertel1/eraddr/ejamt/eqamt）*/
export interface HctTransDataInput {
  epino: string;      // 訂單編號（必填，String(30)）
  ercsig: string;     // 收貨人名稱（必填，String(40)）
  ertel1: string;     // 收貨人電話1（必填，String(20)）
  eraddr: string;     // 收貨人地址（必填，String(100)）
  ejamt: string;      // 件數（必填，最小1）
  eqamt: string;      // 重量 kg（必填，進位整數）
  ertel2?: string;
  esdate?: string;    // YYYYMMDD（預設今天）
  escsno?: string;    // 客代
  esstno?: string;    // 出貨站
  edelno?: string;    // 新竹貨號（不給則系統配號）
  etcsig?: string;    // 出貨人名稱
  ettel1?: string;
  ettel2?: string;
  etaddr?: string;
  eddate?: string;    // 指定日期 YYYYMMDD
  eqmny?: string;     // 代收貨款（預設0）
  eprdct?: string;    // 傳票類別 11月結/21到付/31現收/41代收(COD)
  emark?: string;     // 備註
  eprdcl2?: string;   // 商品種類 001一般/003冷凍/008冷藏（預設001）
  egamt?: string;     // 報值金額
  erenum?: string;    // 回單張數（V15 新增）
  HAWB?: string;      // 小提單號（跨境，必須）
  boxNo?: string;     // 報關箱號（跨境）
  MAWB?: string;      // 大提單號（跨境）
  Declare?: string;   // 報單號碼（跨境）
}

/** TransData 回傳欄位（V15 R1~R20，含自繪標籤用的 CODE/MDCODE）*/
export interface HctTransDataResult {
  Num: string;
  success: 'Y' | 'R' | 'N'; // Y新增 R修改 N失敗
  edelno: string;           // 新竹貨號（10碼，含檢查碼）
  epino: string;
  erstno: string;           // 到著站號碼
  eqamt: string;
  image: string;            // 標籤 hex 字串
  ErrMsg: string | null;
  NewOutArea: string;       // 新集貨註區
  eqmny: string;            // 代收貨款
  CODE1: string;            // 類別
  CODE2: string;            // 到著簡碼
  CODE3: string;            // 衛星區
  CODE4: string;            // 註區（黑底白字）
  CODE5: string;            // 疊區
  CODE7: string;            // QRcode 內容（注意：addrCompare 為 CODE6）
  AREAS: string;            // 聯運區提醒（* 有 / 空白無）
  MDCODE1: string;          // MD 衛星區
  MDCODE2: string;          // MD 註區（黑底白字）
  MDCODE3: string;          // MD 疊區
}

@Injectable()
export class HctShippingService {
  private readonly logger = new Logger(HctShippingService.name);

  constructor(
    private readonly soap: HctSoapClient,
    private readonly cfg: HctConfig,
  ) {}

  /** 建立託運單（一批建議 <= 30 筆；若需回傳 image，一次 <= 5 筆）*/
  async createShipments(items: HctTransDataInput[]): Promise<HctTransDataResult[]> {
    if (items.length > 5) {
      this.logger.warn(`[HCT] 帶 image 回傳一次上限 5 筆，目前 ${items.length} 筆`);
    }
    const json = JSON.stringify(items);
    const resultJson = await this.soap.call(
      this.cfg.ediWebServiceUrl,
      this.cfg.ediNamespace,
      'TransData_Json',
      { company: this.cfg.company, password: this.cfg.password, json },
    );
    const results: HctTransDataResult[] = JSON.parse(resultJson);

    for (const r of results) {
      if (r.success === 'N') {
        this.logger.warn(`[HCT] 託運單建立失敗 epino=${r.epino}: ${r.ErrMsg}`);
      }
    }
    return results;
  }
}
```

---

## 4. 標籤 image 還原（hex → 圖片）

`TransData` 回傳的 `image` 是 hex 編碼的 1bit 黑白 Bitmap。

### TypeScript（Node.js Buffer）

```typescript
/**
 * 將 HCT 回傳的 hex 字串還原成 Bitmap byte buffer。
 * 此 buffer 即原始 BMP 檔內容，可直接寫檔或回傳給前端列印。
 */
export function hctImageHexToBuffer(hex: string): Buffer {
  const clean = hex.replace(/\s/g, '');
  if (clean.length % 2 !== 0) {
    throw new Error('[HCT] image hex 長度必須為偶數');
  }
  return Buffer.from(clean, 'hex'); // 每 2 hex 字元 = 1 byte
}

// 用法：寫成 .bmp 檔
// import { writeFileSync } from 'fs';
// writeFileSync('label.bmp', hctImageHexToBuffer(result.image));
//
// 或轉 base64 data URL 給前端 <img> / 列印：
// const dataUrl = `data:image/bmp;base64,${hctImageHexToBuffer(result.image).toString('base64')}`;
```

---

## 5. Codabar 物流單號 + 檢查碼

```typescript
/**
 * 計算新竹物流貨號檢查碼：MOD(前9碼, 7)。
 * @param body9 9 碼物流單號本體（純數字字串）
 * @returns 完整 10 碼貨號（body9 + 檢查碼）
 */
export function hctBuildDelno(body9: string): string {
  if (!/^\d{9}$/.test(body9)) {
    throw new Error('[HCT] 物流單號本體必須為 9 碼數字');
  }
  const checkDigit = Number(BigInt(body9) % 7n); // 前9碼除以7的餘數（0~6）
  return body9 + String(checkDigit);
}

/** 驗證 10 碼貨號檢查碼是否正確。*/
export function hctVerifyDelno(delno10: string): boolean {
  if (!/^\d{10}$/.test(delno10)) return false;
  const body9 = delno10.slice(0, 9);
  const check = delno10.slice(9);
  return String(Number(BigInt(body9) % 7n)) === check;
}

/**
 * 組 Codabar 條碼編碼內容：前後用 D 包起來。
 * 條碼規格：高 15mm / 寬 54mm / 密度 0.4mm / 粗細比 2.5。
 */
export function hctCodabarValue(delno10: string): string {
  return `D${delno10}D`;
}
```

---

## 6. UpdData / TransReport / QueryEDELNO

```typescript
/** 修改重量（UpdData_Json）*/
async function modifyWeight(soap: HctSoapClient, cfg: HctConfig,
  items: { epino: string; edelno: string; eqamt: string }[]) {
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'UpdData_Json',
    { company: cfg.company, password: cfg.password, json: JSON.stringify(items) },
  );
  return JSON.parse(resultJson) as Array<{
    Num: string; success: 'R' | 'N'; edelno: string; epino: string; eqamt: string; ErrMsg: string | null;
  }>;
}

/** 確認當日出貨 / 列印總表（TransReport_Json — 注意第三參數是 dsCusJson）*/
async function confirmReport(soap: HctSoapClient, cfg: HctConfig,
  items: { epino: string; edelno: string }[]) {
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'TransReport_Json',
    { company: cfg.company, password: cfg.password, dsCusJson: JSON.stringify(items) }, // ← dsCusJson!
  );
  return JSON.parse(resultJson) as Array<{
    Num: string; success: 'Y' | 'N'; edelno: string; epino: string; ErrMsg: string | null;
  }>;
}

/** 查詢貨號（QueryEDELNO_Json）*/
async function queryEdelno(soap: HctSoapClient, cfg: HctConfig, epinos: string[]) {
  // 可一物件一筆，或單一物件 epino 帶逗號分隔字串（訂單編號中不能有逗號）
  const items = epinos.map((epino) => ({ epino }));
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'QueryEDELNO_Json',
    { company: cfg.company, password: cfg.password, json: JSON.stringify(items) },
  );
  return JSON.parse(resultJson) as Array<{
    success: 'Y' | 'N'; edelno: string; epino: string; ErrMsg: string;
  }>;
}
```

---

## 7. 逆物流 R_TransData_Json（大寫 key 正規化）

`R_TransData_Json` 回傳的 JSON key 首字大寫，建議正規化成小寫一致使用。

```typescript
export interface HctReturnInput {
  epino: string;      // 訂單編號（必填，不可重複，String(20)）
  ercsig: string;     // 退貨人名稱（必填）
  ertel1: string;     // 退貨人電話1（必填）
  eraddr: string;     // 退貨人地址（必填，String(60)）
  ejamt: string;      // 件數（必填）
  eqamt?: string;     // 重量
  esdate?: string;
  escsno?: string;
  edelno?: string;
  etcsig?: string;    // 收貨人名稱
  ettel1?: string;
  etaddr?: string;    // 收貨地址（String(60)）
  emark?: string;
  eprdcl2?: string;
  edelno2?: string;   // 原查貨號碼（原正物流出貨查貨號碼）
}

/** 回傳大寫 key 原型 */
interface RawReturnResult {
  Num: string;
  Success: 'Y' | 'N';
  Edelno: string;
  Epino: string;
  Erstno: string;
  Eqamt: string;
  ErrMsg: string;
}

export interface HctReturnResult {
  num: string;
  success: 'Y' | 'N';
  edelno: string;
  epino: string;
  erstno: string;     // 退貨人到著站號碼
  eqamt: string;
  errMsg: string;
}

async function createReturnShipment(soap: HctSoapClient, cfg: HctConfig, items: HctReturnInput[]):
  Promise<HctReturnResult[]> {
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'R_TransData_Json',
    { company: cfg.company, password: cfg.password, json: JSON.stringify(items) },
  );
  const raw: RawReturnResult[] = JSON.parse(resultJson);
  // 大寫 key → 小寫正規化（避免與正物流回傳混用時出錯）
  return raw.map((r) => ({
    num: r.Num, success: r.Success, edelno: r.Edelno, epino: r.Epino,
    erstno: r.Erstno, eqamt: r.Eqamt, errMsg: r.ErrMsg,
  }));
}
```

---

## 8. 取消收貨 / 派遣收貨

```typescript
/** 取消收貨資料 / 取消託運單（TransDataCancel_Json，V15 新增）*/
async function cancelShipment(soap: HctSoapClient, cfg: HctConfig,
  items: { edelno: string; epino: string }[]) {
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'TransDataCancel_Json',
    { company: cfg.company, password: cfg.password, json: JSON.stringify(items) },
  );
  return JSON.parse(resultJson) as Array<{
    Num: string; success: 'Y' | 'N'; edelno: string; epino: string; ErrMsg: string | null;
  }>;
}

/** 上傳需派遣收貨資料（TransDispatchOrder_Json，V15 新增 — 回傳結構特殊）*/
interface DispatchResponse {
  rtn_code: number;     // 回傳結果代碼
  rtn_msg: string;      // 回傳結果訊息
  rtn_data: Array<{     // 明細（key 小寫）
    num: string; success: 'Y' | 'N'; edelno: string; epino: string; emark: string; errMsg: string;
  }>;
}

async function dispatchOrder(soap: HctSoapClient, cfg: HctConfig,
  // emark = 派遣者，必填不可空白；派遣條件需為 30 天內同一 company 上傳的資料
  items: { edelno: string; epino: string; emark: string }[]): Promise<DispatchResponse> {
  const resultJson = await soap.call(
    cfg.ediWebServiceUrl, cfg.ediNamespace, 'TransDispatchOrder_Json',
    { company: cfg.company, password: cfg.password, json: JSON.stringify(items) },
  );
  return JSON.parse(resultJson) as DispatchResponse; // 注意：單一 object 包 rtn_data 陣列，非直接陣列
}
```

---

## 9. 完整出貨流程（編排範例）

```typescript
// hct-fulfillment.service.ts
import { Injectable, Logger } from '@nestjs/common';
import { HctShippingService } from './hct-shipping.service';

@Injectable()
export class HctFulfillmentService {
  private readonly logger = new Logger(HctFulfillmentService.name);
  constructor(private readonly shipping: HctShippingService) {}

  /**
   * 出貨完整流程：
   *  1. TransData_Json 建立託運單 → 取得 edelno + image(標籤) + 到著碼 CODE/MDCODE
   *  2. （當日確認出貨）TransReport_Json 確認出貨 → 列印總表（一式二份）
   */
  async fulfill(order: {
    orderNo: string;
    receiverName: string;
    receiverPhone: string;
    receiverAddress: string;
    packages: number;
    weightKg: number;
  }) {
    const [result] = await this.shipping.createShipments([
      {
        epino: order.orderNo,
        ercsig: order.receiverName,
        ertel1: order.receiverPhone,
        eraddr: order.receiverAddress,
        ejamt: String(order.packages),
        eqamt: String(Math.ceil(order.weightKg)), // 小數進位整數
        eprdct: '11',                              // 月結
        eprdcl2: '001',                            // 一般
      },
    ]);

    if (result.success === 'N') {
      throw new Error(`[HCT] 出貨建立失敗：${result.ErrMsg}`);
    }

    return {
      hctTrackingNo: result.edelno,   // 新竹貨號（= 物流單號 10 碼）
      arrivalStation: result.erstno,  // 到著站號
      labelBmpHex: result.image,      // 標籤圖（hex → 用 hctImageHexToBuffer 還原）
      qrcode: result.CODE7,           // QRcode 內容（自繪標籤用）
    };
    // 後續：當日確認出貨時呼叫 TransReport_Json([{ epino, edelno }]) 列印總表
  }
}
```

---

## 10. 錯誤處理建議

- `success === 'N'` 時讀 `ErrMsg`，對照 `api-reference.md` §9 錯誤明細表決定重試或人工處理。
- 帳密錯誤（「公司名稱或密碼錯誤」）→ 立即告警，不重試。
- 「訂單編號重複 / 一個月內貨號重複」→ 同一 `esdate` 內 `epino` 不可重複；確認是否誤送重複單。
- 「貨號檢查碼錯誤」→ 檢查碼必須等於 `MOD(前9碼, 7)`；用 `hctVerifyDelno` 預先驗證。
- 帶 `image` 一次超過 5 筆會導致部分筆無 image 或 SOAP message size 超限——拆批處理。
- SOAP message size：`maxReceivedMessageSize`（SOAP）/ `maxStringContentLength`（JSON）需調大（1 圖約 2 萬字元）。
- **逆物流回傳大寫 key**：`R_TransData_Json` 回 `Num/Success/Edelno/...`，務必正規化（見 §7）。
- **TransReport 第三參數名是 `dsCusJson`**（不是 `json`）；**TransDispatchOrder 回傳是 object 包 `rtn_data`**（不是直接陣列）——勿用統一解析邏輯硬套。

---

## 11. 官方 C# image function（V15 原文）

V15 第 11 節「C#將 image 字串轉回圖片 function」原文：

```csharp
// 字串轉 byte
byte[] GetBytes(string HexString)
{
    int byteLength = HexString.Length / 2;
    byte[] bytes = new byte[byteLength];
    string hex;
    int j = 0;
    for (int i = 0; i < bytes.Length; i++)
    {
        hex = new String(new Char[] { HexString[j], HexString[j + 1] });
        bytes[i] = HexToByte(hex);
        j = j + 2;
    }
    return bytes;
}

byte HexToByte(string hex)
{
    if (hex.Length > 2 || hex.Length <= 0)
        throw new ArgumentException("hex must be 1 or 2 characters in length");
    byte newByte = byte.Parse(hex, System.Globalization.NumberStyles.HexNumber);
    return newByte;
}

// byte 轉成圖片
Bitmap image(byte[] b)
{
    // 將 byte 讀進 MemoryStream
    MemoryStream ms = new MemoryStream(b);
    // 轉回成圖片
    Bitmap bmp = (Bitmap)Bitmap.FromStream(ms);
    BitmapData bmpData = bmp.LockBits(
        new Rectangle(0, 0, bmp.Width, bmp.Height),
        ImageLockMode.ReadOnly,
        PixelFormat.Format1bppIndexed);
    // 畫完圖，把圖轉為 1bit 索引色（黑白單色）
    bmp = new Bitmap(bmp.Width, bmp.Height, bmpData.Stride,
        PixelFormat.Format1bppIndexed, bmpData.Scan0);
    return bmp;
}
```
