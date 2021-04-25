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

// Loading page
$max_timout = 10;
$proxy = false;
$product_url = "https://www.ikea.com/ru/ru/catalog/products/$id";
$data = request($product_url, $max_timout, $proxy);

// Start parsing
$pq = phpQuery::newDocument($data['data']);

// Product title
$result['title'] = trim($pq->find('div.range-revamp-header-section__title--big')->html());
if ($result['title'] == "") {
    http_response_code(404);
    exit;
}

// Product image url
$pic = $pq->find('div.range-revamp-media-grid__grid > div.range-revamp-media-grid__media-container > span > img')->attr('src');
$result['pic'] = substr($pic , 0,-4);

// Product description
$description = $pq->find('div.range-revamp-pip-price-package > div > div> h1 > div > span.range-revamp-header-section__description-text');
$description = trim($description->html());

// Getting additional information for description like sizes and capacity
$pieces = $pq->find('div.range-revamp-pip-price-package__wrapper > div > div.range-revamp-pip-price-package__main-price > span > span.range-revamp-price__unit');
$pieces = trim(preg_replace("/\//", '', $pieces[0]->html()));
if (strlen($pieces) > 0) {
	$result['description'] .= ', ' . $pieces;
}

// Product price
// Checking current price
$price = $pq-> find('div.range-revamp-pip-price-package__wrapper > div > div.range-revamp-pip-price-package__main-price > span > span.range-revamp-price__integer');
$result['price'] = intval(preg_replace("/[^0-9]/", '', $price));

// Checking previous price
$result['discount'] = null;
$discount = $pq-> find('div.range-revamp-pip-price-package__content-left > div.range-revamp-pip-price-package__previous-price > span > span.range-revamp-price__integer');
$price_default = intval(preg_replace("/[^0-9]/", '', $discount));

// Checking the validity period of the discount
$matches_period = [];
$period = $pq-> find('span.range-revamp-pip-price-package__while-supply-last-text-date-range');
preg_match_all("/[0-9]{2}\.[0-9]{2}\.[0-9]{4}/", $period->html(), $matches_period);
$result['startDate'] = str_replace('.', '-', $matches_period[0][0]);
$result['endDate'] = str_replace('.', '-', $matches_period[0][1]);

// Swap price and previous price if the previous price is exist
if ($price_default != "" && strlen($period) > 0){
	$result['discount'] = $result['price'];
	$result['price'] = $price_default;
}

// Product type: ART (single article) или SPR (combination of few articles)
preg_match_all('/data-product-no="'.$id.'" data-product-type="(.*?)"/i', $data['data'], $matches);
$result['item_type'] = $matches[1][0];

// Product packages
$packages = $pq->find("#SEC_product-details-packaging > div > div.range-revamp-product-details__container > div.range-revamp-product-details__container");
$mblock = "";

foreach ($packages as $key => $value) {
    $mblock .= pq($value)->html();
}

preg_match_all("/(Ширина|Высота|Длина|Диаметр|Вес)\: (.*?) /iu", $mblock, $matches);
$k = 0;
for ($i = 0; $i < count($matches[1]); $i++) {
    $ind = (int)($k / 4);
    if ($matches[1][$i] == 'Ширина')
            $result['sizes'][$ind]['width'] = intval($matches[2][$i]);
    if ($matches[1][$i] == 'Высота')
            $result['sizes'][$ind]['height'] = intval($matches[2][$i]);
    if ($matches[1][$i] == 'Длина')
            $result['sizes'][$ind]['length'] = intval($matches[2][$i]);
    if ($matches[1][$i] == 'Вес')
            $result['sizes'][$ind]['weight'] = $matches[2][$i] * 1000;
    if ($matches[1][$i] == 'Диаметр') {
        if (!$result['sizes'][$ind]['width'])
            $result['sizes'][$ind]['width'] =  intval($matches[2][$i]);
        if (!$result['sizes'][$ind]['length'])
            $result['sizes'][$ind]['length'] =  intval($matches[2][$i]);
        if (!$result['sizes'][$ind]['height'])
            $result['sizes'][$ind]['height'] =  intval($matches[2][$i]);
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,true);
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
