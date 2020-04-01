<?php
include(dirname(__FILE__) . "/phpQuery.php");

/*
	Request example:
	https://swedlist.ru/article_info.php?id=20392120

	Getting additional information about stock in specified store:
	https://swedlist.ru/article_info.php?id=20392120&store=344
*/

// Article validation
if (!isset($_GET['id']))
	exit;
$id = preg_replace("/[^0-9]/", '', $_GET['id']);
if (strlen($id) != 8)
	exit;
$result['article'] = $id;

// Getting product page url by its article
$session_key = "ace0171a-7f84-499f-a4c5-eecff461b02b";
$customer_key = "59b18964-eeb2-4a4d-36ca-8f6f8bc6e6c1";
exec("curl 'https://w102a21be.api.esales.apptus.cloud/api/v1/panels/instant-search?sessionKey=$session_key&customerKey=$customer_key&market=RURU&arg.search_prefix=$id&arg.nr_suggestions=3&arg.nr_planner_suggestions=3&arg.nr_category_suggestions=3&arg.nr_content_suggestions=3&arg.catalog_root=category_catalog_ruru%3A%27root%27&arg.catalog_filter=type%3A%27functional%27%20OR%20type%3A%27products%27%20OR%20type%3A%27series%27%20OR%20type%3A%27collections%27&arg.locale=ru_RU&arg.filter=market%3A%27RURU%27' -H 'Sec-Fetch-Mode: cors' -H 'Referer: https://www.ikea.com/ru/ru/p/linnmon-adils-stol-belyy-s89279386/' -H 'Origin: https://www.ikea.com' -H 'User-Agent: Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Mobile Safari/537.36' -H 'DNT: 1' --compressed", $search);
$array = json_decode($search[0], 1);
$product_url = $array['productSuggestions'][0]['products'][0]['attributes']['pip_url'];

// Loading page
$max_timout = 10;
$proxy = false;
$data = request($product_url, $max_timout, $proxy);

// Start parsing
$pq = phpQuery::newDocument($data['data']);

// Product title
$result['title'] = trim($pq->find(' h1 > span.product-pip__name')->html());

// Product image url
$result['pic'] = $pq->find('div.range-carousel__image > img')->attr('src');
$result['pic'] = substr($result['pic'], 0, -4);

// Product description
$description = $pq->find(' h1 > span.normal-font.range__text-rtl')->html();
$result['description'] = trim(preg_replace('/ \s+/', '', $description));

// Getting additional information for description like sizes and capacity
$pieces = $pq->find('#content > div.product-pip.js-product-pip > div.product-pip__top-container.flex.center-horizontal > div.product-pip__right-container > div.product-pip__price-package > div > p.js-pip-price-component.no-margin > span > span.product-pip__price__unit');
$pieces = trim(preg_replace("/\//", '', $pieces->html()));
if (strlen($pieces) > 0) {
	$result['description'] .= ', ' . $pieces;
}

// Product price
// Checking current price
$price = $pq->find('#content > div.product-pip.js-product-pip > div.product-pip__top-container.flex.center-horizontal > div.product-pip__right-container > div.product-pip__price-package > div > p.js-pip-price-component.no-margin > span > span.product-pip__price__value');
$matches = array();
preg_match("/.+₽/", $price->html(), $matches);
$result['price'] = preg_replace("/[^0-9]/", '', $matches[0]);

// Checking previous price
$result['discount'] = null;
$discount = $pq->find('#content > div.product-pip.js-product-pip > div.product-pip__top-container.flex.center-horizontal > div.product-pip__right-container > div.product-pip__price-package > div > p.product-pip__previous-price');
$matches = array();
preg_match("/.+₽/s", $discount->html(), $matches);
preg_match("/Прежняя/", $matches[0], $price_prev);
if (!$price_prev)
	$price_prev = preg_replace("/[^0-9]/", '', $matches[0]);
else
	$price_prev = null;

// Swap price and previous price if the previous price is exist
if ($price_prev) {
	$result['discount'] = $result['price'];
	$result['price'] = $price_prev;
}

// Checking the validity period of the discount
if ($result['discount']) {
	$period = $pq->find('#content > div.product-pip.js-product-pip > div.product-pip__top-container.flex.center-horizontal > div.product-pip__right-container > div.product-pip__price-package > div.price-package');
	$matches = array();
	preg_match_all("|\((.*?) - (.*?) Д|", $period->html(), $matches);
	$result['startDate'] = str_to_date($matches[1][0]);
	$result['endDate'] = str_to_date($matches[2][0]);
}

// Product type: ART (single article) или SPR (combination of few articles)
preg_match_all('/data-item-type="(.*?)"/i', $data['data'], $matches_type);
$result['item_type'] = $matches_type[1][0];

// Product packages
$packages = $pq->find("#pip_package_details > div > div > div > p > span");
$packages_block = "";
foreach ($packages as $key => $value) {
	$packages_block .= pq($value)->html();
}
$matches = array();
preg_match_all("/(Ширина|Высота|Длина|Диаметр|Вес)\:(.*?) /iu", $packages_block, $matches);
$k = 0;
for ($i = 0; $i < count($matches[1]); $i++) {
	$ind = (int)($k / 4);
	if ($matches[1][$i] == 'Ширина')
		$result['sizes'][$ind]['width'] = $matches[2][$i];
	if ($matches[1][$i] == 'Высота')
		$result['sizes'][$ind]['height'] = $matches[2][$i];
	if ($matches[1][$i] == 'Длина')
		$result['sizes'][$ind]['length'] = $matches[2][$i];
	if ($matches[1][$i] == 'Вес')
		$result['sizes'][$ind]['weight'] = strval($matches[2][$i] * 1000);
	if ($matches[1][$i] == 'Диаметр') {
		if (!$result['sizes'][$ind]['width'])
			$result['sizes'][$ind]['width'] = $matches[2][$i];
		if (!$result['sizes'][$ind]['length'])
			$result['sizes'][$ind]['length'] = $matches[2][$i];
		if (!$result['sizes'][$ind]['height'])
			$result['sizes'][$ind]['height'] = $matches[2][$i];
		$k++;
	}
	$k++;
}

// Product place
if (isset($_GET['store'])) {
	$store_id = $_GET['store'];
	$avail = array();
	$item_type = $result['item_type'];
	exec("curl 'https://iows.ikea.com/retail/iows/ru/ru/stores/$store_id/availability/$item_type/$id' -H 'sec-fetch-mode: cors' -H 'origin: https://www.ikea.com' -H 'accept-encoding: gzip, deflate, br' -H 'accept-language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7' -H 'pragma: no-cache' -H 'consumer: MAMMUT' -H 'user-agent: Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Mobile Safari/537.36' -H 'accept: application/vnd.ikea.iows+json;version=1.0' -H 'cache-control: no-cache' -H 'authority: iows.ikea.com' -H 'referer: $product_url' -H 'sec-fetch-site: same-site' -H 'contract: 37249' -H 'dnt: 1' --compressed", $avail);
	$avail_array = json_decode($avail['0'], 1);

	foreach ($avail_array['StockAvailability']['RetailItemAvailability']['RetailItemCommChildAvailabilityList']['RetailItemCommChildAvailability'] as $key => $value) {
		$row = substr($value['RecommendedSalesLocation']['$'], 0, 2);
		$cell = substr($value['RecommendedSalesLocation']['$'], 2, 2);
		$result['items'][$key]['item'] = $value['ItemNo']['$'];
		$result['items'][$key]['row'] = $row . '/' . $cell;
		if ($row == "")
			$result['items'][$key]['place'] = "";
	}
	if (isset($avail_array['StockAvailability']['RetailItemAvailability']) && !is_array($avail_array['StockAvailability']['RetailItemAvailability']['RetailItemCommChildAvailabilityList']['RetailItemCommChildAvailability'])) {
		$row = substr($avail_array['StockAvailability']['RetailItemAvailability']['RecommendedSalesLocation']['$'], 0, 2);
		$cell = substr($avail_array['StockAvailability']['RetailItemAvailability']['RecommendedSalesLocation']['$'], 2, 2);
		$result['place'] = $row . '/' . $cell;
		if ($row == "")
			$result['place'] = "";
	}
	$stock = array();
	array_push($stock, $avail_array['StockAvailability']['RetailItemAvailability']['AvailableStock']['$']);
	foreach ($avail_array['StockAvailability']['AvailableStockForecastList']['AvailableStockForecast'] as $key => $value) {
		array_push($stock, $value['AvailableStock']['$']);
	}
	$result['stock'] = $stock;
}

echo json_encode($result);

function request($url, $timeout = 10, $proxy = false)
{
	$headers[] = "User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64; rv:61.0) Gecko/20100101 Firefox/61.0";
	$headers[] = "Accept: */*";
	$headers[] = "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3";

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_PROXY, $proxy);

	$data = curl_exec($ch);
	$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	$result['httpcode'] = $httpcode;
	$result['data'] = $data;
	return $result;
}

function str_to_date($str)
{
	if ($str == "")
		return $str;
	$str = trim($str);
	$mnths = array('01' => 'янв,', '02' => 'февр,', '03' => 'марта', '04' => 'апр,', '05' => 'мая,', '06' => 'июня,', '07' => 'июля,', '08' => 'авг,', '09' => 'сент,', '10' => 'окт,', '11' => 'нояб,', '12' => 'дек,');
	$mnth = explode(" ", $str)[1];
	return substr($str, -4) . '-' . array_search($mnth, $mnths) . '-' . substr($str, 0, 2);
}
