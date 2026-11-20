# 來源頁面清單與爬取紀錄（2026-09-23）

> **何時讀這份**：要回溯某段內容的官方出處、確認官方頁面更新日期是否比本 skill 新、或查哪些頁面沒收錄 / 需要登入時。本檔不含 API 規格本身。

## 目錄

- [1. 爬取方式](#1-爬取方式)
- [2. 文件頁清單（選單 117 頁 + 首頁連入 1 頁）](#2-文件頁清單選單-117-頁--首頁連入-1-頁)
- [3. 額外取得的官方檔案](#3-額外取得的官方檔案)
- [4. 未取得 / 需登入 / 不存在 / 隱藏頁](#4-未取得--需登入--不存在--隱藏頁)

---

## 1. 爬取方式

- 起點：`https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=77`（SPA）。以 agent-browser 開啟並渲染頁面後，於瀏覽器內呼叫 portal 自身使用的公開文件 API：
  - 選單樹：`GET /openapi/console/noLogin/online/doc/menu/list`
  - 單頁：`GET /openapi/console/noLogin/online/doc/{secondMenu}/{lang}` → 回傳帶簽名的 Markdown 檔 URL（同源代理 `/openapi-prod/apiDoc/onlineDoc/...`）→ 取回 Markdown 原文。
- 選單共三大類：**文檔中心**（新手指引 / 開發文檔 / API 文檔）與**常見問題**；API 文檔下有「國際件API」「海外本地件API」「國際區劃」「附錄」。葉節點共 **117 頁，全部取得 zh-CN 原文**；另收錄 **1 頁首頁卡片連入、不在選單的第 84 頁 `IUOP_QUERY_TRACK`**。英文版：選單頁取得 99 頁（其餘 18 頁無英文版），第 84 頁亦有英文版，用於交叉比對（僅路由查詢業務碼有實質差異，已補在 `tracking.md`）。
- 語言：portal 只提供 `zh-CN` 與 `en`；`zh-HK` / `zh-TW` 文件不存在（API 回空）。本 skill 以 zh-CN 為主體轉繁體。
- 截圖：原文中的 base64 截圖（控制台操作畫面、Postman 範例）未轉錄，已以「（原文此處為截圖，未轉錄）」標示。

## 2. 文件頁清單（選單 117 頁 + 首頁連入 1 頁）

| id | 頁面路徑 | msgType | URL | 官方更新（zh-CN） | 英文版 | 收錄位置 |
|---|---|---|---|---|---|---|
| 104 | 新手指引 > 對接類型指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=104 | 2023-03-20 | en 2023-05-29 | `onboarding-and-faq.md` |
| 194 | 新手指引 > 對接開放平台學習路徑 > 開發者對接學習路徑 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=194 | 2024-04-25 | en 2024-04-25 | `onboarding-and-faq.md` |
| 195 | 新手指引 > 對接開放平台學習路徑 > 第三方ERP對接學習路徑 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=195 | 2024-03-29 | en 2024-04-03 | `onboarding-and-faq.md` |
| 196 | 新手指引 > 對接開放平台學習路徑 > ERP用戶對接學習路徑 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=196 | 2024-04-25 | en 2024-04-25 | `onboarding-and-faq.md` |
| 141 | 新手指引 > 第三方ERP授權指引 > 快遞業務 > 通用指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=141 | 2023-10-08 | en 2023-03-21 | `onboarding-and-faq.md` |
| 140 | 新手指引 > 第三方ERP授權指引 > 快遞業務 > 店小秘 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=140 | 2023-05-10 | en 2022-11-22 | `onboarding-and-faq.md` |
| 162 | 新手指引 > 第三方ERP授權指引 > 出口電商業務 > 店小秘 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=162 | 2023-09-06 | 無英文版 | `onboarding-and-faq.md` |
| 164 | 新手指引 > OAuth2認證接入指引 > 開發者對接指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=164 | 2024-04-25 | en 2024-04-25 | `onboarding-and-faq.md` |
| 165 | 新手指引 > OAuth2認證接入指引 > 第三方ERP對接指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=165 | 2023-06-06 | en 2023-06-06 | `onboarding-and-faq.md` |
| 166 | 新手指引 > OAuth2認證接入指引 > ERP用戶授權指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=166 | 2024-04-25 | en 2024-04-25 | `onboarding-and-faq.md` |
| 171 | 新手指引 > OAuth2認證接入指引 > 測試環境授權信息 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=171 | 2026-02-26 | en 2026-02-26 | `onboarding-and-faq.md` |
| 172 | 新手指引 > OAuth2認證接入指引 > 生產環境授權信息獲取 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=172 | 2023-05-29 | en 2023-05-29 | `onboarding-and-faq.md` |
| 181 | 新手指引 > OAuth2認證接入指引 > 關聯API指引 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=181 | 2023-07-19 | en 2023-07-20 | `onboarding-and-faq.md` |
| 73 | 開發文檔 > 接入指南 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=73 | 2023-05-26 | en 2022-11-22 | `onboarding-and-faq.md` |
| 75 | 開發文檔 > Access Token接口 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=75 | 2023-05-17 | en 2023-12-29 | `auth-and-crypto.md` |
| 76 | 開發文檔 > 業務接口 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=76 | 2024-12-03 | en 2025-05-20 | `auth-and-crypto.md` |
| 144 | 開發文檔 > 第三方ERP平台(ISV)接入 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=144 | 2024-03-29 | en 2024-03-29 | `auth-and-crypto.md` |
| 106 | 開發文檔 > 加解密樣例代碼 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=106 | 2022-11-28 | en 2025-11-27 | `auth-and-crypto.md` |
| 176 | 開發文檔 > 沙箱工具 > 在線報文調試工具 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=176 | 2023-06-09 | en 2024-01-23 | `onboarding-and-faq.md` |
| 178 | 開發文檔 > 沙箱工具 > 在線加解密工具 |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=178 | 2023-06-06 | en 2023-06-06 | `onboarding-and-faq.md` |
| 241 | 開發文檔 > MCP & Skill > MCP Server |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=241 | 2026-04-03 | en 2026-04-03 | `onboarding-and-faq.md` |
| 242 | 開發文檔 > MCP & Skill > Skill |  | https://openapi-portal.sf.global/document?firstMenu=DOC&secondMenu=242 | 2026-04-03 | en 2026-04-03 | `onboarding-and-faq.md` |
| 97 | API文檔 > 國際件API > 出口電商業務場景接入 > 創建物流訂單 | `IECS_CREATE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=97 | 2026-09-21 | en 2026-09-19 | `iecs-ecommerce.md` |
| 98 | API文檔 > 國際件API > 出口電商業務場景接入 > 獲取發貨面單 | `IECS_PRINT_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=98 | 2025-10-20 | en 2025-10-20 | `iecs-ecommerce.md` |
| 109 | API文檔 > 國際件API > 出口電商業務場景接入 > 導入包票關係 | `IECS_IMPORT_PACKAGE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=109 | 2024-10-21 | en 2024-10-21 | `iecs-ecommerce.md` |
| 110 | API文檔 > 國際件API > 出口電商業務場景接入 > 重量及運費查詢 | `IECS_QUERY_WEIGHT_FREIGHT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=110 | 2026-01-08 | en 2023-12-29 | `iecs-ecommerce.md` |
| 111 | API文檔 > 國際件API > 出口電商業務場景接入 > 小包上傳重量 | `IECS_UPLOAD_WEIGHT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=111 | 2025-01-14 | en 2025-01-14 | `iecs-ecommerce.md` |
| 112 | API文檔 > 國際件API > 出口電商業務場景接入 > 服務商單號查詢 | `IECS_QUERY_AGENTNO` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=112 | 2026-04-16 | en 2026-04-16 | `iecs-ecommerce.md` |
| 191 | API文檔 > 國際件API > 出口電商業務場景接入 > 取消電商訂單 | `IECS_CANCEL_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=191 | 2024-10-21 | en 2024-10-21 | `iecs-ecommerce.md` |
| 179 | API文檔 > 國際件API > 出口電商業務場景接入 > 訂單攔截 | `IECS_ORDER_INTERCEPT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=179 | 2024-10-21 | en 2024-10-21 | `iecs-ecommerce.md` |
| 206 | API文檔 > 國際件API > 出口電商業務場景接入 > 電商發貨/簽收證明 | `IECS_QUERY_POD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=206 | 2024-05-30 | en 2024-05-30 | `iecs-ecommerce.md` |
| 222 | API文檔 > 國際件API > 出口電商業務場景接入 > 尾程代理傳輸 | `IECS_AGENT_TRANS` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=222 | 2025-06-11 | 無英文版 | `iecs-ecommerce.md` |
| 228 | API文檔 > 國際件API > 出口電商業務場景接入 > 大包攬收-大小包關係綁定 | `IECS_BIG_PACKAGE_BIND` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=228 | 2025-10-11 | 無英文版 | `iecs-ecommerce.md` |
| 77 | API文檔 > 國際件API > 國際快遞業務場景接入 > 創建物流訂單 | `IUOP_CREATE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=77 | 2026-09-19 | en 2026-09-19 | `iuop-create-order.md` |
| 78 | API文檔 > 國際件API > 國際快遞業務場景接入 > 查詢物流訂單 | `IUOP_QUERY_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=78 | 2023-08-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 79 | API文檔 > 國際件API > 國際快遞業務場景接入 > 獲取發貨面單 | `IUOP_PRINT_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=79 | 2026-03-10 | en 2023-12-29 | `iuop-order-ops.md` |
| 80 | API文檔 > 國際件API > 國際快遞業務場景接入 > 取消物流訂單 | `IUOP_CANCEL_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=80 | 2023-08-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 81 | API文檔 > 國際件API > 國際快遞業務場景接入 > 上傳清關資料 | `IUOP_UPLOAD_CERTIFY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=81 | 2025-05-19 | en 2025-05-19 | `iuop-order-ops.md` |
| 82 | API文檔 > 國際件API > 國際快遞業務場景接入 > 清關資料是否有效查詢 | `IUOP_VALIDITY_CERTIFY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=82 | 2024-06-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 83 | API文檔 > 國際件API > 國際快遞業務場景接入 > 上傳清關資料要求查詢 | `IUOP_REQUIRE_CERTIFY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=83 | 2023-08-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 85 | API文檔 > 國際件API > 國際快遞業務場景接入 > 預估運費 | `IUOP_QUERY_FREIGHT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=85 | 2026-06-09 | en 2026-06-09 | `iuop-order-ops.md` |
| 87 | API文檔 > 國際件API > 國際快遞業務場景接入 > 創建物流訂單【信任重量】 | `IUOP_CREATE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=87 | 2026-01-19 | en 2024-08-27 | `iuop-order-variants.md` |
| 89 | API文檔 > 國際件API > 國際快遞業務場景接入 > 異步打印面單 | `IUOP_ASYN_PRINT_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=89 | 2026-04-10 | en 2026-04-10 | `iuop-order-ops.md` |
| 90 | API文檔 > 國際件API > 國際快遞業務場景接入 > 異步打印面單結果查詢 | `IUOP_ASYN_PRINT_RESULT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=90 | 2023-08-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 129 | API文檔 > 國際件API > 國際快遞業務場景接入 > 預估總費用 | `IUOP_ESTIMATE_FEE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=129 | 2024-07-03 | en 2023-12-29 | `iuop-order-ops.md` |
| 88 | API文檔 > 國際件API > 國際快遞業務場景接入 > BBD預合單 | `IUOP_BBD_PRE_MERGE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=88 | 2023-08-17 | en 2023-12-29 | `iuop-order-ops.md` |
| 185 | API文檔 > 國際件API > 國際快遞業務場景接入 > BBD合單結果查詢 | `IUOP_BBD_MERGE_RESULT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=185 | 2024-06-14 | en 2023-10-19 | `iuop-order-ops.md` |
| 192 | API文檔 > 國際件API > 國際快遞業務場景接入 > 取消BBD預合單 | `IUOP_CANCEL_BBD_MERGE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=192 | 2024-06-14 | en 2023-11-29 | `iuop-order-ops.md` |
| 210 | API文檔 > 國際件API > 國際快遞業務場景接入 > 預創建物流訂單 | `IUOP_PRE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=210 | 2026-01-12 | en 2026-01-12 | `iuop-order-variants.md` |
| 217 | API文檔 > 國際件API > 國際快遞業務場景接入 > 快遞櫃查詢 | `IOMS_QUERY_LOCKER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=217 | 2025-02-28 | en 2025-02-28 | `iuop-order-ops.md` |
| 232 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 創建訂單 | `IFTP_CREATE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=232 | 2026-06-26 | 無英文版 | `iftp-cross-border-transport.md` |
| 233 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 查詢訂單 | `IFTP_QUERY_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=233 | 2025-11-24 | 無英文版 | `iftp-cross-border-transport.md` |
| 234 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 獲取面單 | `IFTP_PRINT_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=234 | 2025-11-24 | 無英文版 | `iftp-cross-border-transport.md` |
| 235 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 取消訂單 | `IFTP_CANCEL_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=235 | 2025-11-24 | 無英文版 | `iftp-cross-border-transport.md` |
| 236 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 上傳清關資料 | `IFTP_UPLOAD_CERTIFY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=236 | 2025-11-24 | 無英文版 | `iftp-cross-border-transport.md` |
| 237 | API文檔 > 國際件API > 跨境運輸業務場景接入 > GPS軌跡接收 | `SF_IFTP_RECEIVE_GPS_TRACK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=237 | 2026-01-15 | 無英文版 | `iftp-cross-border-transport.md` |
| 238 | API文檔 > 國際件API > 跨境運輸業務場景接入 > 運費明細接收 | `SF_IFTP_RECEIVE_COST_DETAILS` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=238 | 2026-01-15 | 無英文版 | `iftp-cross-border-transport.md` |
| 145 | API文檔 > 國際件API > 通用 > 路由查詢 | `GTS_QUERY_TRACK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=145 | 2026-07-15 | en 2025-11-24 | `tracking.md` |
| 225 | API文檔 > 國際件API > 通用 > 通用路由訂閱 | `GTS_COM_ROUTE_SUBSCRIBE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=225 | 2026-07-08 | 無英文版 | `tracking.md` |
| 124 | API文檔 > 國際件API > 通用 > 單票派送證明查詢 | `IOMS_SEND_PROVE_ONEFILE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=124 | 2026-04-28 | en 2023-12-29 | `common-apis.md` |
| 86 | API文檔 > 國際件API > 通用 > 批量派送證明查詢 | `IOMS_SEND_PROVE_CREATE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=86 | 2026-04-28 | en 2025-11-13 | `common-apis.md` |
| 133 | API文檔 > 國際件API > 通用 > 計費重和費用項查詢 | `IOMS_QUERY_CHARGE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=133 | 2026-04-03 | en 2026-04-03 | `common-apis.md` |
| 168 | API文檔 > 國際件API > 通用 > 尾程供應商派送證明查詢 | `ADES_AGENT_QUERY_POD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=168 | 2026-07-15 | en 2026-07-15 | `common-apis.md` |
| 173 | API文檔 > 國際件API > 通用 > 獲取稅金信息及支付渠道 | `IOMS_TAX_PAYMENT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=173 | 2023-08-17 | en 2023-06-06 | `common-apis.md` |
| 174 | API文檔 > 國際件API > 通用 > 獲取稅金支付鏈接 | `IOMS_TAX_PAYMENT_LINK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=174 | 2023-08-17 | en 2023-06-06 | `common-apis.md` |
| 182 | API文檔 > 國際件API > 通用 > 獲取在線支付短鏈接 | `IOMS_PAYMENT_LINK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=182 | 2023-08-17 | en 2023-12-29 | `common-apis.md` |
| 183 | API文檔 > 國際件API > 通用 > 派送證明上傳 | `ADES_UPLOAD_POD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=183 | 2025-03-03 | en 2023-09-26 | `common-apis.md` |
| 184 | API文檔 > 國際件API > 通用 > 操作運單費用明細查詢 | `IOMS_WAYBILL_BILL` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=184 | 2024-04-19 | en 2024-04-19 | `common-apis.md` |
| 197 | API文檔 > 國際件API > 通用 > 中國海關稅金信息查詢 | `ICSM_CN_TAX_QUERY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=197 | 2024-04-08 | en 2024-04-03 | `common-apis.md` |
| 211 | API文檔 > 國際件API > 通用 > 代理商更新運單 | `ADES_AGENT_UPDATE_WAYBILL` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=211 | 2024-10-14 | en 2024-10-14 | `common-apis.md` |
| 212 | API文檔 > 國際件API > 通用 > 國際收寄標準 | `SOP_COLLECT_SHIP_STANDARD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=212 | 2024-12-05 | 無英文版 | `common-apis.md` |
| 213 | API文檔 > 國際件API > 通用 > 產品運費時效推薦接口 | `IPE_QUERY_PRODUCT_FREIGHT` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=213 | 2025-05-14 | 無英文版 | `common-apis.md` |
| 218 | API文檔 > 國際件API > 通用 > 預估總運費查詢 | `IPE_ESTIMATE_FEE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=218 | 2025-08-29 | en 2025-08-29 | `common-apis.md` |
| 221 | API文檔 > 國際件API > 通用 > 查詢預計派件時間 | `IPT_QUERY_PROMITM` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=221 | 2026-08-18 | en 2025-05-27 | `common-apis.md` |
| 243 | API文檔 > 國際件API > 通用 > 國際產品查詢 | `IPE_QUERY_PRODUCT_LIST` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=243 | 2026-08-24 | en 2026-08-24 | `common-apis.md` |
| 219 | API文檔 > 國際件API > 關務 > 關務清關資料上傳 | `ICSM_UPLOAD_CERTIFY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=219 | 2026-01-19 | en 2026-08-11 | `customs-apis.md` |
| 215 | API文檔 > 國際件API > 關務 > 發票信息接收 | `ICSM_INVOICE_INFO_UPLOAD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=215 | 2024-12-10 | 無英文版 | `customs-apis.md` |
| 216 | API文檔 > 國際件API > 關務 > 發票文件接收 | `ICSM_INVOICE_FILE_UPLOAD` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=216 | 2024-12-10 | 無英文版 | `customs-apis.md` |
| 230 | API文檔 > 國際件API > 關務 > 9610申報信息上傳 | `ICSM_UPLOAD_NSOZ` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=230 | 2025-11-18 | 無英文版 | `customs-apis.md` |
| 92 | API文檔 > 國際件API > 消息推送 > 運單軌跡推送 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=92 | 2026-07-30 | en 2026-07-30 | `tracking.md` |
| 202 | API文檔 > 海外本地件API > 創建物流訂單 | `IUOP_OS_CREATE_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=202 | 2026-06-12 | en 2026-06-12 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 203 | API文檔 > 海外本地件API > 獲取發貨面單 | `IUOP_OS_PRINT_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=203 | 2024-05-17 | en 2024-04-16 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 204 | API文檔 > 海外本地件API > 路由軌跡查詢 | `IUOP_OS_QUERY_TRACK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=204 | 2024-10-29 | en 2024-10-29 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 208 | API文檔 > 海外本地件API > 取消物流訂單 | `IUOP_OS_CANCEL_ORDER` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=208 | 2024-07-03 | en 2024-07-03 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 207 | API文檔 > 海外本地件API > 路線與增值服務查詢 | `IUOP_OS_QUERY_ROUTE_ADDSERVICE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=207 | 2026-06-04 | en 2026-06-04 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 224 | API文檔 > 海外本地件API > 消息推送 > 運單軌跡推送 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=224 | 2025-06-26 | en 2025-06-24 | 未收錄（海外本地件：馬來西亞/印尼/越南，非台灣；SKILL.md 只標邊界） |
| 147 | API文檔 > 國際區劃 > 查詢大洲列表 | `ICMS_GET_CONTINENT_LIST` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=147 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 148 | API文檔 > 國際區劃 > 查詢城市列表 | `ICMS_QUERY_CITY_LIST` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=148 | 2023-09-12 | en 2023-12-29 | `address-region.md` |
| 149 | API文檔 > 國際區劃 > 查詢國家列表 | `ICMS_QUERY_COUNTRY_LIST` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=149 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 150 | API文檔 > 國際區劃 > 查詢郵編屬性 | `ICMS_QUERY_POST_CODE_ATTRIBUTE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=150 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 151 | API文檔 > 國際區劃 > 根據國際區劃查詢郵編 | `ICMS_QUERY_POST_CODE_BY_REGION` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=151 | 2023-09-12 | en 2023-12-29 | `address-region.md` |
| 152 | API文檔 > 國際區劃 > 查詢郵編詳細信息 | `ICMS_QUERY_POST_CODE_INFO` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=152 | 2024-07-15 | en 2023-12-29 | `address-region.md` |
| 153 | API文檔 > 國際區劃 > 郵編模糊查詢 | `ICMS_QUERY_POST_CODE_VAGUE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=153 | 2023-09-12 | en 2023-12-29 | `address-region.md` |
| 154 | API文檔 > 國際區劃 > 查詢州省列表 | `ICMS_QUERY_PROVINCE_LIST` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=154 | 2023-09-12 | en 2023-12-29 | `address-region.md` |
| 155 | API文檔 > 國際區劃 > 根據郵編查詢國際區劃 | `ICMS_QUERY_REGION_BY_POST_CODE` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=155 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 156 | API文檔 > 國際區劃 > 國際區劃級聯查詢 | `ICMS_REGION_CASCADE_QUERY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=156 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 157 | API文檔 > 國際區劃 > 國際區劃模糊查詢 | `ICMS_REGION_VAGUE_QUERY` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=157 | 2023-08-17 | en 2023-12-29 | `address-region.md` |
| 205 | API文檔 > 國際區劃 > 國際電話有效性校驗 | `ICMS_PHONE_VALID_CHECK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=205 | 2024-05-08 | 無英文版 | `address-region.md` |
| 60 | API文檔 > 附錄 > 1.業務接口編碼列表 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=60 | 2023-06-07 | en 2022-09-27 | `dictionaries.md` |
| 61 | API文檔 > 附錄 > 2.國際件產品映射碼聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=61 | 2026-09-11 | en 2026-09-11 | `dictionaries.md` |
| 201 | API文檔 > 附錄 > 3.海外本地件產品映射碼 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=201 | 2025-10-24 | en 2025-10-24 | `dictionaries.md` |
| 62 | API文檔 > 附錄 > 4.幣種聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=62 | 2023-07-31 | en 2023-07-31 | `dictionaries.md` |
| 63 | API文檔 > 附錄 > 5.國家地區代碼聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=63 | 2023-03-28 | en 2023-03-28 | `dictionaries.md` |
| 64 | API文檔 > 附錄 > 6.增值服務聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=64 | 2025-10-24 | en 2025-10-24 | `dictionaries.md` |
| 65 | API文檔 > 附錄 > 7.證件類型聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=65 | 2026-01-19 | en 2025-11-06 | `dictionaries.md` |
| 66 | API文檔 > 附錄 > 8.時區聲明 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=66 | 2022-07-27 | en 2022-07-27 | `dictionaries.md` |
| 67 | API文檔 > 附錄 > 9.擴展屬性 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=67 | 2025-02-28 | en 2025-02-28 | `dictionaries.md` |
| 68 | API文檔 > 附錄 > 10.報關方式屬性 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=68 | 2025-09-17 | en 2025-09-17 | `dictionaries.md` |
| 69 | API文檔 > 附錄 > 11.貿易條件屬性值 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=69 | 2022-07-27 | en 2022-08-03 | `dictionaries.md` |
| 71 | API文檔 > 附錄 > 12.報關資料類型 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=71 | 2025-09-22 | en 2025-09-22 | `dictionaries.md` |
| 72 | API文檔 > 附錄 > 13.響應碼 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=72 | 2024-08-14 | en 2024-08-14 | `error-codes.md` |
| 132 | API文檔 > 附錄 > 14.路由操作碼 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=132 | 2024-01-18 | en 2024-01-17 | `tracking.md` |
| 209 | API文檔 > 附錄 > 15.收派範圍列表 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=209 | 2024-07-15 | 無英文版 | `dictionaries.md` |
| 229 | API文檔 > 附錄 > 16.本地化參數 |  | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=229 | 2025-10-17 | en 2025-11-06 | `dictionaries.md` |
| 127 | 常見問題 > 開發接入常見問題 |  | https://openapi-portal.sf.global/question?secondMenu=127 | 2025-03-24 | en 2022-11-10 | `onboarding-and-faq.md` |
| 128 | 常見問題 > 出口電商常見異常響應 |  | https://openapi-portal.sf.global/question?secondMenu=128 | 2025-09-03 | en 2025-09-03 | `error-codes.md` |
| 169 | 常見問題 > 國際快遞常見異常響應 |  | https://openapi-portal.sf.global/question?secondMenu=169 | 2023-06-21 | en 2025-09-03 | `error-codes.md` |
| 84 | API文檔 > 國際件API > 路由軌跡查詢（首頁卡片「查詢軌跡 › 國際快遞」連入，不在左側選單） | `IUOP_QUERY_TRACK` | https://openapi-portal.sf.global/document?firstMenu=API&secondMenu=84 | 2023-12-20 | en 2023-12-29 | `tracking.md`（**從首頁卡片「查詢軌跡 › 國際快遞」連入，不在左側選單**） |

## 3. 額外取得的官方檔案

| 檔案 | URL | 用途 |
|---|---|---|
| PHP 加解密樣例 | https://storage.googleapis.com/ifsp-public/SampleCode/Php.zip | `BizMsgCrypt` / `PrpCrypt` / `SHA2` / `PKCS7Encoder` / `ErrorCode` / Demo，`php-client.md` 依此改寫並驗證 |
| JS 加解密樣例 | https://storage.googleapis.com/ifsp-public/SampleCode/JS.zip | crypto-js 版 `aesFunc.js`，比對 PKCS7 block=32、IV=key[0:16] |
| Java 加解密樣例 | https://storage.googleapis.com/ifsp-public/SampleCode/Java.zip | 確認 signature 用 `getSHA2`（SHA-256）、字串排序 |
| Python 加解密樣例 | https://storage.googleapis.com/ifsp-public/SampleCode/Python.zip | 提供第二組測試密文 |
| 官方 Claude Skill 包 | https://storage.googleapis.com/ifsp-public/AI/sf-ibu-openapi-code.zip | 內含 6 語言 SampleCode（PHP `SampleCode.php` 示範 https 沙箱 + `IUOP_QUERY_ORDER`） |
| MCP Server（未下載執行） | https://storage.googleapis.com/ifsp-public/AI/IOASPMCPServer-1.0-SNAPSHOT.jar、https://storage.googleapis.com/ifsp-public/AI/mcp-py.zip | 官方 MCP（工具 getToken / encrypt / decrypt / dispatch），說明見 `onboarding-and-faq.md` |
| C# / Go 樣例（未下載） | https://storage.googleapis.com/ifsp-public/SampleCode/C%23.zip、https://storage.googleapis.com/ifsp-public/SampleCode/Go.zip | 未使用 |

另以公開沙箱憑證做了唯讀驗證呼叫（結果見 `auth-and-crypto.md` §0.8、`address-region.md` 補充節、`php-client.md` §7）。

## 4. 未取得 / 需登入 / 不存在 / 隱藏頁

### 4.1 選單上沒有的隱藏頁（portal 文件 API 以 secondMenu id 可直接取得）

原文未保留在本 skill；需要時可用 portal 文件 API 依 id 重抓（公開、免登入，與選單頁相同）：`GET https://openapi-portal.sf.global/openapi/console/noLogin/online/doc/{id}/{lang}`（`lang` = `zh-CN` 或 `en`）→ 回應 `data.content` 為帶簽名的 Markdown 檔 URL（`storage.googleapis.com/openapi-prod/apiDoc/onlineDoc/...`，約 30 分鐘有效；瀏覽器內可改走同源代理路徑 `/openapi-prod/apiDoc/onlineDoc/...`）→ 再 GET 該 URL 取 Markdown 原文。選單樹為 `GET /openapi/console/noLogin/online/doc/menu/list`。

| id | 標題 / 內容 | 說明 | 是否收錄 |
|---|---|---|---|
| 84 | 路由軌跡查詢 `IUOP_QUERY_TRACK` | 首頁卡片「查詢軌跡 › 國際快遞」連入；沙箱實測可用 | **收錄**：`tracking.md` 第 2 節 + 對照表 |
| 125 | 物流單信息推送海關 `IUOP_PUSH_TRACK` | 大陸進口**落地配**業務，請順豐把物流單推送給口岸海關（物流企業代碼 4403180939 順豐速運有限公司；正式走貨需銷售提 IT069 流程）；body `customerCode`、`waybillNo`、`version`，回 `data` Boolean | 未收錄完整欄位（與台灣無關）；本列即其全部欄位 |
| 239 | 宅配延伸服務類型查詢 | 用來查建單 `addServiceInfoList[].assemblyInfoList[].assemblyCode`；原文 msgType 寫「(例如，IIUOP_ASSEMBLY_QUERY)」（疑似占位 / 筆誤，未定案），範例為泰國（region=TH、泰文服務名） | 未收錄；已在 `iuop-create-order.md` 的 `assemblyInfoList` / `assemblyCode` 列加註 |
| 227 | 附錄「16.費用項列表」 | 草稿：只有 `1` 運費、`IN02` 保費兩列，其餘空白；與選單上的「16.本地化參數」編號衝突 | 未收錄 |
| 143 | 聚水潭 ERP 授權指引 | 聚水潭以「IUOP 下單接口（摘要簽名認證 2）」對接；CustomerCode、APPkey/APPsecret 需客戶經理提 IT067-01 流程取得 | 未收錄（第三方 ERP 設定） |
| 3 | 新手指引（TEST 表格） | 測試頁 | 未收錄 |
| 8 / 57 | 舊版 Q&A（中 / 英，2022） | 內容為 `onboarding-and-faq.md` 開發接入常見問題的舊版前段 | 未收錄（已有新版） |
| 9 / 99 | 舊版「異常響應 / 常見異常響應」（2022） | IECS 錯誤表舊版 | 未收錄（已有新版 `error-codes.md`） |
| 53 | 【ERP】在店小秘中如何使用（2022 草稿） | 測試格式內容 | 未收錄 |
| 100 | TEST0809 | 測試亂字 | 未收錄 |
| 158 | TEST | 空白 API 範本 | 未收錄 |

### 4.2 其他未取得項目

| 項目 | 狀態 |
|---|---|
| 控制台 `https://openapi.sf.global/`（應用列表、在線報文調試工具 `/SandboxModule/OnlineDebugApi`、在線加解密工具 `/SandboxModule/OnlineEncryptTool`、客戶編碼管理 `/ManageCenterModule/CustIdManagement`、ISV 應用列表 `/IsvModule/IsvUserAppList`） | **需登入，未取得**；文件中只有截圖 |
| IUOP 商家後台 `https://iuop.sf-express.com` / `https://iuop.sf.global/`、海外本地件後台 `https://order.my.kex-express.com` | **需登入，未取得** |
| 推送聯調資訊收集表（DingTalk `sf-alidocs.dingtalk.com`，官方標註「內部連結」） | **內部，未取得** |
| 正式環境推送 `sign` key、正式 appKey / appSecret / AES key、月結卡號 | 需與順豐簽約 / 聯調後取得 |
| 路由查詢 `reasonCode`（國際原因代碼）完整對照表 | **portal 無此表**（推送範例只見 `reasonCode`/`reasonCodeTranslation` 成對出現） |
| `zh-HK` / `zh-TW` 版文件 | 不存在 |
| 18 頁無英文版：162、212、213、215、216、222、225、228、230、232–238、205、209 | 僅 zh-CN |
| 頂部「順豐國際」連結（官網 `https://www.sf-international.com`）與商家後台 | 非開發文件，未爬 |
| 中國大陸境內件（順豐速運大陸開放平台） | portal 文件無對應說明，未收錄 |
| 台灣島內件（TW→TW） | **文件沒有台灣島內件說明**；[實測 2026-09-23] 沙箱 `IPE_QUERY_PRODUCT_FREIGHT` TW 10048 → TW 80141 回 13 個產品（含 `INT0005`、`INT0008`、附錄 2 未列的 `INT0202` Taiwan E-commerce），正式可否下單待向順豐台灣客戶經理確認，見 `taiwan-rules.md` §2.1 |
