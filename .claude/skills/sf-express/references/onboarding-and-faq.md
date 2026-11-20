# 接入流程、控制台操作、沙箱工具、MCP/Skill 與開發 FAQ

> 本檔由官方 portal（openapi-portal.sf.global，zh-CN 版）逐頁擷取後轉為繁體中文；參數名、代碼、JSON 範例保留原文。爬取日期 2026-09-23。表格內容為官方原表完整搬移，未摘要。

**何時讀這份**：第一次開通（註冊 → 開通物流服務 → 綁定客戶編碼 → 建立應用 → 關聯 API → 沙箱聯調 → 正式金鑰）、找沙箱測試憑證、判斷「自建系統 / 第三方 ERP / ERP 用戶」三種對接角色、或排查 FAQ 已知問題時。

未取得（需登入控制台 / 後台，官方 portal 只有截圖或連結，本 skill 未編造）：
- 控制台 `openapi.sf.global` 內的應用列表、在線報文調試工具、在線加解密工具、客戶編碼管理頁面實際畫面與欄位（文件只有截圖）。
- IUOP 商家後台 `iuop.sf-express.com` / `iuop.sf.global` 的開通服務流程與月結帳號綁定（需地區銷售在 ICRM 後台操作）。
- 推送聯調用的內部表單「軌跡推送信息收集」（DingTalk 內部連結）。
- 正式環境推送 `sign` key（聯調通過後由順豐提供）。

⚠️ 沙箱憑證是官方公開的共用測試帳號，**不可用於正式環境**，也不要把正式金鑰寫進程式碼 / git。

## 目錄

- [1. 對接類型指引](#1-對接類型指引)
- [2. 開發者對接學習路徑](#2-開發者對接學習路徑)
- [3. 第三方ERP對接學習路徑](#3-第三方erp對接學習路徑)
- [4. ERP用戶對接學習路徑](#4-erp用戶對接學習路徑)
- [5. 開發者對接指引](#5-開發者對接指引)
- [6. 第三方ERP對接指引](#6-第三方erp對接指引)
- [7. ERP用戶授權指引](#7-erp用戶授權指引)
- [8. 測試環境授權信息](#8-測試環境授權信息)
- [9. 生產環境授權信息獲取](#9-生產環境授權信息獲取)
- [10. 關聯API指引](#10-關聯api指引)
- [11. 通用指引](#11-通用指引)
- [12. 店小秘](#12-店小秘)
- [13. 店小秘](#13-店小秘)
- [14. 接入指南](#14-接入指南)
- [15. 在線報文調試工具](#15-在線報文調試工具)
- [16. 在線加解密工具](#16-在線加解密工具)
- [17. MCP Server](#17-mcp-server)
- [18. Skill](#18-skill)
- [19. 開發接入常見問題](#19-開發接入常見問題)

---

## 1. 對接類型指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=104（文檔中心 > 新手指引 > 對接類型指引；官方更新 2023-03-20；爬取 2026-09-23）

### 1\. 系統對接類型

* **ERP平台用戶對接**：客戶使用第三方ERP平台，且該平台已完成順豐國際服務集成，可以快速通過配置完成對接。
* **自建系統用戶對接**：指客戶有自己的業務系統，並具備研發能力將順豐國際IUOP服務集成到公司應用中，通過調用API完成業務請求。
* **ERP平台對接**：指第三方ERP平台把順豐國際服務集成到ERP平台，讓ERP平台客戶可以快速通過配置完成對接。

### 2\. ERP平台用戶對接指南

請參考ERP平台用戶對接通用指南（見 `onboarding-and-faq.md`）, 根據ERP平台對接順豐國際的實際接口獲取對應密鑰。

### 3\. 自建系統用戶對接指南

如果是個人開發者或公司自建系統，請參考開發者對接指引（見 `onboarding-and-faq.md`）；

### 4\. ERP系統對接指南
如果是第三方ERP平台，請參考第三方ERP對接指引（見 `onboarding-and-faq.md`）

---

## 2. 開發者對接學習路徑

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=194（文檔中心 > 新手指引 > 對接開放平台學習路徑 > 開發者對接學習路徑；官方更新 2024-04-25；爬取 2026-09-23）

### 1.瞭解

開發者接入主要是接入方通過使用API方式完成自有系統的對接。您可以在開放平台完成應用創建、在線調試，也可以在開放文檔獲取對接所需要了解的流程、接口、字段信息。

### 2.準備

1. 註冊帳號
2. 開通服務：
    1). 國際件物流服務：如果未開通物流服務，請跳轉到[[商家後台（國際統一訂單平台）](https://iuop.sf-express.com/#/)]開通相關的物流服務（快遞，電商和海外倉等）
    2). 海外本地件物流服務（目前只支持馬來西亞）：如果未開通物流服務，請跳轉到[[海外本地件商家後台](https://order.my.kex-express.com/#/)]開通相關的物流服務（快遞）
3. 開通完物流服務，請到[[控制台-客戶編碼管理頁面](https://openapi.sf.global/ManageCenterModule/CustIdManagement)]綁定開通服務對應的客戶編碼

### 3.應用創建

對接API，需在[[控制台-應用列表](https://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList)]創建應用獲取對接信息和關聯對接的API，參考文檔：開發者對接指引（見 `onboarding-and-faq.md`）

### 4.開發對接

1. 查看文檔[接入指南（見 `onboarding-and-faq.md`）]，瞭解對接流程
2. 先通過[Access Token接口（見 `auth-and-crypto.md`）]獲取token，注意token時效和失效機制
3. 得到token後，再通過加密生成簽名調用對應的業務接口，參考文檔：[業務接口調用（見 `auth-and-crypto.md`）]
4. 下載[對接加解密樣例代碼（見 `auth-and-crypto.md`）]
5. 沙箱環境調試信息：[調試授權信息（見 `onboarding-and-faq.md`）]

### 5.在線工具

在線工具還提供了：

1. 沙箱環境[[在線調試](https://openapi.sf.global/SandboxModule/OnlineDebugApi)]，此功能提供了API的樣例報文和可以生成對應的樣例代碼，參考文檔：[在線報文調試工具操作指南（見 `onboarding-and-faq.md`）]
2. [[在線加解密工具](https://openapi.sf.global/SandboxModule/OnlineEncryptTool)],協助您加解密報文，參考文檔：[在線加解密工具操作指南（見 `onboarding-and-faq.md`）]

---

## 3. 第三方ERP對接學習路徑

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=195（文檔中心 > 新手指引 > 對接開放平台學習路徑 > 第三方ERP對接學習路徑；官方更新 2024-03-29；爬取 2026-09-23）

### 1.瞭解

第三方ERP平台接入主要是接入方通過使用API方式完成自有系統的對接，並提供給平台的用戶進行使用。您可以在開放平台完成應用創建、在線調試，也可以在開放文檔獲取對接所需要了解的流程、接口、字段信息。

### 2.準備

1. 註冊帳號
2. 準備平台的信息和LOGO圖片，建議像素為100\*100

### 3.應用創建

對接API，需在[[控制台-應用列表](https://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList)]創建應用獲取對接信息和關聯對接的API，參考文檔：第三方ERP對接指引（見 `onboarding-and-faq.md`）

### 4.開發對接

1. 查看文檔[接入指南（見 `onboarding-and-faq.md`）]，瞭解對接流程
2. 先通過[Access Token接口（見 `auth-and-crypto.md`）]獲取token，注意token時效和失效機制
3. 第三方平台在發起業務接口時，請求頭上傳平台用戶提供的”授權碼“，參考文檔：[第三方ERP平台(ISV)接入指南（見 `auth-and-crypto.md`）]
4. 得到token後，再通過加密生成簽名調用對應的業務接口，參考文檔：[業務接口調用（見 `auth-and-crypto.md`）]
5. 下載[對接加解密樣例代碼（見 `auth-and-crypto.md`）]
6. 沙箱環境調試信息：[調試授權信息（見 `onboarding-and-faq.md`）]

### 5.在線工具

在線工具還提供了：

1. 沙箱環境[[在線調試](https://openapi.sf.global/SandboxModule/OnlineDebugApi)]，此功能提供了API的樣例報文和可以生成對應的樣例代碼，參考文檔：[在線報文調試工具操作指南（見 `onboarding-and-faq.md`）]
2. [[在線加解密工具](https://openapi.sf.global/SandboxModule/OnlineEncryptTool)],協助您加解密報文，參考文檔：[在線加解密工具操作指南（見 `onboarding-and-faq.md`）]

---

## 4. ERP用戶對接學習路徑

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=196（文檔中心 > 新手指引 > 對接開放平台學習路徑 > ERP用戶對接學習路徑；官方更新 2024-04-25；爬取 2026-09-23）

### 1.瞭解

ERP用戶對接，是準備使用第三方ERP軟件進行物流業務。您可以在開放平台完成應用創建、選擇可授權的第三方平台。

### 2.準備

1. 註冊帳號
2. 開通服務：
    1). 國際件物流服務：如果未開通物流服務，請跳轉到[[商家後台（國際統一訂單平台）](https://iuop.sf-express.com/#/)]開通相關的物流服務（快遞，電商和海外倉等）
    2). 海外本地件物流服務（目前只支持馬來西亞）：如果未開通物流服務，請跳轉到[[海外本地件商家後台](https://order.my.kex-express.com/#/)]開通相關的物流服務（快遞）
3. 開通完物流服務，請到[[控制台-客戶編碼管理頁面](https://openapi.sf.global/ManageCenterModule/CustIdManagement)]綁定開通服務對應的客戶編碼

### 3.應用創建

創建應用，需在[[控制台-應用列表](https://openapi.sf.global/IsvModule/IsvUserAppList)]創建應用獲取授權信息和選擇授權第三方平台(ERP)，提供得到的“授權碼”給第三方平台，參考文檔：ERP用戶對接指引（見 `onboarding-and-faq.md`）

---

## 5. 開發者對接指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=164（文檔中心 > 新手指引 > OAuth2認證接入指引 > 開發者對接指引；官方更新 2024-04-25；爬取 2026-09-23）

### 開發者對接指引

開發者接入主要是接入方通過使用API方式完成自有系統的對接。

### 接入條件

1. 有自己的業務系統
2. 並具備研發能力
3. 需要集成順豐國際服務

### 接入步驟

#### 步驟一: 登錄控制台

如果您已有開放平台或國際統一訂單平台帳號，可直接使用帳號密碼[登錄](https://openapi.sf.global/Home)。如果您是首次與順豐國際合作，可通過[註冊平台帳號](https://openapi.sf.global/Home)後登錄。

#### 步驟二: 選擇對接方式

根據自身的需求選擇對接方式，本指引是選擇開發者對接，如果未開通任何物流服務，請閱讀開發者對接學習路徑（見 `onboarding-and-faq.md`）根據文檔中的“準備”進行操作，已開通
（原文此處為截圖，未轉錄）
網頁右上角菜單切換角色，進行其他角色的對接
（原文此處為截圖，未轉錄）

#### 步驟三: 創建應用

點擊【新建應用】按鈕創建應用，如果內部有多套系統對接開放平台，推薦每套系統創建對應的應用

#### 1.填寫應用信息

（原文此處為截圖，未轉錄）

#### 2.完成應用創建

獲取對接信息（Key: appKey, 密鑰: appSecret，AES密鑰: aesKey）。後續如果需要停用或下線應用，請聯繫業務對接人員。
（原文此處為截圖，未轉錄）

#### 3.關聯API

當應用創建成功後，進入應用的API列表頁面點擊【關聯API】按鈕可選擇需要關聯到應用的服務接口。點擊【確認】按鈕將已選擇的服務接口添加到應用API列表。
（原文此處為截圖，未轉錄）

#### 步驟四: 聯調測試

可以通過在線調試工具獲取每個接口的樣例報文和請求代碼，協助您的系統對接
對接研發時可以參考接入指南文檔（見 `onboarding-and-faq.md`）和沙箱工具（見 `onboarding-and-faq.md`）
（原文此處為截圖，未轉錄）

---

## 6. 第三方ERP對接指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=165（文檔中心 > 新手指引 > OAuth2認證接入指引 > 第三方ERP對接指引；官方更新 2023-06-06；爬取 2026-09-23）

### 第三方ERP對接指引

第三方ERP平台接入主要是接入方通過使用API方式完成自有系統的對接，並提供給平台的用戶進行使用

### 接入條件

1. 有自己的ERP系統
2. 並具備研發能力
3. 需要集成順豐國際服務
4. 使用的用戶也是順豐國際的客戶

### 接入步驟

#### 步驟一: 登錄控制台

如果您已有開放平台帳號，可直接使用帳號密碼[登錄](https://openapi.sf.global/Home)。如果您是首次與順豐國際合作，可通過[註冊平台帳號](https://openapi.sf.global/Home)後登錄。

#### 步驟二: 選擇對接方式

根據自身的需求選擇對接方式，本指引是選擇第三方ERP對接對接
（原文此處為截圖，未轉錄）

#### 步驟三: 企業信息登記

填寫企業信息，上傳營業執照和平台信息
（原文此處為截圖，未轉錄）
可以通過網頁右上角的菜單進行企業信息查看或編輯，也可以切換角色，進行其他角色的對接
（原文此處為截圖，未轉錄）

#### 步驟四: 創建應用

點擊【新建應用】按鈕創建應用，如果內部有多套系統對接開放平台，推薦每套系統創建對應的應用

#### 1.填寫應用信息

（原文此處為截圖，未轉錄）

#### 2.完成應用創建

獲取對接信息（Key: appKey, 密鑰: appSecret，AES密鑰: aesKey）
（原文此處為截圖，未轉錄）

#### 3.關聯API

當應用創建成功後，進入應用的API列表頁面點擊【關聯API】按鈕可選擇需要關聯到應用的服務接口。點擊【確認】按鈕將已選擇的服務接口添加到應用API列表。
（原文此處為截圖，未轉錄）

#### 步驟五: 聯調測試

可以通過在線調試工具獲取每個接口的樣例報文和請求代碼，協助您的系統對接
對接研發時可以參考接入指南文檔（見 `onboarding-and-faq.md`），第三方平台接入文檔（見 `auth-and-crypto.md`）和沙箱工具（見 `onboarding-and-faq.md`）
（原文此處為截圖，未轉錄）

---

## 7. ERP用戶授權指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=166（文檔中心 > 新手指引 > OAuth2認證接入指引 > ERP用戶授權指引；官方更新 2024-04-25；爬取 2026-09-23）

### ERP用戶對接指引

ERP用戶對接，是準備使用第三方ERP軟件進行物流業務

### 接入條件

1. 期望通過快速對接順豐國際的服務
2. 使用第三方ERP軟件，且該軟件在平台合作的第三方軟件清單中

### 接入步驟

#### 步驟一: 登錄控制台

如果您已有開放平台或國際統一訂單平台帳號，可直接使用帳號密碼[登錄](https://openapi.sf.global/Home)。如果您是首次與順豐國際合作，可通過[註冊平台帳號](https://openapi.sf.global/Home)後登錄。

#### 步驟二: 選擇對接方式

根據自身的需求選擇對接方式，本指引是選擇ERP用戶對接，如果未開通任何物流服務，請閱讀ERP用戶對接學習路徑（見 `onboarding-and-faq.md`）根據文檔中的“準備”進行操作，已開通
（原文此處為截圖，未轉錄）
網頁右上角菜單切換角色，進行其他角色的對接
（原文此處為截圖，未轉錄）

#### 步驟三: 創建應用

點擊【+】按鈕創建應用，通過第三方ERP平台進行對接

#### 1.選擇所用的ERP

可以多選，同時授權給多個ERP平台
（原文此處為截圖，未轉錄）

#### 2.填寫應用信息

（原文此處為截圖，未轉錄）

#### 3.完成應用創建

獲取對接信息（授權碼）
（原文此處為截圖，未轉錄）

#### <br>

---

## 8. 測試環境授權信息

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=171（文檔中心 > 新手指引 > OAuth2認證接入指引 > 測試環境授權信息；官方更新 2026-02-26；爬取 2026-09-23）

### 測試環境授權秘鑰

| 字段 | 參數 |
| --- | --- |
| url | http://api-ifsp-sit.sf.global |
| appKey | cdd169d87a4698d314754a79f180308a |
| appSecret | 69fb3e868a45d0bdd5b0de0ea1db6ece |
| encodingAesKey | oI1YdU1cb1YC70HVjZRa3wXBLUsrIUYr5lDh0gFrMbe |
| **國際件API**CustomerCode | ICRME000SRN93 |
| **海外本地件API**CustomerCode | OSMYICRM-OSMY00009Z38|
| apiUsername (IECS出口電商業務必填) | popsandBox |
| **國際件API**月結卡號 | 9999999999 |
| **海外本地件API**月結卡號 | TESTJACK0004|

---

## 9. 生產環境授權信息獲取

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=172（文檔中心 > 新手指引 > OAuth2認證接入指引 > 生產環境授權信息獲取；官方更新 2023-05-29；爬取 2026-09-23）

### 獲取生產環境授權秘鑰

1\. 登錄IUOP，中國內地客戶使用 [https://iuop.sf-express.com](https://iuop.sf-express.com/) ，港澳台地區及海外客戶可使用[https://iuop.sf-express.com](https://iuop.sf-express.com/) 或 [https://iuop.sf.global/](https://iuop.sf.global/)
2\. 月結帳號需要聯繫地區銷售支持在ICRM後台綁定IUOP客戶帳號後方可使用，綁定後可以在IUOP首頁看到。
3\. 參考開發者對接指引（見 `onboarding-and-faq.md`），創建應用並關聯所需用到的接口

| 字段 | 參數/獲取方式 |
| --- | --- |
| url | https://api-ifsp.sf.global |
|token請求地址|https://api-ifsp.sf.global/openapi/api/token|
|業務接口請求地址|https://api-ifsp.sf.global/openapi/api/dispatch|
| appkey | 步驟3操作後，打開[應用列表](http://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList) - "詳情" - "應用詳情頁" - "生產Key"|
| APPsecret | 步驟3操作後，打開[應用列表](http://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList) - "詳情" - "應用詳情頁" - "生產密鑰" |
| encodingAesKey | 步驟3操作後，打開[應用列表](http://openapi.sf.global/OutSideAppModule/OutSideCustomerAppList) - "詳情" - "應用詳情頁" - "AES密鑰" |
| CustomerCode | 參考步驟1登陸 IUOP，"管理中心" - "基本信息" - "客戶編號"|
| apiUsername (IECS出口電商業務必填) | 參考步驟1登陸 IUOP，"管理中心" - "基本信息" - "用戶名" |
| 月結帳號 | 如已綁定至客戶編號，可在IUOP "首頁" - "月結帳號" 查看, 或諮詢順豐客戶經理|

---

## 10. 關聯API指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=181（文檔中心 > 新手指引 > OAuth2認證接入指引 > 關聯API指引；官方更新 2023-07-19；爬取 2026-09-23）

### 第一步：
    登錄 http://openapi.sf.global/ 頁面

### 第二步：
    選擇左側菜單欄的【應用列表】，然後選擇關聯API的應用【詳情】
（原文此處為截圖，未轉錄）

### 第三步
點擊【關聯API按鈕】，在彈出框中選擇需要關聯的API接口即可。（關聯成功後，如果存在狀態為【待審核】的，請聯繫客服對接人員）
（原文此處為截圖，未轉錄）

---

## 11. 通用指引

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=141（文檔中心 > 新手指引 > 第三方ERP授權指引 > 快遞業務 > 通用指引；官方更新 2023-10-08；爬取 2026-09-23）

### ERP平台用戶對接通用指南

目前順豐國際IUOP快遞業務主要有以下三種接入鑑權方式，需要分清鑑權方式獲取對應秘鑰進行填寫。如不清楚，請根據下面鑑權填寫信息鑑別或諮詢ERP平台客服。

| - | 摘要簽名認證1(大部分平台使用) | 摘要簽名認證2(少部分平台使用) | OAuth2認證(最新) |
| --- | ---------------- | ---------------- | ------------ |
| 鑑權填寫信息 | CustomerCode（CustomerID/客戶編碼/接入編碼）、checkWord（校驗碼/秘鑰/註冊碼） | CustomerCode、APPkey 、APPsecret | APPkey、APPsecret、encodingAesKey、CustomerCode |
| 請求地址 | https://osms.sf-express.com/osms/services/OrderWebService | https://iuop.sf.global/iuop-api/order/service | https://api-ifsp.sf.global |
| 平台側名稱 | 順豐國際IBS、順豐國際OSMS | - | 順豐國際Openapi |
| 備註 | 順豐國際舊接口，目前已切換至IUOP，使用舊接口將無感切換至IUOP平台 | IUOP下單接口 | Openapi接口 for IUOP |

### 數字簽名認證1秘鑰獲取

1. 如未註冊IUOP順豐國際下單帳號，請先註冊。中國內地客戶使用 [https://iuop.sf-express.com](https://iuop.sf-express.com/) ，港澳台地區及海外客戶可使用[https://iuop.sf-express.com](https://iuop.sf-express.com/) 或 [https://iuop.sf.global/](https://iuop.sf.global/)；
2. 聯繫您的客戶經理在後台為您綁定月結卡號到您註冊的IUOP帳號下，登陸IUOP後，可在“首頁” - “月結帳號” 下看到綁定的月結卡號；
3. **CustomerCode（客戶編碼/接入編碼）** ：在“管理中心” - “基本信息” 頁面下查看，客戶編號即為CustomerCode；
4. **checkWord（註冊碼/密鑰/檢驗碼）** : 聯繫您的客戶經理提交IT067-01 流程審批，完結後可在流程詳情中查看。

### 數字簽名認證2秘鑰獲取

1. 如未註冊IUOP順豐國際下單帳號，請先註冊。中國內地客戶使用 [https://iuop.sf-express.com](https://iuop.sf-express.com/) ，港澳台地區及海外客戶可使用[https://iuop.sf-express.com](https://iuop.sf-express.com/) 或 [https://iuop.sf.global/](https://iuop.sf.global/)；
2. 聯繫您的客戶經理在後台為您綁定月結卡號到您註冊的IUOP帳號下，登陸IUOP後，可在“首頁” - “月結帳戶” 下看到綁定的月結卡號；
3. **CustomerCode** ：登陸後在“管理中心” - “基本信息” 頁面下查看，客戶編號即為CustomerCode
4. **APPkey和APPsecret** : 聯繫您的客戶經理提交IT067-01內部流程獲取

### OAuth2認證秘鑰獲取

參考指引 OAuth2認證接入指引 - ERP用戶對接指引（見 `onboarding-and-faq.md`） 獲取密鑰後填寫即可
**注意：** 如果OAuth2認證客戶同時使用多個ERP平台下單，需要在IUOP為不同ERP系統申請多套秘鑰！

---

## 12. 店小秘

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=140（文檔中心 > 新手指引 > 第三方ERP授權指引 > 快遞業務 > 店小秘；官方更新 2023-05-10；爬取 2026-09-23）

店小秘使用**順豐國際IBS接口**與順豐國際下單系統對接，各接口詳情可參考這裡（見 `onboarding-and-faq.md`）。

### 授權秘鑰獲取
1.  如未註冊IUOP順豐國際下單帳號，請先註冊。中國內地客戶使用 [https://iuop.sf-express.com](https://iuop.sf-express.com/) ，港澳台地區及海外客戶可使用[https://iuop.sf-express.com](https://iuop.sf-express.com/)  或  [https://iuop.sf.global/](https://iuop.sf.global/)；
2.  聯繫您的客戶經理在後台為您綁定月結卡號到您註冊的IUOP帳號下，登陸IUOP後，可在“首頁” - “月結帳號” 下看到綁定的月結卡號；
3. **CustomerCode（客戶編碼）** ：在“我的資料” - “基本資料” 頁面下查看，客戶編號即為CustomerCode；
4. **checkWord（註冊碼/密鑰）** : 聯繫您的客戶經理提交IT067-01內部流程獲取。

### 授權密鑰填寫
1. 登陸店小秘，依次點擊“物流-選擇物流方式”，輸入“順豐國際”後點擊搜索，找到“順豐國際（快遞）”後點擊“添加授權”；
（原文此處為截圖，未轉錄）
2. 按照下圖填寫授權信息
（原文此處為截圖，未轉錄）
3. 授權後，找到需要使用的物流產品，如“國際特惠-包裹”，點擊“啟用” 後完善店小秘側的信息填寫即可
 （原文此處為截圖，未轉錄）
4. 更多店小秘側設置可參考[店小秘幫助手冊-順豐國際（快遞）授權方法](https://help.dianxiaomi.com/pre/getContent.htm?id=353)

---

## 13. 店小秘

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=162（文檔中心 > 新手指引 > 第三方ERP授權指引 > 出口電商業務 > 店小秘；官方更新 2023-09-06；爬取 2026-09-23）

### 授權秘鑰獲取

1. **用戶名（接入編碼）** ：登錄IUOP，在“管理中心” - “我的資料” - “基本資料” 頁面下查看，用戶名即為接入編碼；
2. **接口校驗碼** : 在“管理中心” - “我的資料” - “基本資料” 頁面下查看 “接口校驗碼”。

### 授權密鑰填寫

1. 登陸店小秘，依次點擊“物流-選擇物流方式”，輸入“順豐國際”後點擊搜索，找到“順豐國際跨境電商”後點擊“添加授權”；
    （原文此處為截圖，未轉錄）
2. 按照下圖填寫授權信息
    （原文此處為截圖，未轉錄）
3. 授權後，找到需要使用的物流產品，如“國際小包”，點擊“啟用” 後完善店小秘側的信息填寫即可
    （原文此處為截圖，未轉錄）

---

## 14. 接入指南

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=73（文檔中心 > 開發文檔 > 接入指南；官方更新 2023-05-26；爬取 2026-09-23）

### 1.獲取應用信息

參考此處（見 `onboarding-and-faq.md`）獲取測試環境以及生產環境對接授權信息

### 2.獲取Token

參考Access Token接口文檔（見 `auth-and-crypto.md`），調用獲取Access Token接口，獲取token
（原文此處為截圖，未轉錄）

### 3.生成密文和簽名

| 編程語言 | 加解密樣例代碼下載地址 |
| ---- | ----------- |
| Java | [https://storage.googleapis.com/ifsp-public/SampleCode/Java.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Java.zip) |
| Php | [https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip) |
| JS | [https://storage.googleapis.com/ifsp-public/SampleCode/JS.zip](https://storage.googleapis.com/ifsp-public/SampleCode/JS.zip) |
| C# | [https://storage.googleapis.com/ifsp-public/SampleCode/C%23.zip](https://storage.googleapis.com/ifsp-public/SampleCode/C%23.zip) |
| Go | [https://storage.googleapis.com/ifsp-public/SampleCode/Go.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Go.zip) |
| Python | [https://storage.googleapis.com/ifsp-public/SampleCode/Python.zip](https://storage.googleapis.com/ifsp-public/SampleCode/Python.zip) |

根據樣例代碼的demo生成請求報文的密文和簽名，下面以java代碼為例子：
（原文此處為截圖，未轉錄）
（原文此處為截圖，未轉錄）

### 4.請求業務接口

參考業務接口文檔（見 `auth-and-crypto.md`）
以下單為例：
（原文此處為截圖，未轉錄）
（原文此處為截圖，未轉錄）

### 5.解密

獲取密文後通過樣例代碼的方法進行解密
（原文此處為截圖，未轉錄）

---

## 15. 在線報文調試工具

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=176（文檔中心 > 開發文檔 > 沙箱工具 > 在線報文調試工具；官方更新 2023-06-09；爬取 2026-09-23）

### 在線報文調試工具

**入口**：
[http://openapi.sf.global/SandboxModule/OnlineDebugApi](http://openapi.sf.global/SandboxModule/OnlineDebugApi)

* 控制台-沙箱工具\在線報文調試工具
* 控制台"應用列表“中操作列點擊沙箱調試按鈕

這個功能可以幫助您瞭解如何對接順豐國際開放平台，主要提供了：

1. 聯調的信息，包含聯調的地址，應用信息和客戶信息以供研發聯調使用
2. 提供在線接口調試，根據您所選的接口，提供樣例報文，併發起調用
3. 根據所選的接口，還可以生成樣例代碼，支持Java，PHP，C#，Python，Go和JavaScript

### 聯調信息

代碼對接時，根據不同角色顯示環境信息不同，可以拿對應的信息進行聯調測試
**1、開發者對接**
（原文此處為截圖，未轉錄）
**2.第三方ERP對接**
（原文此處為截圖，未轉錄）

### 在線接口調試

這個部分結合上面的聯調信息和接口報文，在聯調測試環境模擬接口請求過程
截圖裡的1是您所建的應用列表，截圖裡的2是會分類展示你所選應用關聯的接口，截圖裡的3是展示你所選擇的接口的樣例報文
當點擊“發起調用”按鈕時，會對聯調測試環境發起請求，獲得對應的返回報文
（原文此處為截圖，未轉錄）

### 生成代碼

當選擇接口後，把鼠標移動到“三個點”會彈出菜單，點擊生成代碼
（原文此處為截圖，未轉錄）

會根據聯調信息，接口和報文生成請求或加密的樣例代碼，以供研發時作參考
（原文此處為截圖，未轉錄）

<br>

---

## 16. 在線加解密工具

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=178（文檔中心 > 開發文檔 > 沙箱工具 > 在線加解密工具；官方更新 2023-06-06；爬取 2026-09-23）

### 在線加解密工具

**入口**：控制台-沙箱工具\在線加解密工具
這個功能可以幫助您在線進行加解密操作，並幫您確認你的加解密是否正確，主要提供了：根據應用信息進行加解密，或者手工輸入App Key和AES Key進行加解密

### 加密

輸入請求對應的token，timestamp和nonce，點擊加密就能獲取，報文的密文和簽名
（原文此處為截圖，未轉錄）

#### 加密樣例代碼

點擊“加密樣例代碼”按鈕後，會根據你輸入信息生成樣例代碼作研發的參考，支持Java，PHP，C#，Python，Go和JavaScript
（原文此處為截圖，未轉錄）

### 解密

切換加密和解密開關，輸入密文，點擊解密就能獲取對應的明文（解密的App Key和AES密鑰需與加密一致）
（原文此處為截圖，未轉錄）

---

## 17. MCP Server

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=241（文檔中心 > 開發文檔 > MCP & Skill > MCP Server；官方更新 2026-04-03；爬取 2026-09-23）

> **本 skill 註（非官方原文）**：下方官方 MCP 設定 JSON 的 Windows 路徑反斜線未跳脫（如 `"x:\jdk-17.0.5\bin\java"`），直接貼上是**無效 JSON**；且 appKey / appSecret / aesKey 以 args 明碼傳入，會出現在程序列表與設定檔。實際使用請改用正斜線（或 `\\`）並以環境變數注入金鑰。本 skill 已涵蓋 token、加解密與 dispatch（`php-client.md`），一般**不需要**安裝官方 MCP / Skill；若要安裝第三方套件（jar / zip），安裝前先審查內容。

### 介紹

本平台提供MCP server，配置好MCP並啟用後，就可以直接向AI助手提問即可，AI 會根據你的問題自動調用工具

### 工具列表

| 工具 | 說明 |
| --- | --- |
| getToken | 獲取Token |
| encrypt | 加密，針對請求報文進行加密和生成簽名 |
| decrypt | 解密，針對密文進行解密 |
| dispatch | 調用業務接口 |

### 資源下載

| MCP類型 | 下載地址 |
| ----- | ---- |
| Java | [IOASPMCPServer-1.0-SNAPSHOT.jar](https://storage.googleapis.com/ifsp-public/AI/IOASPMCPServer-1.0-SNAPSHOT.jar) |
| Python | [mcp-py.zip](https://storage.googleapis.com/ifsp-public/AI/mcp-py.zip) |

### 配置

### Java

```
{
  "IOASP-MCP-PROD": {
    "command": "x:\jdk-17.0.5\bin\java",
    "args": [
      "-Dspring.ai.mcp.server.stdio=true",
      "-Dioasp.base.url=https://api-ifsp-sit.sf.global或https://api-ifsp.sf.global",
      "-Dioasp.appKey=申请的appkey或沙箱appkey",
      "-Dioasp.appSecret=申请的appSecret或沙箱appSecret",
      "-Dioasp.aesKey=申请的aesKey或沙箱aesKey",
      "-jar",
      "x:\IOASPMCPServer\IOASPMCPServer-1.0-SNAPSHOT.jar"
    ],
    "env": {}
  }
}
```

### Python

```
{
  "SFOpenAPIMCP": {
    "command": "python",
    "args": [
      "x:\mcp\ioasp_server.py",
      "--appKey",
      "申请的appkey或沙箱appkey",
      "--appSecret",
      "申请的appSecret或沙箱appSecret",
      "--aesKey",
      "申请的aesKey或沙箱aesKey",
      "--baseUrl",
      "https://api-ifsp-sit.sf.global或https://api-ifsp.sf.global"
    ],
    "env": {}
  }
}
```

### 對話

在對話前，在當前對話啟用MCP和選擇支持function call的模型

### 獲取token

```
獲取openapi token
```

### 發起業務請求

```
调用openapi dispatch接口，msgType：IUOP_CREATE_ORDER, token从上文获取，请求报文：{"abc":123}
```

### 解密

```
調用openapi解密
```

### 加密

```
调用openapi加密，token从上文获取，请求报文：{"abc":123}
```

### 多命令執行

```
调用openapi，步骤分为：
1.获取openapi token，
2.得到token后，调用openapi dispatch接口，msgType：IUOP_CREATE_ORDER，请求报文：{"abc":123}，token进行请求
3.请求成功后，对密文进行解密
```

（原文此處為截圖，未轉錄）

---

## 18. Skill

> 來源：https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=242（文檔中心 > 開發文檔 > MCP & Skill > Skill；官方更新 2026-04-03；爬取 2026-09-23）

### 介紹

本工具是順豐國際 OpenAPI 的智能對接助手，可自動生成集成代碼，更能幫助開發者排查對接過程中的各類問題。它支持Python、Java、JavaScript、PHP、C#、Go 六種編程語言，具備完整的 AES 加解密、簽名驗證、HTTP 請求組裝與響應解析能力，是對接順豐國際OpenAPI 的專業調試夥伴。

### 核心功能

* 完整的 AES 加密 / 解密集成
* 自動生成簽名驗證邏輯
* 標準化 HTTP 請求構建
* 生產級錯誤處理
* 詳細的代碼註釋和最佳實踐指導

### 支持的語言和框架

| 語言 | 版本 | HTTP 庫 | 加密庫 |
| --- | --- | ------ | --- |
| Python | 3.7+ | http.client | hashlib, struct |
| Java | 8+ | OkHttp3 | 內置 AES 實現 |
| JavaScript | ES6+ | request/fetch | crypto-js/Node.js crypto |
| PHP | 7+ | cURL | OpenSSL |
| C# | .NET Framework 4.6+ | HttpClient | System.Security.Cryptography |
| Go | 1.11+ | net/http | crypto/cipher, crypto/aes |

### 資源下載

下載地址: [sf-ibu-openapi-code.zip](https://storage.googleapis.com/ifsp-public/AI/sf-ibu-openapi-code.zip)

### 安裝方法

### 1.存放位置

| 級別 | 路徑 | 優先級 | 適用場景 |
| --- | --- | --- | ---- |
| 個人級 | C:\Users\用戶名.claude\skills\（原文缺反斜線；等同 `~/.claude/skills/`） | 高 | 個人偏好、常用工具 |
| 項目級 | 項目根目錄.claude\skills\（等同 `<專案根目錄>/.claude/skills/`） | 中 | 項目依賴、團隊共享 |

下載完成後解壓放入對應的目錄。

### 2.重啟Claude Code

```shell
### 輸入exit退出Claude Code。
exit
### 輸入claude進入Claude Code。
claude
### 然後輸入reload加載技能。
reload
```

### 3.驗證安裝

在Claude Code中輸入"/skills"命令驗證安裝

```shell
### 在Claude Code中輸入"/skills"命令驗證安裝
/skills
```

### 使用示例

#### 1.示例 代碼生成

輸入“/sf-ibu-openapi-code 生成java示例代碼”
agent輸入包含:

* 完整的Java實現
* Java項目所需依賴文件與文件說明、
* Maven 依賴配置
* 代碼核心功能
* 代碼工作流程介紹

#### 2.示例 代碼調試

輸入“/sf-ibu-openapi-code 【代碼塊】 調用接口提示Signature is invalid”
agent輸入包含:

* 錯誤原因
* 錯誤解決方法
* 代碼改進建議

#### 3.示例 代碼糾錯

輸入“/sf-ibu-openapi-code 【加密代碼塊】，加密過程報錯了， java.security.InvalidKeyException: Illegal key size”
agent輸入包含

* 錯誤原因
* 錯誤解決方法
* 代碼改進建議

---

## 19. 開發接入常見問題

> 來源：https://openapi-portal.sf.global/question?secondMenu=127（常見問題 > 開發接入常見問題；官方更新 2025-03-24；爬取 2026-09-23）

#### 常見問題

1. 【測試環境】下單接口注意字段：
    customerCode 需要傳客戶編碼，如：ICRME000SRN93
    apiUsername 需要傳用戶名，如：popsandBox

2. 電商類下單接口提示錯誤信息：沒有此用戶\|current user not supported\|用戶越權\|User ultra vires
    確認apiUsername是否有傳值，沙箱測試時，apiUsername字段應該是：popsandBox
    如果此字段有值，請檢查客戶編碼是否綁定，打開[鏈接](https://openapi.sf.global/IsvModule/IsvUserAppList)，管理中心-客戶編碼管理，綁定客戶，確認即可。
    （原文此處為截圖，未轉錄）
3. 接入IECS打印面單接口，若缺失打印模板，接口會報錯返回【生成面單失敗，請重試\|Failed to generate face sheet\, please try again】，找對接服務支持人員配置相關數據。
    
    <br>
4. 調用/openapi/api/dispatch接口若響應【system error】，確認Content-Type頭參數的值是否為：application/json
    
    <br>
5. 調用/openapi/api/dispatch接口若響應【簽名驗證錯誤】，請求體body只有密文的字符串，不需要組裝key-value的結構，具體請參考下圖，注意body不要自己添加換行符：
    （原文此處為截圖，未轉錄）
    
    <br>
6. 關於加解密過程中報錯 java.security.InvalidKeyException: Illegal key size的處理方案，請參考：
    方法A： https://blog.csdn.net/angellee1988/article/details/123588983
    方法B： https://www.shuzhiduo.com/A/LPdom4xOJ3/
    
    <br>
7. 關於加解密過程中報錯 java.lang.IllegalArgumentException: Last encoded character (before the paddings if any) is a
    valid base 64 alphabet but not a possible value. Expected the discarded bits to be zero. 的處理方案，請參考：
    https://blog.csdn.net/wujianmin577/article/details/108263498

#### 快遞業務和電商業務 對接應該使用哪個業務接口？

請先和您的順豐客戶經理確認所使用的順豐國際產品，如國際標快、國際特惠等等。
api文檔附錄-國際產品映射碼 中有具體說明產品屬於哪種業務。按照說明選擇快遞業務或者電商業務進行對接即可。
電商ERP平台建議兩個業務接口都進行對接。
**提示**：快遞業務和電商業務使用的接口編碼(msgType)是不一樣的，對接時請注意區分。

#### 測試環境測試正常，上線生產後提示“Access denied, token invalid”

測試環境為了方便起見，token都是固定的。
生產環境中申請的token有效期為2小時（請勿頻繁申請新token，建議過期前5-10分鐘前申請新token），且如果申請了新token，上一個token會失效。
**提示**：如果一個客戶有多套不同的系統需要用到順豐國際openapi的接口，解決方案有兩個：

1. 客戶在“API授權” 界面為不同的系統申請多套授權秘鑰；
2. 客戶僅申請一套秘鑰，需自行在不同的系統中同步最新的token，比如通過Redis緩存

#### 快遞業務的稅金支付方式

一般情況下是收方支付，產生稅金後會通知收方客戶繳納
如客戶有特殊要求（DDP），也可寄付/第三方月結支付，月結卡號需提前簽訂稅金協議。
如有疑問，可諮詢您的順豐客戶經理。

#### BBD業務是什麼？

BBD是指集運散派，將收件人不同，目的國相同的貨物打為大包，統一報關運輸，到目的國再分別派送到不同收件人。
普通客戶對接時無需理會，如有興趣，可聯繫你的順豐客戶經理。

#### 下國際標快產品時有國際標快和國際標快+兩種產品，應該怎麼選擇？

澳大利亞、印度尼西亞、印度、日本、柬埔寨、韓國、緬甸、蒙古、馬來西亞、新加坡、泰國、越南
收件國家在上述列表的使用國際標快，其餘海外國家用國際標快+
