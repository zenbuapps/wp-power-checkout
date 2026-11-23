# 其他 EDIEntrance 端點（店到店 / 客戶專屬 / 內部維運）

> **何時讀這份**：在 `EDIEntrance/Help` 看到不認識的端點、想判斷某端點能不能用，或嘉里大榮業務指定你走「店到店 / 超商代收 / 轉店」流程時。
> 一般電商出貨**不需要**這裡的任何端點；主流程見 `api-reference.md`。

全部端點的 schema 來自 `https://api.kerrytj.com/EDIEntrance/Help`（官方伺服器自動產生，2026-10-13），**所有欄位 Description 皆空、全標 `None.`**；下文「用途」一律為依名稱推得，未證實。

> 官方《客戶 API 串接規格書》v1.1.0（2025-03-19）**完全沒有收錄本檔任何端點**——它們不是給一般契約客戶用的。測試主機 `EDIEntranceTest` 也有同樣這些端點，同樣不要呼叫。

## 目錄

- [1. 店到店 / 超商相關（需大榮另行開通）](#1-店到店--超商相關需大榮另行開通)
- [2. 客戶專屬端點（不要使用）](#2-客戶專屬端點不要使用)
- [3. 內部維運端點（絕對不要呼叫）](#3-內部維運端點絕對不要呼叫)

---

## 1. 店到店 / 超商相關（需大榮另行開通）

### 1.1 StoreShipment/*（4 支，同一模型）

| 端點 | 推得用途 |
|---|---|
| `POST StoreShipment/HomeDelivery` | 門市出貨 → 宅配到府 |
| `POST StoreShipment/Transfer` | 門市間轉運（調撥） |
| `POST StoreShipment/CommodityReturn` | 門市商品退貨 |
| `POST StoreShipment/LogisticsBox` | 物流箱（周轉箱）運送 |

Body：`StoreShipmentRequestEntity[]`；Response：`ShipmentResponseEntity`（`{Result, Count, Data: ErrorBLN[]}`）。

`StoreShipmentRequestEntity` 欄位（全 string）：`ShipDate`、`OutStoreCode`（出貨門市）、`InStoreCode`（收貨門市）、`Piece`、`CON`、`Remark`、`PaymentStoreCode`（付款門市）、`AC`、`Consignee`、`ConsigneeAdd`、`ConsigneePhone`、`ConsigneePhone2`、`ConsigneePost`、`PaymentType`、`BLN`、`Volume`、`Weight`、`CustomerNo`、`Temperature`、`Batch`（批次）、`DataSource`（資料來源）。

推測服務對象是**連鎖門市型客戶**的店對店 / 門市對宅配，與一般電商 B2C 出貨無關。

### 1.2 TurnToStore/*（轉店取貨）

| 端點 | Body | Response |
|---|---|---|
| `POST TurnToStore/CreateOrder` | **單一** `ShipmentB2CRequestViewModel`：`BLN`、`StatusCode`、`StoreId`、`Phone`、`UploadSR`、`SuperMarkFlag` | `ApiResponseViewModel` = `{BLN, Status, Message}` |
| `POST TurnToStore/InsertPickup` | **單一** `RetrievePackageScanViewModel`：`BLN`、`StatusCode`、`UploadSR`、`SuperMarkFlag` | `ApiResponseViewModel` |
| `POST TurnToStore/RemoveOrder` | **單一** `RemoverOrderViewModel`：`BLN` | `ApiResponseViewModel` |

推得：宅配失敗 / 指定改至門市（`StoreId`）取貨的轉店流程；`UploadSR` 可能為上傳的司機（SR）代號、`SuperMarkFlag` 可能與超市 / 量販通路有關。對象與開通條件未知。

### 1.3 ConvenienceStore/ShipmentPayment

`POST ConvenienceStore/ShipmentPayment`；Body `ShipmentPaymentRequestEntity[]`；Response `IHttpActionResult`（無結構描述）。

欄位：`CustomerNo`(string)、`PickupNo`(string)、`ServiceType`(string)、`Account`(**integer**，推得為金額)、`TradeDescribe`(string，交易描述)、`CpRemark01`~`CpRemark03`(string)、`ShopperName`(string，購買人)。

推得：超商代收 / 超商繳費相關的款項資料上傳。嘉里大榮官網的公開服務**沒有**超商取貨，此端點應為特定合作專案用，不要自行使用。

---

## 2. 客戶專屬端點（不要使用）

端點名稱帶有特定企業名稱或欄位明顯為單一客戶客製，**不是通用介面**，列出只為辨識。

| 端點 | Body 模型 / 主要欄位 | Response | 辨識 |
|---|---|---|---|
| `POST PickupCarrefour` | `PickupEntity[]`（與 `PickupV2` 相同） | `ShipmentConResponseEntity` | 家樂福（Carrefour）專用取件 |
| `POST TestriteShipment` | `TestriteShipmentRequestEntity[]` = `ShipmentRequestEntity` 欄位 + `Floor`(integer，樓層) + `Items: [{ItemName, Packages}]` | `ShipmentResponseEntity` | 特力（Testrite）專用出貨（含樓層 / 品項） |
| `POST TestritePickup` | `TestritePickupRequestEntity[]` = `PickupEntity` 欄位 + `Floor` + `Items` | `ShipmentResponseEntity` | 特力專用取件 |
| `POST ShipmentYfyCpg` | `YfyCpgShipmentEntity[]` = `ShipmentRequestEntity` 欄位 + `Products: [{ProductId, Quantity}]` | `ShipResponse` | 永豐餘消費品（YFY CPG）專用 |
| `POST api/GAP/ShipmentOrder` | （無 body 定義） | `HttpResponseMessage` | GAP 專案 |
| `POST api/GAP/CancelOrder` | （無 body 定義） | `HttpResponseMessage` | GAP 專案 |
| `GET api/GAP/GetList?bolNo={bolNo}` | URI：`bolNo` | `GapShipmentBoxInformationEntity[]`：`BolNo`、`ShipperId`、`FromId`、`ToId`、`EcNo`、`LegNo`、`BoxNo`、`Piece`、`CreateDateTime`、`UpdateDateTime` | GAP 專案箱資訊 |
| `POST api/GAP/Update` | `GapShipmentBoxInformationEntity` | — | GAP 專案 |
| `POST api/GAP/Delete` | `GapShipmentBoxInformationEntity` | — | GAP 專案 |
| `POST api/Mirle/UpdateCubicStatus` | `CsMirleCubicFootEntity[]`：`BolNo`、`CubicFoot`(decimal)、`BolNoStatusId`、`CrtDate`、`IsReceivedCargo`(boolean) | `ShipmentResponseErrorCodeViewModel` = `{Result, Count, Data: [{BolNo, ErrorCode}]}` | 盟立（Mirle，自動化設備）材積量測回寫 |

> 這些模型可順帶看出嘉里大榮系統內部用語：`BolNo` = `BLN` = 託運單號；`CubicFoot` = 才數。

---

## 3. 內部維運端點（絕對不要呼叫）

| 端點 | 說明 |
|---|---|
| `GET DeliveryStation/RemoveNormalCache` | 清除常溫配送站快取（推得）——伺服器維運用 |
| `GET DeliveryStation/RemoveColdCache` | 清除低溫配送站快取（推得）——伺服器維運用 |

無參數、無回應模型。對正式主機呼叫可能影響大榮服務，**任何情況都不要呼叫**。
