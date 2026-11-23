<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers;

/**
 * 台灣郵遞區號 / 縣市 + 鄉鎮市區 → 順豐英文區劃（regionFirst = 縣市英文、regionSecond = 區英文）
 *
 * 資料：zenbu `apps/api-gateway/src/commerce/shipping/utils/tw-admin-divisions.ts`（中華郵政中英對照，22 縣市 / 371 鄉鎮市區）
 * 以一次性腳本轉出（轉換腳本不納入版控），已套用順豐寫法：英文區名結尾 ` Dist.` 展開為 ` District`、
 * 彎撇號（U+2019）換 ASCII `'`——順豐 ICMS_QUERY_REGION_BY_POST_CODE 實回 `Zhongzheng District` / `Xitun District`（2026-11-21 沙箱）。
 * **勿手改**：英文區劃打錯的症狀是派送延誤或退件，沙箱照收，我方不會知道。
 *
 * 換算不出來一律回 null，由呼叫端擋下（VALIDATION），**不送猜測值**。
 * 3 碼 300（新竹市東 / 北 / 香山區）與 600（嘉義市東 / 西區）多區共用 → 只有郵遞區號時不猜。
 *
 * @see .claude/skills/sf-express/references/address-region.md
 */
final class SfRegion {

	/**
	 * [ 3 碼郵遞區號, 縣市, 鄉鎮市區, 縣市英文, 區英文 ]
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public const ROWS = [
		[ '100', '臺北市', '中正區', 'Taipei City', 'Zhongzheng District' ],
		[ '103', '臺北市', '大同區', 'Taipei City', 'Datong District' ],
		[ '104', '臺北市', '中山區', 'Taipei City', 'Zhongshan District' ],
		[ '105', '臺北市', '松山區', 'Taipei City', 'Songshan District' ],
		[ '106', '臺北市', '大安區', 'Taipei City', 'Da\'an District' ],
		[ '108', '臺北市', '萬華區', 'Taipei City', 'Wanhua District' ],
		[ '110', '臺北市', '信義區', 'Taipei City', 'Xinyi District' ],
		[ '111', '臺北市', '士林區', 'Taipei City', 'Shilin District' ],
		[ '112', '臺北市', '北投區', 'Taipei City', 'Beitou District' ],
		[ '114', '臺北市', '內湖區', 'Taipei City', 'Neihu District' ],
		[ '115', '臺北市', '南港區', 'Taipei City', 'Nangang District' ],
		[ '116', '臺北市', '文山區', 'Taipei City', 'Wenshan District' ],
		[ '200', '基隆市', '仁愛區', 'Keelung City', 'Ren\'ai District' ],
		[ '201', '基隆市', '信義區', 'Keelung City', 'Xinyi District' ],
		[ '202', '基隆市', '中正區', 'Keelung City', 'Zhongzheng District' ],
		[ '203', '基隆市', '中山區', 'Keelung City', 'Zhongshan District' ],
		[ '204', '基隆市', '安樂區', 'Keelung City', 'Anle District' ],
		[ '205', '基隆市', '暖暖區', 'Keelung City', 'Nuannuan District' ],
		[ '206', '基隆市', '七堵區', 'Keelung City', 'Qidu District' ],
		[ '207', '新北市', '萬里區', 'New Taipei City', 'Wanli District' ],
		[ '208', '新北市', '金山區', 'New Taipei City', 'Jinshan District' ],
		[ '220', '新北市', '板橋區', 'New Taipei City', 'Banqiao District' ],
		[ '221', '新北市', '汐止區', 'New Taipei City', 'Xizhi District' ],
		[ '222', '新北市', '深坑區', 'New Taipei City', 'Shenkeng District' ],
		[ '223', '新北市', '石碇區', 'New Taipei City', 'Shiding District' ],
		[ '224', '新北市', '瑞芳區', 'New Taipei City', 'Ruifang District' ],
		[ '226', '新北市', '平溪區', 'New Taipei City', 'Pingxi District' ],
		[ '227', '新北市', '雙溪區', 'New Taipei City', 'Shuangxi District' ],
		[ '228', '新北市', '貢寮區', 'New Taipei City', 'Gongliao District' ],
		[ '231', '新北市', '新店區', 'New Taipei City', 'Xindian District' ],
		[ '232', '新北市', '坪林區', 'New Taipei City', 'Pinglin District' ],
		[ '233', '新北市', '烏來區', 'New Taipei City', 'Wulai District' ],
		[ '234', '新北市', '永和區', 'New Taipei City', 'Yonghe District' ],
		[ '235', '新北市', '中和區', 'New Taipei City', 'Zhonghe District' ],
		[ '236', '新北市', '土城區', 'New Taipei City', 'Tucheng District' ],
		[ '237', '新北市', '三峽區', 'New Taipei City', 'Sanxia District' ],
		[ '238', '新北市', '樹林區', 'New Taipei City', 'Shulin District' ],
		[ '239', '新北市', '鶯歌區', 'New Taipei City', 'Yingge District' ],
		[ '241', '新北市', '三重區', 'New Taipei City', 'Sanchong District' ],
		[ '242', '新北市', '新莊區', 'New Taipei City', 'Xinzhuang District' ],
		[ '243', '新北市', '泰山區', 'New Taipei City', 'Taishan District' ],
		[ '244', '新北市', '林口區', 'New Taipei City', 'Linkou District' ],
		[ '247', '新北市', '蘆洲區', 'New Taipei City', 'Luzhou District' ],
		[ '248', '新北市', '五股區', 'New Taipei City', 'Wugu District' ],
		[ '249', '新北市', '八里區', 'New Taipei City', 'Bali District' ],
		[ '251', '新北市', '淡水區', 'New Taipei City', 'Tamsui District' ],
		[ '252', '新北市', '三芝區', 'New Taipei City', 'Sanzhi District' ],
		[ '253', '新北市', '石門區', 'New Taipei City', 'Shimen District' ],
		[ '209', '連江縣', '南竿鄉', 'Lienchiang County', 'Nangan Township' ],
		[ '210', '連江縣', '北竿鄉', 'Lienchiang County', 'Beigan Township' ],
		[ '211', '連江縣', '莒光鄉', 'Lienchiang County', 'Juguang Township' ],
		[ '212', '連江縣', '東引鄉', 'Lienchiang County', 'Dongyin Township' ],
		[ '260', '宜蘭縣', '宜蘭市', 'Yilan County', 'Yilan City' ],
		[ '263', '宜蘭縣', '壯圍鄉', 'Yilan County', 'Zhuangwei Township' ],
		[ '261', '宜蘭縣', '頭城鎮', 'Yilan County', 'Toucheng Township' ],
		[ '262', '宜蘭縣', '礁溪鄉', 'Yilan County', 'Jiaoxi Township' ],
		[ '264', '宜蘭縣', '員山鄉', 'Yilan County', 'Yuanshan Township' ],
		[ '265', '宜蘭縣', '羅東鎮', 'Yilan County', 'Luodong Township' ],
		[ '266', '宜蘭縣', '三星鄉', 'Yilan County', 'Sanxing Township' ],
		[ '267', '宜蘭縣', '大同鄉', 'Yilan County', 'Datong Township' ],
		[ '268', '宜蘭縣', '五結鄉', 'Yilan County', 'Wujie Township' ],
		[ '269', '宜蘭縣', '冬山鄉', 'Yilan County', 'Dongshan Township' ],
		[ '270', '宜蘭縣', '蘇澳鎮', 'Yilan County', 'Su\'ao Township' ],
		[ '272', '宜蘭縣', '南澳鄉', 'Yilan County', 'Nan\'ao Township' ],
		[ '290', '宜蘭縣', '釣魚臺', 'Yilan County', 'Diaoyutai' ],
		[ '300', '新竹市', '東區', 'Hsinchu City', 'East District' ],
		[ '300', '新竹市', '北區', 'Hsinchu City', 'North District' ],
		[ '300', '新竹市', '香山區', 'Hsinchu City', 'Xiangshan District' ],
		[ '308', '新竹縣', '寶山鄉', 'Hsinchu County', 'Baoshan Township' ],
		[ '302', '新竹縣', '竹北市', 'Hsinchu County', 'Zhubei City' ],
		[ '303', '新竹縣', '湖口鄉', 'Hsinchu County', 'Hukou Township' ],
		[ '304', '新竹縣', '新豐鄉', 'Hsinchu County', 'Xinfeng Township' ],
		[ '305', '新竹縣', '新埔鎮', 'Hsinchu County', 'Xinpu Township' ],
		[ '306', '新竹縣', '關西鎮', 'Hsinchu County', 'Guanxi Township' ],
		[ '307', '新竹縣', '芎林鄉', 'Hsinchu County', 'Qionglin Township' ],
		[ '310', '新竹縣', '竹東鎮', 'Hsinchu County', 'Zhudong Township' ],
		[ '311', '新竹縣', '五峰鄉', 'Hsinchu County', 'Wufeng Township' ],
		[ '312', '新竹縣', '橫山鄉', 'Hsinchu County', 'Hengshan Township' ],
		[ '313', '新竹縣', '尖石鄉', 'Hsinchu County', 'Jianshi Township' ],
		[ '314', '新竹縣', '北埔鄉', 'Hsinchu County', 'Beipu Township' ],
		[ '315', '新竹縣', '峨眉鄉', 'Hsinchu County', 'Emei Township' ],
		[ '320', '桃園市', '中壢區', 'Taoyuan City', 'Zhongli District' ],
		[ '324', '桃園市', '平鎮區', 'Taoyuan City', 'Pingzhen District' ],
		[ '325', '桃園市', '龍潭區', 'Taoyuan City', 'Longtan District' ],
		[ '326', '桃園市', '楊梅區', 'Taoyuan City', 'Yangmei District' ],
		[ '327', '桃園市', '新屋區', 'Taoyuan City', 'Xinwu District' ],
		[ '328', '桃園市', '觀音區', 'Taoyuan City', 'Guanyin District' ],
		[ '330', '桃園市', '桃園區', 'Taoyuan City', 'Taoyuan District' ],
		[ '333', '桃園市', '龜山區', 'Taoyuan City', 'Guishan District' ],
		[ '334', '桃園市', '八德區', 'Taoyuan City', 'Bade District' ],
		[ '335', '桃園市', '大溪區', 'Taoyuan City', 'Daxi District' ],
		[ '336', '桃園市', '復興區', 'Taoyuan City', 'Fuxing District' ],
		[ '337', '桃園市', '大園區', 'Taoyuan City', 'Dayuan District' ],
		[ '338', '桃園市', '蘆竹區', 'Taoyuan City', 'Luzhu District' ],
		[ '350', '苗栗縣', '竹南鎮', 'Miaoli County', 'Zhunan Township' ],
		[ '351', '苗栗縣', '頭份市', 'Miaoli County', 'Toufen City' ],
		[ '352', '苗栗縣', '三灣鄉', 'Miaoli County', 'Sanwan Township' ],
		[ '353', '苗栗縣', '南庄鄉', 'Miaoli County', 'Nanzhuang Township' ],
		[ '354', '苗栗縣', '獅潭鄉', 'Miaoli County', 'Shitan Township' ],
		[ '356', '苗栗縣', '後龍鎮', 'Miaoli County', 'Houlong Township' ],
		[ '357', '苗栗縣', '通霄鎮', 'Miaoli County', 'Tongxiao Township' ],
		[ '358', '苗栗縣', '苑裡鎮', 'Miaoli County', 'Yuanli Township' ],
		[ '360', '苗栗縣', '苗栗市', 'Miaoli County', 'Miaoli City' ],
		[ '361', '苗栗縣', '造橋鄉', 'Miaoli County', 'Zaoqiao Township' ],
		[ '362', '苗栗縣', '頭屋鄉', 'Miaoli County', 'Touwu Township' ],
		[ '363', '苗栗縣', '公館鄉', 'Miaoli County', 'Gongguan Township' ],
		[ '364', '苗栗縣', '大湖鄉', 'Miaoli County', 'Dahu Township' ],
		[ '365', '苗栗縣', '泰安鄉', 'Miaoli County', 'Tai\'an Township' ],
		[ '366', '苗栗縣', '銅鑼鄉', 'Miaoli County', 'Tongluo Township' ],
		[ '367', '苗栗縣', '三義鄉', 'Miaoli County', 'Sanyi Township' ],
		[ '368', '苗栗縣', '西湖鄉', 'Miaoli County', 'Xihu Township' ],
		[ '369', '苗栗縣', '卓蘭鎮', 'Miaoli County', 'Zhuolan Township' ],
		[ '400', '臺中市', '中區', 'Taichung City', 'Central District' ],
		[ '401', '臺中市', '東區', 'Taichung City', 'East District' ],
		[ '402', '臺中市', '南區', 'Taichung City', 'South District' ],
		[ '403', '臺中市', '西區', 'Taichung City', 'West District' ],
		[ '404', '臺中市', '北區', 'Taichung City', 'North District' ],
		[ '406', '臺中市', '北屯區', 'Taichung City', 'Beitun District' ],
		[ '407', '臺中市', '西屯區', 'Taichung City', 'Xitun District' ],
		[ '408', '臺中市', '南屯區', 'Taichung City', 'Nantun District' ],
		[ '411', '臺中市', '太平區', 'Taichung City', 'Taiping District' ],
		[ '412', '臺中市', '大里區', 'Taichung City', 'Dali District' ],
		[ '413', '臺中市', '霧峰區', 'Taichung City', 'Wufeng District' ],
		[ '414', '臺中市', '烏日區', 'Taichung City', 'Wuri District' ],
		[ '420', '臺中市', '豐原區', 'Taichung City', 'Fengyuan District' ],
		[ '421', '臺中市', '后里區', 'Taichung City', 'Houli District' ],
		[ '422', '臺中市', '石岡區', 'Taichung City', 'Shigang District' ],
		[ '423', '臺中市', '東勢區', 'Taichung City', 'Dongshi District' ],
		[ '424', '臺中市', '和平區', 'Taichung City', 'Heping District' ],
		[ '426', '臺中市', '新社區', 'Taichung City', 'Xinshe District' ],
		[ '427', '臺中市', '潭子區', 'Taichung City', 'Tanzi District' ],
		[ '428', '臺中市', '大雅區', 'Taichung City', 'Daya District' ],
		[ '429', '臺中市', '神岡區', 'Taichung City', 'Shengang District' ],
		[ '432', '臺中市', '大肚區', 'Taichung City', 'Dadu District' ],
		[ '433', '臺中市', '沙鹿區', 'Taichung City', 'Shalu District' ],
		[ '434', '臺中市', '龍井區', 'Taichung City', 'Longjing District' ],
		[ '435', '臺中市', '梧棲區', 'Taichung City', 'Wuqi District' ],
		[ '436', '臺中市', '清水區', 'Taichung City', 'Qingshui District' ],
		[ '437', '臺中市', '大甲區', 'Taichung City', 'Dajia District' ],
		[ '438', '臺中市', '外埔區', 'Taichung City', 'Waipu District' ],
		[ '439', '臺中市', '大安區', 'Taichung City', 'Da\'an District' ],
		[ '500', '彰化縣', '彰化市', 'Changhua County', 'Changhua City' ],
		[ '502', '彰化縣', '芬園鄉', 'Changhua County', 'Fenyuan Township' ],
		[ '503', '彰化縣', '花壇鄉', 'Changhua County', 'Huatan Township' ],
		[ '504', '彰化縣', '秀水鄉', 'Changhua County', 'Xiushui Township' ],
		[ '505', '彰化縣', '鹿港鎮', 'Changhua County', 'Lukang Township' ],
		[ '506', '彰化縣', '福興鄉', 'Changhua County', 'Fuxing Township' ],
		[ '507', '彰化縣', '線西鄉', 'Changhua County', 'Xianxi Township' ],
		[ '508', '彰化縣', '和美鎮', 'Changhua County', 'Hemei Township' ],
		[ '509', '彰化縣', '伸港鄉', 'Changhua County', 'Shengang Township' ],
		[ '510', '彰化縣', '員林市', 'Changhua County', 'Yuanlin City' ],
		[ '511', '彰化縣', '社頭鄉', 'Changhua County', 'Shetou Township' ],
		[ '512', '彰化縣', '永靖鄉', 'Changhua County', 'Yongjing Township' ],
		[ '513', '彰化縣', '埔心鄉', 'Changhua County', 'Puxin Township' ],
		[ '514', '彰化縣', '溪湖鎮', 'Changhua County', 'Xihu Township' ],
		[ '515', '彰化縣', '大村鄉', 'Changhua County', 'Dacun Township' ],
		[ '516', '彰化縣', '埔鹽鄉', 'Changhua County', 'Puyan Township' ],
		[ '520', '彰化縣', '田中鎮', 'Changhua County', 'Tianzhong Township' ],
		[ '521', '彰化縣', '北斗鎮', 'Changhua County', 'Beidou Township' ],
		[ '522', '彰化縣', '田尾鄉', 'Changhua County', 'Tianwei Township' ],
		[ '523', '彰化縣', '埤頭鄉', 'Changhua County', 'Pitou Township' ],
		[ '524', '彰化縣', '溪州鄉', 'Changhua County', 'Xizhou Township' ],
		[ '525', '彰化縣', '竹塘鄉', 'Changhua County', 'Zhutang Township' ],
		[ '526', '彰化縣', '二林鎮', 'Changhua County', 'Erlin Township' ],
		[ '527', '彰化縣', '大城鄉', 'Changhua County', 'Dacheng Township' ],
		[ '528', '彰化縣', '芳苑鄉', 'Changhua County', 'Fangyuan Township' ],
		[ '530', '彰化縣', '二水鄉', 'Changhua County', 'Ershui Township' ],
		[ '540', '南投縣', '南投市', 'Nantou County', 'Nantou City' ],
		[ '541', '南投縣', '中寮鄉', 'Nantou County', 'Zhongliao Township' ],
		[ '542', '南投縣', '草屯鎮', 'Nantou County', 'Caotun Township' ],
		[ '544', '南投縣', '國姓鄉', 'Nantou County', 'Guoxing Township' ],
		[ '545', '南投縣', '埔里鎮', 'Nantou County', 'Puli Township' ],
		[ '546', '南投縣', '仁愛鄉', 'Nantou County', 'Ren\'ai Township' ],
		[ '551', '南投縣', '名間鄉', 'Nantou County', 'Mingjian Township' ],
		[ '552', '南投縣', '集集鎮', 'Nantou County', 'Jiji Township' ],
		[ '553', '南投縣', '水里鄉', 'Nantou County', 'Shuili Township' ],
		[ '555', '南投縣', '魚池鄉', 'Nantou County', 'Yuchi Township' ],
		[ '556', '南投縣', '信義鄉', 'Nantou County', 'Xinyi Township' ],
		[ '557', '南投縣', '竹山鎮', 'Nantou County', 'Zhushan Township' ],
		[ '558', '南投縣', '鹿谷鄉', 'Nantou County', 'Lugu Township' ],
		[ '600', '嘉義市', '西區', 'Chiayi City', 'West District' ],
		[ '600', '嘉義市', '東區', 'Chiayi City', 'East District' ],
		[ '602', '嘉義縣', '番路鄉', 'Chiayi County', 'Fanlu Township' ],
		[ '603', '嘉義縣', '梅山鄉', 'Chiayi County', 'Meishan Township' ],
		[ '604', '嘉義縣', '竹崎鄉', 'Chiayi County', 'Zhuqi Township' ],
		[ '605', '嘉義縣', '阿里山鄉', 'Chiayi County', 'Alishan Township' ],
		[ '606', '嘉義縣', '中埔鄉', 'Chiayi County', 'Zhongpu Township' ],
		[ '607', '嘉義縣', '大埔鄉', 'Chiayi County', 'Dapu Township' ],
		[ '608', '嘉義縣', '水上鄉', 'Chiayi County', 'Shuishang Township' ],
		[ '611', '嘉義縣', '鹿草鄉', 'Chiayi County', 'Lucao Township' ],
		[ '612', '嘉義縣', '太保市', 'Chiayi County', 'Taibao City' ],
		[ '613', '嘉義縣', '朴子市', 'Chiayi County', 'Puzi City' ],
		[ '614', '嘉義縣', '東石鄉', 'Chiayi County', 'Dongshi Township' ],
		[ '615', '嘉義縣', '六腳鄉', 'Chiayi County', 'Liujiao Township' ],
		[ '616', '嘉義縣', '新港鄉', 'Chiayi County', 'Xingang Township' ],
		[ '621', '嘉義縣', '民雄鄉', 'Chiayi County', 'Minxiong Township' ],
		[ '622', '嘉義縣', '大林鎮', 'Chiayi County', 'Dalin Township' ],
		[ '623', '嘉義縣', '溪口鄉', 'Chiayi County', 'Xikou Township' ],
		[ '624', '嘉義縣', '義竹鄉', 'Chiayi County', 'Yizhu Township' ],
		[ '625', '嘉義縣', '布袋鎮', 'Chiayi County', 'Budai Township' ],
		[ '630', '雲林縣', '斗南鎮', 'Yunlin County', 'Dounan Township' ],
		[ '631', '雲林縣', '大埤鄉', 'Yunlin County', 'Dapi Township' ],
		[ '632', '雲林縣', '虎尾鎮', 'Yunlin County', 'Huwei Township' ],
		[ '633', '雲林縣', '土庫鎮', 'Yunlin County', 'Tuku Township' ],
		[ '634', '雲林縣', '褒忠鄉', 'Yunlin County', 'Baozhong Township' ],
		[ '635', '雲林縣', '東勢鄉', 'Yunlin County', 'Dongshi Township' ],
		[ '636', '雲林縣', '臺西鄉', 'Yunlin County', 'Taixi Township' ],
		[ '637', '雲林縣', '崙背鄉', 'Yunlin County', 'Lunbei Township' ],
		[ '638', '雲林縣', '麥寮鄉', 'Yunlin County', 'Mailiao Township' ],
		[ '640', '雲林縣', '斗六市', 'Yunlin County', 'Douliu City' ],
		[ '643', '雲林縣', '林內鄉', 'Yunlin County', 'Linnei Township' ],
		[ '646', '雲林縣', '古坑鄉', 'Yunlin County', 'Gukeng Township' ],
		[ '647', '雲林縣', '莿桐鄉', 'Yunlin County', 'Citong Township' ],
		[ '648', '雲林縣', '西螺鎮', 'Yunlin County', 'Xiluo Township' ],
		[ '649', '雲林縣', '二崙鄉', 'Yunlin County', 'Erlun Township' ],
		[ '651', '雲林縣', '北港鎮', 'Yunlin County', 'Beigang Township' ],
		[ '652', '雲林縣', '水林鄉', 'Yunlin County', 'Shuilin Township' ],
		[ '653', '雲林縣', '口湖鄉', 'Yunlin County', 'Kouhu Township' ],
		[ '654', '雲林縣', '四湖鄉', 'Yunlin County', 'Sihu Township' ],
		[ '655', '雲林縣', '元長鄉', 'Yunlin County', 'Yuanchang Township' ],
		[ '700', '臺南市', '中西區', 'Tainan City', 'West Central District' ],
		[ '701', '臺南市', '東區', 'Tainan City', 'East District' ],
		[ '702', '臺南市', '南區', 'Tainan City', 'South District' ],
		[ '704', '臺南市', '北區', 'Tainan City', 'North District' ],
		[ '708', '臺南市', '安平區', 'Tainan City', 'Anping District' ],
		[ '709', '臺南市', '安南區', 'Tainan City', 'Annan District' ],
		[ '710', '臺南市', '永康區', 'Tainan City', 'Yongkang District' ],
		[ '711', '臺南市', '歸仁區', 'Tainan City', 'Guiren District' ],
		[ '712', '臺南市', '新化區', 'Tainan City', 'Xinhua District' ],
		[ '713', '臺南市', '左鎮區', 'Tainan City', 'Zuozhen District' ],
		[ '714', '臺南市', '玉井區', 'Tainan City', 'Yujing District' ],
		[ '715', '臺南市', '楠西區', 'Tainan City', 'Nanxi District' ],
		[ '716', '臺南市', '南化區', 'Tainan City', 'Nanhua District' ],
		[ '717', '臺南市', '仁德區', 'Tainan City', 'Rende District' ],
		[ '718', '臺南市', '關廟區', 'Tainan City', 'Guanmiao District' ],
		[ '719', '臺南市', '龍崎區', 'Tainan City', 'Longqi District' ],
		[ '720', '臺南市', '官田區', 'Tainan City', 'Guantian District' ],
		[ '721', '臺南市', '麻豆區', 'Tainan City', 'Madou District' ],
		[ '722', '臺南市', '佳里區', 'Tainan City', 'Jiali District' ],
		[ '723', '臺南市', '西港區', 'Tainan City', 'Xigang District' ],
		[ '724', '臺南市', '七股區', 'Tainan City', 'Qigu District' ],
		[ '725', '臺南市', '將軍區', 'Tainan City', 'Jiangjun District' ],
		[ '726', '臺南市', '學甲區', 'Tainan City', 'Xuejia District' ],
		[ '727', '臺南市', '北門區', 'Tainan City', 'Beimen District' ],
		[ '730', '臺南市', '新營區', 'Tainan City', 'Xinying District' ],
		[ '731', '臺南市', '後壁區', 'Tainan City', 'Houbi District' ],
		[ '732', '臺南市', '白河區', 'Tainan City', 'Baihe District' ],
		[ '733', '臺南市', '東山區', 'Tainan City', 'Dongshan District' ],
		[ '734', '臺南市', '六甲區', 'Tainan City', 'Liujia District' ],
		[ '735', '臺南市', '下營區', 'Tainan City', 'Xiaying District' ],
		[ '736', '臺南市', '柳營區', 'Tainan City', 'Liuying District' ],
		[ '737', '臺南市', '鹽水區', 'Tainan City', 'Yanshui District' ],
		[ '741', '臺南市', '善化區', 'Tainan City', 'Shanhua District' ],
		[ '744', '臺南市', '新市區', 'Tainan City', 'Xinshi District' ],
		[ '742', '臺南市', '大內區', 'Tainan City', 'Danei District' ],
		[ '743', '臺南市', '山上區', 'Tainan City', 'Shanshang District' ],
		[ '745', '臺南市', '安定區', 'Tainan City', 'Anding District' ],
		[ '800', '高雄市', '新興區', 'Kaohsiung City', 'Xinxing District' ],
		[ '801', '高雄市', '前金區', 'Kaohsiung City', 'Qianjin District' ],
		[ '802', '高雄市', '苓雅區', 'Kaohsiung City', 'Lingya District' ],
		[ '803', '高雄市', '鹽埕區', 'Kaohsiung City', 'Yancheng District' ],
		[ '804', '高雄市', '鼓山區', 'Kaohsiung City', 'Gushan District' ],
		[ '805', '高雄市', '旗津區', 'Kaohsiung City', 'Qijin District' ],
		[ '806', '高雄市', '前鎮區', 'Kaohsiung City', 'Qianzhen District' ],
		[ '807', '高雄市', '三民區', 'Kaohsiung City', 'Sanmin District' ],
		[ '811', '高雄市', '楠梓區', 'Kaohsiung City', 'Nanzi District' ],
		[ '812', '高雄市', '小港區', 'Kaohsiung City', 'Xiaogang District' ],
		[ '813', '高雄市', '左營區', 'Kaohsiung City', 'Zuoying District' ],
		[ '814', '高雄市', '仁武區', 'Kaohsiung City', 'Renwu District' ],
		[ '815', '高雄市', '大社區', 'Kaohsiung City', 'Dashe District' ],
		[ '817', '高雄市', '東沙群島', 'Kaohsiung City', 'Dongsha Islands' ],
		[ '819', '高雄市', '南沙群島', 'Kaohsiung City', 'Nansha Islands' ],
		[ '820', '高雄市', '岡山區', 'Kaohsiung City', 'Gangshan District' ],
		[ '821', '高雄市', '路竹區', 'Kaohsiung City', 'Luzhu District' ],
		[ '822', '高雄市', '阿蓮區', 'Kaohsiung City', 'Alian District' ],
		[ '823', '高雄市', '田寮區', 'Kaohsiung City', 'Tianliao District' ],
		[ '824', '高雄市', '燕巢區', 'Kaohsiung City', 'Yanchao District' ],
		[ '825', '高雄市', '橋頭區', 'Kaohsiung City', 'Qiaotou District' ],
		[ '826', '高雄市', '梓官區', 'Kaohsiung City', 'Ziguan District' ],
		[ '827', '高雄市', '彌陀區', 'Kaohsiung City', 'Mituo District' ],
		[ '828', '高雄市', '永安區', 'Kaohsiung City', 'Yong\'an District' ],
		[ '829', '高雄市', '湖內區', 'Kaohsiung City', 'Hunei District' ],
		[ '830', '高雄市', '鳳山區', 'Kaohsiung City', 'Fengshan District' ],
		[ '831', '高雄市', '大寮區', 'Kaohsiung City', 'Daliao District' ],
		[ '832', '高雄市', '林園區', 'Kaohsiung City', 'Linyuan District' ],
		[ '833', '高雄市', '鳥松區', 'Kaohsiung City', 'Niaosong District' ],
		[ '840', '高雄市', '大樹區', 'Kaohsiung City', 'Dashu District' ],
		[ '842', '高雄市', '旗山區', 'Kaohsiung City', 'Qishan District' ],
		[ '843', '高雄市', '美濃區', 'Kaohsiung City', 'Meinong District' ],
		[ '844', '高雄市', '六龜區', 'Kaohsiung City', 'Liugui District' ],
		[ '845', '高雄市', '內門區', 'Kaohsiung City', 'Neimen District' ],
		[ '846', '高雄市', '杉林區', 'Kaohsiung City', 'Shanlin District' ],
		[ '847', '高雄市', '甲仙區', 'Kaohsiung City', 'Jiaxian District' ],
		[ '848', '高雄市', '桃源區', 'Kaohsiung City', 'Taoyuan District' ],
		[ '849', '高雄市', '那瑪夏區', 'Kaohsiung City', 'Namaxia District' ],
		[ '851', '高雄市', '茂林區', 'Kaohsiung City', 'Maolin District' ],
		[ '852', '高雄市', '茄萣區', 'Kaohsiung City', 'Qieding District' ],
		[ '880', '澎湖縣', '馬公市', 'Penghu County', 'Magong City' ],
		[ '881', '澎湖縣', '西嶼鄉', 'Penghu County', 'Xiyu Township' ],
		[ '882', '澎湖縣', '望安鄉', 'Penghu County', 'Wang\'an Township' ],
		[ '883', '澎湖縣', '七美鄉', 'Penghu County', 'Qimei Township' ],
		[ '884', '澎湖縣', '白沙鄉', 'Penghu County', 'Baisha Township' ],
		[ '885', '澎湖縣', '湖西鄉', 'Penghu County', 'Huxi Township' ],
		[ '890', '金門縣', '金沙鎮', 'Kinmen County', 'Jinsha Township' ],
		[ '891', '金門縣', '金湖鎮', 'Kinmen County', 'Jinhu Township' ],
		[ '892', '金門縣', '金寧鄉', 'Kinmen County', 'Jinning Township' ],
		[ '893', '金門縣', '金城鎮', 'Kinmen County', 'Jincheng Township' ],
		[ '894', '金門縣', '烈嶼鄉', 'Kinmen County', 'Lieyu Township' ],
		[ '896', '金門縣', '烏坵鄉', 'Kinmen County', 'Wuqiu Township' ],
		[ '900', '屏東縣', '屏東市', 'Pingtung County', 'Pingtung City' ],
		[ '901', '屏東縣', '三地門鄉', 'Pingtung County', 'Sandimen Township' ],
		[ '902', '屏東縣', '霧臺鄉', 'Pingtung County', 'Wutai Township' ],
		[ '903', '屏東縣', '瑪家鄉', 'Pingtung County', 'Majia Township' ],
		[ '904', '屏東縣', '九如鄉', 'Pingtung County', 'Jiuru Township' ],
		[ '905', '屏東縣', '里港鄉', 'Pingtung County', 'Ligang Township' ],
		[ '906', '屏東縣', '高樹鄉', 'Pingtung County', 'Gaoshu Township' ],
		[ '907', '屏東縣', '鹽埔鄉', 'Pingtung County', 'Yanpu Township' ],
		[ '908', '屏東縣', '長治鄉', 'Pingtung County', 'Changzhi Township' ],
		[ '909', '屏東縣', '麟洛鄉', 'Pingtung County', 'Linluo Township' ],
		[ '911', '屏東縣', '竹田鄉', 'Pingtung County', 'Zhutian Township' ],
		[ '912', '屏東縣', '內埔鄉', 'Pingtung County', 'Neipu Township' ],
		[ '913', '屏東縣', '萬丹鄉', 'Pingtung County', 'Wandan Township' ],
		[ '920', '屏東縣', '潮州鎮', 'Pingtung County', 'Chaozhou Township' ],
		[ '921', '屏東縣', '泰武鄉', 'Pingtung County', 'Taiwu Township' ],
		[ '922', '屏東縣', '來義鄉', 'Pingtung County', 'Laiyi Township' ],
		[ '923', '屏東縣', '萬巒鄉', 'Pingtung County', 'Wanluan Township' ],
		[ '924', '屏東縣', '崁頂鄉', 'Pingtung County', 'Kanding Township' ],
		[ '925', '屏東縣', '新埤鄉', 'Pingtung County', 'Xinpi Township' ],
		[ '926', '屏東縣', '南州鄉', 'Pingtung County', 'Nanzhou Township' ],
		[ '927', '屏東縣', '林邊鄉', 'Pingtung County', 'Linbian Township' ],
		[ '928', '屏東縣', '東港鎮', 'Pingtung County', 'Donggang Township' ],
		[ '929', '屏東縣', '琉球鄉', 'Pingtung County', 'Liuqiu Township' ],
		[ '931', '屏東縣', '佳冬鄉', 'Pingtung County', 'Jiadong Township' ],
		[ '932', '屏東縣', '新園鄉', 'Pingtung County', 'Xinyuan Township' ],
		[ '940', '屏東縣', '枋寮鄉', 'Pingtung County', 'Fangliao Township' ],
		[ '941', '屏東縣', '枋山鄉', 'Pingtung County', 'Fangshan Township' ],
		[ '942', '屏東縣', '春日鄉', 'Pingtung County', 'Chunri Township' ],
		[ '943', '屏東縣', '獅子鄉', 'Pingtung County', 'Shizi Township' ],
		[ '944', '屏東縣', '車城鄉', 'Pingtung County', 'Checheng Township' ],
		[ '945', '屏東縣', '牡丹鄉', 'Pingtung County', 'Mudan Township' ],
		[ '946', '屏東縣', '恆春鎮', 'Pingtung County', 'Hengchun Township' ],
		[ '947', '屏東縣', '滿州鄉', 'Pingtung County', 'Manzhou Township' ],
		[ '950', '臺東縣', '臺東市', 'Taitung County', 'Taitung City' ],
		[ '951', '臺東縣', '綠島鄉', 'Taitung County', 'Ludao Township' ],
		[ '952', '臺東縣', '蘭嶼鄉', 'Taitung County', 'Lanyu Township' ],
		[ '953', '臺東縣', '延平鄉', 'Taitung County', 'Yanping Township' ],
		[ '954', '臺東縣', '卑南鄉', 'Taitung County', 'Beinan Township' ],
		[ '955', '臺東縣', '鹿野鄉', 'Taitung County', 'Luye Township' ],
		[ '956', '臺東縣', '關山鎮', 'Taitung County', 'Guanshan Township' ],
		[ '957', '臺東縣', '海端鄉', 'Taitung County', 'Haiduan Township' ],
		[ '958', '臺東縣', '池上鄉', 'Taitung County', 'Chishang Township' ],
		[ '959', '臺東縣', '東河鄉', 'Taitung County', 'Donghe Township' ],
		[ '961', '臺東縣', '成功鎮', 'Taitung County', 'Chenggong Township' ],
		[ '962', '臺東縣', '長濱鄉', 'Taitung County', 'Changbin Township' ],
		[ '963', '臺東縣', '太麻里鄉', 'Taitung County', 'Taimali Township' ],
		[ '964', '臺東縣', '金峰鄉', 'Taitung County', 'Jinfeng Township' ],
		[ '965', '臺東縣', '大武鄉', 'Taitung County', 'Dawu Township' ],
		[ '966', '臺東縣', '達仁鄉', 'Taitung County', 'Daren Township' ],
		[ '970', '花蓮縣', '花蓮市', 'Hualien County', 'Hualien City' ],
		[ '971', '花蓮縣', '新城鄉', 'Hualien County', 'Xincheng Township' ],
		[ '972', '花蓮縣', '秀林鄉', 'Hualien County', 'Xiulin Township' ],
		[ '973', '花蓮縣', '吉安鄉', 'Hualien County', 'Ji\'an Township' ],
		[ '974', '花蓮縣', '壽豐鄉', 'Hualien County', 'Shoufeng Township' ],
		[ '975', '花蓮縣', '鳳林鎮', 'Hualien County', 'Fenglin Township' ],
		[ '976', '花蓮縣', '光復鄉', 'Hualien County', 'Guangfu Township' ],
		[ '977', '花蓮縣', '豐濱鄉', 'Hualien County', 'Fengbin Township' ],
		[ '978', '花蓮縣', '瑞穗鄉', 'Hualien County', 'Ruisui Township' ],
		[ '979', '花蓮縣', '萬榮鄉', 'Hualien County', 'Wanrong Township' ],
		[ '981', '花蓮縣', '玉里鎮', 'Hualien County', 'Yuli Township' ],
		[ '982', '花蓮縣', '卓溪鄉', 'Hualien County', 'Zhuoxi Township' ],
		[ '983', '花蓮縣', '富里鄉', 'Hualien County', 'Fuli Township' ],
	];

	/**
	 * 以中文縣市 + 鄉鎮市區查表（台 → 臺、去空白；縣市與區錯配回 null）
	 *
	 * @param string $city     縣市
	 * @param string $district 鄉鎮市區
	 * @return array{region_first: string, region_second: string, zip3: string, city: string, district: string}|null
	 */
	public static function from_names( string $city, string $district ): ?array {
		$city     = self::normalize( $city );
		$district = self::normalize( $district );
		if ('' === $city || '' === $district) {
			return null;
		}
		foreach ( self::ROWS as $row ) {
			if ($row[1] === $city && $row[2] === $district) {
				return self::to_result( $row );
			}
		}
		return null;
	}

	/**
	 * 以郵遞區號（取前 3 碼）查表；共用郵遞區號（多區）或查無 → null
	 *
	 * @param string $postcode 郵遞區號（3 / 5 / 6 碼）
	 * @return array{region_first: string, region_second: string, zip3: string, city: string, district: string}|null
	 */
	public static function from_postcode( string $postcode ): ?array {
		$postcode = \trim( $postcode );
		if (1 !== \preg_match( '/^\d{3,6}$/', $postcode )) {
			return null;
		}
		$zip3 = \substr( $postcode, 0, 3 );
		$hits = \array_values( \array_filter( self::ROWS, static fn( array $row ): bool => $row[0] === $zip3 ) );
		return 1 === \count( $hits ) ? self::to_result( $hits[0] ) : null;
	}

	/**
	 * 綜合解析：區名優先（state + city 查表 → 自由文字開頭的「縣市區」）→ 最後才用郵遞區號
	 *
	 * WC 未啟用縣市下拉時，city 常被填成「台中市大雅區」、地址欄也常含縣市區，故串接後解析。
	 *
	 * @param string $postcode 郵遞區號
	 * @param string $state    縣市（WC shipping_state）
	 * @param string $city     鄉鎮市區（WC shipping_city，可能含縣市）
	 * @param string $address  地址（可能以縣市區開頭）
	 * @return array{region_first: string, region_second: string, zip3: string, city: string, district: string}|null
	 */
	public static function resolve( string $postcode, string $state, string $city, string $address = '' ): ?array {
		return self::from_names( $state, $city )
		?? self::from_text( $state . $city . $address )
		?? self::from_text( $address )
		?? self::from_postcode( $postcode );
	}

	/**
	 * 從自由文字開頭解析「縣市 + 鄉鎮市區」
	 *
	 * @param string $text 文字
	 * @return array{region_first: string, region_second: string, zip3: string, city: string, district: string}|null
	 */
	private static function from_text( string $text ): ?array {
		$text = (string) \preg_replace( '/^\d{3,6}/', '', self::normalize( $text ) );
		if ('' === $text) {
			return null;
		}
		foreach ( self::ROWS as $row ) {
			if (\str_starts_with( $text, $row[1] . $row[2] )) {
				return self::to_result( $row );
			}
		}
		return null;
	}

	/**
	 * 正規化：去所有空白、台 → 臺
	 *
	 * @param string $value 值
	 * @return string
	 */
	private static function normalize( string $value ): string {
		return \str_replace( '台', '臺', (string) \preg_replace( '/\s+/u', '', $value ) );
	}

	/**
	 * 列 → 結果
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string, 4: string} $row 資料列
	 * @return array{region_first: string, region_second: string, zip3: string, city: string, district: string}
	 */
	private static function to_result( array $row ): array {
		return [
			'region_first'  => $row[3],
			'region_second' => $row[4],
			'zip3'          => $row[0],
			'city'          => $row[1],
			'district'      => $row[2],
		];
	}
}
