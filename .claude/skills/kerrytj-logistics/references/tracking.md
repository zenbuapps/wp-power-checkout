# 貨況查詢（Tracking）與貨態代碼表

> **何時讀這份**：要查嘉里大榮託運單的配送狀態、解析 `CargoTracing`、把貨態代碼映射成自家訂單狀態，或要給消費者一個「查貨連結」時。

## 目錄

- [1. 路徑總覽](#1-路徑總覽)
- [2. 常溫貨況：POST BLNListTracing](#2-常溫貨況post-blnlisttracing)
- [3. 低溫貨況：POST ColdBLNListTracing](#3-低溫貨況post-coldblnlisttracing)
- [4. 貨態代碼表](#4-貨態代碼表)
- [5. 規格書未收錄：ShipmentTracking / PickupTracking](#5-規格書未收錄shipmenttracking--pickuptracking)
- [6. 官網查貨頁（給人看，不是 API）](#6-官網查貨頁給人看不是-api)
- [7. 已淘汰 / 不可用](#7-已淘汰--不可用)

---

## 1. 路徑總覽

| 路徑 | 端點 | 單號 | 認證 | 可信度 |
|---|---|---|---|---|
| **常溫貨況（多筆）** | `POST https://KerryTracingEDI.kerrytj.com/api/Tracing/BLNListTracing` | 11 碼 | Basic Auth | **官方規格** |
| **低溫貨況（多筆）** | `POST https://KerryTracingEDI.kerrytj.com/api/Tracing/ColdBLNListTracing` | 10 碼 | Basic Auth | **官方規格**；2026-10-29 實測存在（401） |
| 客編正 / 逆物流貨態 | `POST .../EDIEntrance/ShipmentTracking`、`PickupTracking` | 不帶單號 | body `CustomerCode` + `Flag` | 只有 Help Page，**規格書未收錄**（§5） |
| 官網查貨頁 | `https://www.kerrytj.com/zh/checking` | ≤ 5 筆 | 人工 | 不是 API（§6） |

- `KerryTracingEDI` 主機與建單的 `api.kerrytj.com` **不同**；規格書第 2 章 Postman 示範即以這台主機示範 Basic Auth，帳號表每個環境只有一組帳密 → **與 EDIEntrance 共用同一組帳密**（官方規格推得）。
- **規格書只給一組貨況網址，沒有測試網址**。測試環境建的單能否在這台查到：未知，聯調時確認。
- **溫層決定端點**：常溫單打 `BLNListTracing`、冷藏 / 冷凍單打 `ColdBLNListTracing`，**不可混送**（BLN 長度固定）。
- 沒有 webhook / 主動推播：一律**輪詢**。

---

## 2. 常溫貨況：POST BLNListTracing

```
POST https://KerryTracingEDI.kerrytj.com/api/Tracing/BLNListTracing
Authorization: Basic base64(帳號:密碼)
Content-Type: application/json

[ { "BLN": "40338575106" }, { "BLN": "40339001156" } ]
```

**Request**：陣列，每筆 `BLN` STRING(11)，**固定長度 11**。批次上限：規格書未寫（建議每次 ≤ 50 筆並觀察）。

**Response**：

| No | 欄位 | 中文 | 型別 | 說明 |
|---|---|---|---|---|
| 1 | `Status` | API 回傳狀態碼 | STRING(1) | **`1` 成功 / `0` 失敗** |
| 2 | `Error` | 錯誤訊息 | STRING(50) | 異常訊息 |
| 3 | `Result` | 回傳內容 | array | 正常貨況在此：`[{ BLN, CargoTracing[] }]` |

`Result[].CargoTracing[]`：

| No | 欄位 | 中文 | 型別 | 說明 |
|---|---|---|---|---|
| 1 | `BLN` | 託運單號 | STRING(11) | |
| 2 | `Station` | 處理站所 | STRING(8) | 例 `新店`、`南崁`；**可能缺此鍵**（官方範例有一筆沒有 `Station`） |
| 3 | `Date` | 處理日期 | STRING(8) | `yyyyMMdd` |
| 4 | `Time` | 處理時間 | STRING(12) | `HHmmss` |
| 5 | `StatusCode` | 配送狀態 ID | STRING(5) | 代碼見 §4 |
| 6 | `Status` | 配送狀態說明 | STRING(20) | 中文說明 |
| 7 | `StatusCodeType` | 配送狀態類型 | **int** | **`1` → 查常溫貨態表 1（§4.1，配送）；`2` → 查常溫貨態表 2（§4.2，取件 / 退貨）** |

官方範例（節錄，原文缺逗號已修正）：

```json
{
  "Status": "1",
  "Result": [
    {
      "BLN": "40330618040",
      "CargoTracing": [
        { "BLN": "40330618040", "Station": "新店", "Date": "20200530", "Time": "155211", "StatusCode": "30", "Status": "配送完成", "StatusCodeType": 1 },
        { "BLN": "40330618040", "Station": "新店", "Date": "20200530", "Time": "080451", "StatusCode": "74", "Status": "配送中", "StatusCodeType": 1 },
        { "BLN": "40330618040", "Station": "南崁", "Date": "20200529", "Time": "175519", "StatusCode": "49", "Status": "貨件已到發貨站所", "StatusCodeType": 1 }
      ]
    },
    {
      "BLN": "40175925277",
      "CargoTracing": [
        { "BLN": "40175925277", "Date": "20200527", "Time": "235959", "StatusCode": "78", "Status": "收件人已至門市取件", "StatusCodeType": 1 },
        { "BLN": "40175925277", "Station": "樹林", "Date": "20200526", "Time": "102817", "StatusCode": "02", "Status": "配送完成", "StatusCodeType": 1 }
      ]
    }
  ],
  "Error": ""
}
```

**解析重點**：

- `CargoTracing` 範例為**新 → 舊**排序，但不要依賴順序：自行以 `Date + Time` 排序取最新一筆。
- **判斷要用 `StatusCode` + `StatusCodeType`，不要比對 `Status` 文字**：官方範例 `74` 的 `Status` 是「配送中」，代碼表卻寫「共配配送」；`49` 範例是「貨件已到發貨站所」，代碼表寫「卸集貨」——**API 回的 `Status` 文字與代碼表不一定相同**。
- `StatusCode` 有英數混合（`A1`、`F0`），當字串處理；同一代碼在 type 1 / type 2 意義不同（例 `02`：type 1 = 配達；type 2 = 已取回退貨）。
- 規格書貨態表註：「**回傳貨況預設為當天貨況**」（原文；但範例含多天歷程，實際語意待聯調確認）。

---

## 3. 低溫貨況：POST ColdBLNListTracing

```
POST https://KerryTracingEDI.kerrytj.com/api/Tracing/ColdBLNListTracing
Authorization: Basic base64(帳號:密碼)
Content-Type: application/json

[ { "BLN": "6343634496" }, { "BLN": "6088958363" } ]
```

- Request：`BLN` STRING(10)，**固定長度 10**。
- Response：與 §2 相同結構（`Status` / `Error` / `Result[].CargoTracing[]`），但 `CargoTracing` **沒有 `StatusCodeType`**，`BLN` 為 10 碼；`StatusCode` 對照**低溫貨態表**（§4.3），`Status` = 低溫表的「EDI 描述」欄（範例：`91` → `配達`、`73` → `配貨攜出`、`58` → `到著點貨`、`22` → `貨件發送受付`）。
- 官方範例中**同一 `BLN` 在 `Result` 出現兩次**（`6088958363`）——以 `BLN` 為鍵合併、對 `CargoTracing` 去重（以 `Date+Time+StatusCode` 為鍵）。

---

## 4. 貨態代碼表

來源：規格書第八～十章（官方規格）。「建議映射」欄是本 skill 依說明文字整理的**建議分類（推得）**，供自家訂單狀態使用；官方英文說明見 `.tmp/[物流]嘉里大榮/spec-text/`。

建議映射值：`in_transit`（運送中）、`out_for_delivery`（配送中）、`delivered`（已送達 / 已取件，終態）、`ready_for_pickup`（待收件人自取）、`exception`（異常，需人工關注）、`returning`（退回中）、`returned`（已退回寄件人，終態）、`cancelled`、`info`（不影響狀態）。

### 4.1 常溫貨態表 1（`StatusCodeType: 1`，正物流配送）

| 代碼 | EDI 描述 | 建議中文 | 建議映射 |
|---|---|---|---|
| 00 | 客戶不在 | 收件人不在 | exception |
| 01 | 集貨 | 運輸商已接收貨件 | in_transit |
| **02** | 配達 | 配送完成 | **delivered** |
| 03 | 誤訂到著站 | 將重新安排配送站所 | in_transit |
| 04 | 訂正取消 | 取消託運資料修改 | info |
| 05 | 多欠件已收 | 收件人已收取部分貨件 | exception |
| 06 | 欠件拒收 | 收件人因缺件拒絕收件 | exception |
| 08 | 站止未領 | 運輸商配送至站所，收件人未領取 | ready_for_pickup |
| **09** | 站止領回 | 運輸商配送至站所，收件人已領取 | **delivered** |
| 10 | 客戶拒收 | 收件人拒絕收件 | exception |
| 11 | 破損拒收 | 收件人因貨件破損拒絕收件 | exception |
| 12 | 破損未配 | 運輸商因貨件破損未安排配送 | exception |
| 13 | 客戶他遷 | 收件人地址改變 | exception |
| 14 | 多件暫管 | 貨件保留在配送站所，待寄件人 / 收件人通知後配送 | exception |
| 15 | 扣押 | 貨件海關扣押中 | exception |
| 16 | 欠採購文號 | 因無採購單號，無法進行配送 | exception |
| 17 | 全欠 | 貨件全部未抵達配送站所 | exception |
| 18 | 貨件遺失 | 貨件遺失（官方英文欄誤植為 "Consignee didn't answer the call"） | exception |
| 19 | 另約時間配送 | 與收件人另約時間配送 | out_for_delivery |
| 22 | 指定收件人不在 | 指定收件人不在，無法完成配送 | exception |
| 24 | 客戶聯絡自領 | 收件人與運輸商約定至站所收取貨件 | ready_for_pickup |
| 25 | 節日休息節後送 | 節日後再安排配送 | in_transit |
| 26 | 客戶要求改址 | 寄件人 / 收件人要求更改收件地址 | in_transit |
| 29 | 地址錯誤 | 收件地址錯誤 | exception |
| **30** | 聯運配達 | 配送完成 | **delivered** |
| 31 | 聯運欠件轉出 | 雖件數有少，但已交由運輸商夥伴配送 | in_transit |
| 32 | 聯運欠件未轉 | 因件數有少，故尚未交由運輸商夥伴配送 | exception |
| 33 | 聯運多件暫管 | 已交由運輸商夥伴配送，現保存在站所內 | in_transit |
| 34 | 聯運破損未轉 | 因貨件破損，尚未交由運輸商夥伴配送 | exception |
| 35 | 聯運多欠件配達 | 收件人已收取運輸商夥伴配送之部分貨件 | exception |
| **36** | 聯運補轉且配達 | 收件人已收取運輸商夥伴配送之全部貨件 | **delivered** |
| 37 | 外包轉出 | 運輸商已將貨件轉交運輸商夥伴 | in_transit |
| **38** | 外包配達 | 配送完成 | **delivered** |
| 39 | 聯運未配 | 已交由運輸商夥伴配送，尚未配送 | in_transit |
| 40 | 無此收件人 | 無此收件人 | exception |
| 41 | 轉運積貨 | 轉運中心處理貨件中 | in_transit |
| 42 | 公司已停業 | 收件公司已停止營業 | exception |
| 43 | 事故貨件被扣 | 因事故發生，警方查扣貨件中 | exception |
| 45 | 轉運卸貨 | 轉運中心處理貨件中 | in_transit |
| 46 | 電聯無人接聽 | 聯繫收件人，但無人接聽 | exception |
| 49 | 卸集貨 | 運輸商處理貨件中 | in_transit |
| 50 | 送貨故倉 | 貨件破損，送至貨故管理中心 | exception |
| 51 | 海運積貨 | 運輸商海運夥伴收到貨件處理中 | in_transit |
| 52 | 空運積貨 | 運輸商空運夥伴收到貨件處理中 | in_transit |
| 53 | 退回原站 | 貨件退回發貨站所 | returning |
| 54 | 轉代收統倉 | 貨件交至配合統倉 | in_transit |
| 55 | 海運卸貨 | 運輸商海運夥伴收到貨件處理中 | in_transit |
| 56 | 空運卸貨 | 運輸商空運夥伴收到貨件處理中 | in_transit |
| 57 | 更送他站 | 運輸商改由其他配送站所配送 | in_transit |
| 58 | 退回原站 | 貨件退回發貨站所 | returning |
| 59 | 代收統倉入倉 | 貨件已由配合統倉驗收入倉 | in_transit |
| **62** | 欠件補送且配達 | 收件人已收取未配送之貨件 | **delivered** |
| 63 | 誤訂件數更正 | 託運件數更正 | info |
| 64 | 丟棄 | 無人認領，丟棄處置 | exception |
| 65 | 部分破損退原站 | 部分貨件破損並退回發貨站所 | returning |
| 66 | 過期處理 | 超過託運處理期限 | exception |
| 67 | 送回同業 | 運輸商同業轉交配送，但退回同業 | returning |
| 68 | 更正後訂正取消 | 託運資訊更正取消 | info |
| 69 | 部份拒收退回 | 收件人收取部分貨件並退回部分貨件 | exception |
| 72 | 送達門市 | 配送門市完成 | ready_for_pickup |
| 73 | 配送 | 司機配送中 | out_for_delivery |
| 74 | 共配配送 | 運輸商轉由子公司配送中 | out_for_delivery |
| 75 | 誤卸通報?刷碼 | 配送安排錯誤，將重新安排 | in_transit |
| 76 | 疊貨 | 司機安排配送路線中 | out_for_delivery |
| **77** | 送回寄件人 | 貨件送回寄件人 | **returned** |
| **78** | 代收門市取件 | 收件人已至門市取件 | **delivered** |
| 79 | 逾 7 日未取 | 收件人超過 7 天未至門市取件 | exception |
| 82 | 欠件 | 運輸商部分貨件有缺 | exception |
| 83 | 誤積誤訂轉出 | 運輸商重新安排配送中 | in_transit |
| 84 | 誤積誤訂轉入 | 運輸商重新安排配送中 | in_transit |
| 85 | 聯運到岸確認 | 運輸商夥伴確認貨件已到港口 | in_transit |
| 86 | 聯運到著點貨 | 貨件已到運輸商夥伴站所 | in_transit |
| 87 | 聯運轉出 | 運輸商已將貨件轉交運輸商夥伴 | in_transit |
| 88 | 聯運配送 | 貨件已由運輸商合作夥伴配送中 | out_for_delivery |
| 89 | 轉貨故處理 | 貨件破損，貨故處理中 | exception |
| 90 | 同業轉運卸貨 | 由運輸商夥伴轉運，貨件已到配送轉運中心 | in_transit |
| 91 | KTJ 發轉 KE | 運輸商將貨件轉由集團子公司接收 | in_transit |
| 92 | KTJ 到轉 KE | 運輸商將貨件轉由集團子公司配送 | in_transit |
| 93 | KE 卸貨 | 運輸商子公司嘉里快遞已收到貨件 | in_transit |
| 94 | 回 KTJ 自送 | 轉回運輸商配送 | in_transit |
| 95 | 同業轉回 | 運輸商夥伴轉回貨件 | in_transit |
| 96 | 同業站止未領回 | 收件人未至運輸商夥伴站所收件 | ready_for_pickup |
| 97 | 代收統倉驗退 | 貨件已由配合統倉驗退 | exception |
| 99 | 未配出 | 貨件處理中 | in_transit |
| A1 | 發送登錄 | 託運資料已輸入系統 | info |
| A2 | 到著點貨 | 貨件已到配送站所 | in_transit |
| A3 | 存庫盤點 | 貨件保存在運輸商站所 | in_transit |
| A4 | 無板點卸 | 貨件外部無託運標籤資訊 | exception |
| B1 | 放置完成 | 配送物流櫃完成 | ready_for_pickup |
| B2 | 逾期取回 | 收件人超過期限未至物流櫃取件 | exception |
| B3 | 強制取回 | 運輸商依需求取回物流櫃貨件 | exception |
| F0 | 全家預計進店 | 貨件預計轉交全家門市 | in_transit |
| F1 | 全家進店完成 | 貨件已交至全家門市 | ready_for_pickup |
| F2 | 全家逾期取回 | 收件人超過期限未至門市取件退回 | exception |
| F3 | 全家強制取回 | 運輸商依需求取回全家門市貨件 | exception |

（表中沒有 07、20、21、23、27、28、44、47、48、60、61、70、71、80、81、98，原文如此。）

### 4.2 常溫貨態表 2（`StatusCodeType: 2`，取件 / 退貨）

`PickupV2` 建立的取件單在 `BLNListTracing` 回傳時用這張表。

| 代碼 | EDI 描述 | 建議中文 | 建議映射 |
|---|---|---|---|
| 01 | 客戶不退 | 取消退貨 | cancelled |
| 02 | 已取回 | 運輸商已取回退貨 | in_transit（已取件） |
| 03 | 同業已取 | 運輸商夥伴已取回退貨 | in_transit（已取件） |
| 04 | 另約時間 | 與客戶另約時間取回退貨 | exception |
| 05 | 無貨可取 | 運輸商無退貨可取 | exception |
| 06 | 貨件太大（長+寬+高超過 300cm） | 貨件超過可取回規格 | exception |
| 07 | 連絡不到 | 聯繫不上客戶 | exception |
| 08 | 其他 | 其他狀況 | exception |
| 09 | 取件通知重複 | 退貨通知重複 | exception |
| 99 | 更改託運單號 | 更改託運單號 | info |
| **C1** | 配達結案 | 運輸商已完成退貨 | **delivered**（退貨已送回商家，終態） |
| C2 | 取消結案 | 待處理（原文如此；英文 "Cargo return request pending"） | cancelled（推得） |
| D1 | 未派車 | 已收到退貨需求，運輸商尚未處理 | info（待取件） |
| D2 | 派車 | 運輸商已通知司機取回 | info（待取件） |
| P1 | 取件中 | 運輸商取回貨件中 | out_for_delivery（取件中） |
| P2 | 集貨完成 | 運輸商已取回貨件 | in_transit（已取件） |

### 4.3 低溫貨態表（`ColdBLNListTracing`）

「官方分類」欄 = 規格書「更新貨況」欄，**是官方給的狀態分組，可直接用來映射**。

| 代碼 | EDI 描述（= API `Status`） | 官方分類 | 建議中文 | 建議映射 |
|---|---|---|---|---|
| 13 | 集貨站受付 | 司機已收到貨件 | 貨件資料交付站所 | in_transit |
| 15 | 搬運車積貨 | 轉運中 | 貨件上車做轉運 | in_transit |
| 22 | 貨件發送受付 | 司機已收到貨件 | 貨件資料交付站所 | in_transit |
| 38 | 貨件發送裝板 | 轉運中 | 貨件分配轉運籠子 | in_transit |
| 40 | 貨件發送裝車 | 轉運中 | 貨件上車做轉運 | in_transit |
| 41 | 低溫轉出 | 轉運中 | 轉聯運 | in_transit |
| 43 | 班車積貨 | 轉運中 | 貨件上車做轉運 | in_transit |
| 47 | 班車交接轉載 | 轉運中 | 貨件上車做轉運 | in_transit |
| 53 | 班車卸貨 | 轉運中 | 貨件已到發送站 | in_transit |
| 54 | 入站卸板 | 貨件已到發貨站所 | 運輸商已取回至站所 | in_transit |
| 58 | 到著點貨 | 貨件已到配送站所 | 貨件已到配送站所 | in_transit |
| 63 | 到著連繫卸貨 | 轉運中 | 貨件已到發送站 | in_transit |
| 71 | 指定配送 | 配送中（依指定時間安排） | 運輸商依指定需求配送 | out_for_delivery |
| 73 | 配貨攜出 | 配送中 | 運輸商配送中 | out_for_delivery |
| 8E | 欠採購文號 | 配送未完成（文件待補） | 因無採購單號，無法進行配送 | exception |
| 80 | 客戶拒收 | 配送未完成（客戶拒收） | 收件人拒絕收取貨件 | exception |
| 81 | 客戶不在 | 配送未完成（收件人不在） | 收件人不在 | exception |
| 82 | 全欠 | 異常處理中 | 貨件全部未抵達配送站所 | exception |
| 83 | 地址不明 | 配送未完成（收件地址錯誤） | 收件地址不明 | exception |
| 84 | 站止未領 | 配送未完成（未到站所領貨） | 運輸商配送至站所，收件人未領取 | ready_for_pickup |
| 85 | 破損未配 | 退貨處理中 | 運輸商因貨件破損未安排配送 | returning |
| 86 | 多欠件已收 | 收件人已收取部分貨件 | 收件人已收取部分貨件 | exception |
| 87 | 客戶他遷 | 配送未完成（收件地址變更） | 收件人地址改變 | exception |
| 88 | 查無此人 | 配送未完成（無此收件人） | 無此收件人 | exception |
| 89 | 電聯無人 | 配送未完成（電聯無人接聽） | 聯繫收件人，但無人接聽 | exception |
| **90** | 破損已收 | **配送完成** | 雖然貨件破損，但收件人已收 | **delivered** |
| **91** | 配達 | **配送完成** | 配送完成 | **delivered** |
| **92** | 自領 | **配送完成** | 運輸商配送至站所，收件人已領取 | **delivered** |
| 93 | 貨件退回 | 退貨處理中 | 貨件退回寄件人 | returning |
| 94 | 轉聯運 | 轉運中 | 運輸商已將貨件轉交運輸商夥伴 | in_transit |
| 95 | 誤訂轉出 | 轉運中 | 運輸商重新安排配送中 | in_transit |
| 96 | 貨故處理中 | 異常處理中 | 貨件破損，貨故處理中 | exception |
| 97 | 貨故己處理 | 異常處理中 | 貨故已處理完成 | exception |
| 99 | 處理中 | 此為內部貨況，**不呈現在官網或 EDI 上** | 貨件處理中 | in_transit |

### 4.4 映射原則

- **同一代碼在三張表意義不同**（例 `91`：常溫表 1 = KTJ 發轉 KE；低溫 = 配達）→ 映射函式的 key 要是 `(端點/溫層, StatusCodeType, StatusCode)` 三元組。
- 未知代碼 → 保留 raw、映射為 `info`，不要硬猜成 delivered。
- `exception` 類不一定是終態（例 `00 客戶不在` 之後常會再配送）——只在 `delivered` / `returned` 停止輪詢。

---

## 5. 規格書未收錄：ShipmentTracking / PickupTracking

Help Page 有 `POST EDIEntrance/ShipmentTracking`（正物流）與 `POST EDIEntrance/PickupTracking`（逆物流），**規格書 v1.1.0 沒有收錄**，貨況查詢請用 §2 / §3。以下僅供辨識：

- Body（單一物件）：`{ "CustomerCode": "<客編>", "Flag": "<驗證碼>" }`，兩者皆 Required；**不帶單號**，依客編拉資料。
- 錯誤以 **HTTP 200 + `{"IsSuccess":false,"Error":"<中文>","Data":[]}`** 回傳（實測：`客戶編號為必填`、`驗證碼為必填`）。
- Response：`ShipmentTracking` → `Data: [{ BLN, CustomerCode, ShipDate, DeliverStatus, DeliverDate, DeliverTime, DSD, AC, CON }]`；`PickupTracking` → `Data: [{ BLN, PickupNo, ESNA, Date, Time, CustomerNo, StatusCode, Status, Piece }]`。
- `Flag` 產生規則未知（唯一線索：2019 年已下線的 `CommunityPickup` 服務用 `md5(CustomerNo + yyyyMMdd)`，未證實）。**不要在正式環境試算法**。

---

## 6. 官網查貨頁（給人看，不是 API）

| 項目 | 內容 | 來源 |
|---|---|---|
| 給消費者的查貨入口 | `https://www.kerrytj.com/zh/checking`（**無法帶單號 deep link**：單號存在 sessionStorage，且需先勾選同意法律聲明） | 官方（前端程式碼） |
| 單次筆數 | 最多 5 筆 | 官方（前端） |
| 兩種類型 | 「路線」（常溫）/「低溫零擔」 | 官方（前端） |
| 保留期限 | 網路查貨僅保留**寄貨日 + 75 天** | 官方營運（FAQ） |
| 簽收單 | 契約客戶可於官網下載簽收影像；保留 1 年 | 官方營運（FAQ） |

官網背後的 `www.kerrytj.com/api/Tracking/GetTracking` 是官網自用端點，**不是對外 API，不要呼叫或爬取**。訂單頁給消費者「單號 + 前往 `https://www.kerrytj.com/zh/checking` 自行輸入」即可；系統內貨態同步走 §2 / §3。

---

## 7. 已淘汰 / 不可用

| 項目 | 狀態 |
|---|---|
| `http://KerryTracingEDI.kerrytj.com/api/Tracing/BLNTracing`（單筆，規格書第 2 章 Postman 截圖中的舊路徑） | 規格書正式章節已改用 `BLNListTracing`（多筆）；新程式不要用 |
| `https://www.kerrytj.com/ZH/search/search.aspx?qry={單號}`（舊查貨 deep link） | 實測 400，已失效 |
| `http://www.kerrytj.com/zh/search/search_track.aspx` | 實測 400，已失效 |
| `http://api.kerrytj.com/CommunityPickup/api/Request/*`（2019 社區取件） | 實測 503，已下線 |
