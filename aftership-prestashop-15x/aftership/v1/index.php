<?php

error_reporting(0);

if (!function_exists('http_response_code'))
{
	function http_response_code($new_code = NULL)
	{
		static $code = 200;
		if($new_code !== NULL)
		{
			header('X-PHP-Response-Code: '.$new_code, true, $new_code);
			if(!headers_sent())
				$code = $new_code;
		}
		return $code;
	}
}

if( !function_exists('apache_request_headers') ) {

	function apache_request_headers() {
		$arh = array();
		$rx_http = '/\AHTTP_/';
		foreach($_SERVER as $key => $val) {
			if( preg_match($rx_http, $key) ) {
				$arh_key = preg_replace($rx_http, '', $key);
				$rx_matches = array();
				// do some nasty string manipulations to restore the original letter case
				// this should work in most cases
				$rx_matches = explode('_', $arh_key);
				if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
					foreach($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
					$arh_key = implode('-', $rx_matches);
				}
				$arh[$arh_key] = $val;
			}
		}
		return( $arh );
	}
}

function checkEnabled($shop_id) {
	global $db;

	$q = "SELECT * FROM `"._DB_PREFIX_."module` m, `"._DB_PREFIX_."module_shop` ms
		  WHERE m.`id_module` = ms.`id_module`
		  AND m.`name` = 'aftership'
		  AND m.`active` = '1'
		  AND ms.`id_shop` = '".$shop_id."'
		  LIMIT 1";

	$r = $db->query($q);
	if ($r->num_rows != 1) {
		render(403, 'Module disabled');
	}

	return ($r->num_rows != 1);
}

function getKey() {
	//get the key here
	$headers = apache_request_headers();

	$token = $headers['X-PrestaShop-Token'];
	if (!$token) {
		$token = $headers['X-PRESTASHOP-TOKEN'];
	}
	if (!$token) {
		$token = $headers['X-Prestashop-Token'];
	}

	if (!$token) {
		foreach ($headers as $header_key => $header_value) {
			if ($header_key == 'X-PrestaShop-Token' || $header_key == 'X-PRESTASHOP-TOKEN') {
				$token = $header_value;
			}
		}
	}
	return $token;
}

function checkKey($token) {
	global $db;

	$token = getKey();

	if (!isset($token)) {
		render(401, 'Unauthorized');
	} else {
		$q = "SELECT wa.`active`, was.`id_shop` FROM `"._DB_PREFIX_."webservice_account` wa, `"._DB_PREFIX_."webservice_account_shop` was
		WHERE wa.`key` = '".$db->real_escape_string($token)."'
		AND wa.`active` = '1'
		AND was.`id_webservice_account` = wa.`id_webservice_account`
		LIMIT 1";

		$r = $db->query($q);
		if ($r->num_rows == 1) {
			$d = $r->fetch_assoc();
			return $d['id_shop'];
		} else {
			render(401, 'Unauthorized');
		}
	}

	return -1;
}

function render($code, $error_msg = '', $data = array()) {
	$output = array();
	$output['meta'] = array();
	$output['meta']['code'] = $code;
	$output['meta']['error_msg'] = $error_msg;
	$output['data'] = array();
	foreach ($data as $key=>$value) {
		$output['data'][$key] = $value;
	}

	http_response_code($code);
	header('Content-type: application/json');
	echo json_encode($output);
	exit();
}

function auth() {
	render(200);
}

function info() {
	render(200, null, array('version_prestashop' => constant('_PS_VERSION_'), 'version_plugin' => '1.0.7'));
}

function orders($shop_id) {
	global $db;

	$created_at_max 	= isset($_GET['created_at_max'])?(int)trim($_GET['created_at_max']):NULL;
	$updated_at_max 	= isset($_GET['updated_at_max'])?(int)trim($_GET['updated_at_max']):NULL;
	$last_created_at 	= isset($_GET['last_created_at'])?(int)trim($_GET['last_created_at']):(time() - 3 * 60*60*24);
	$last_updated_at 	= isset($_GET['last_updated_at'])?(int)trim($_GET['last_updated_at']):(time() - 3 * 60*60*24);
	$page 				= isset($_GET['page'])?(int)trim($_GET['page']):1;
	$limit 				= isset($_GET['limit'])?(int)trim($_GET['limit']):100;

	$last_created_at = gmdate('Y-m-d H:i:s', $last_created_at);
	$last_updated_at = gmdate('Y-m-d H:i:s', $last_updated_at);

	// by default handle as old version
	$time_criteria = "AND o.`date_add` > '".$last_created_at."' AND o.`date_upd` > '".$last_updated_at."'";

	// handle as new version if created_at_max and updated_at_max are set
	if ($created_at_max != NULL && $updated_at_max != NULL) {
		$time_criteria = "AND o.`date_add` < '".gmdate('Y-m-d H:i:s', $created_at_max)."' AND o.`date_upd` < '".gmdate('Y-m-d H:i:s', $updated_at_max)."'";
	}

	if ($page < 1) {
		$page = 1;
	}

	if ($limit > 200) {
		$limit = 200;
	}

	$offset = ($page - 1) * $limit;

	$q = "SELECT o.`date_add`, o.`date_upd`, o.`reference`, o.`shipping_number`, c.`firstname`, c.`lastname`, c.`email`, a.`address1`, a.`address2`, a.`postcode`, a.`city`, a.`phone`, a.`phone_mobile`, cl.`iso_code` as country_name, s.`name` as state_name, ca.`name` as slug
		  FROM `"._DB_PREFIX_."orders` o, `"._DB_PREFIX_."customer` c, `"._DB_PREFIX_."country` cl, `"._DB_PREFIX_."carrier` ca, `"._DB_PREFIX_."address` a
		  LEFT JOIN `"._DB_PREFIX_."state` s
		  ON s.`id_state` = a.`id_state`
		  WHERE o.`id_customer` = c.`id_customer`
		  AND o.`id_carrier` = ca.`id_carrier`
		  AND o.`id_address_delivery` = a.`id_address`
		  ".$time_criteria."
		  AND o.`shipping_number` != ''
		  AND o.`id_shop` = '".$shop_id."'
		  AND a.`id_country` = cl.`id_country`
		  ORDER BY o.`date_upd` DESC
		  LIMIT ".$offset.", ".$limit;

	$r = $db->query($q);

	$orders = array();

    $order_ids = array();

    for ($i=0;$i<$r->num_rows;$i++) {
		$d = $r->fetch_assoc();

		$addresses = array();
		if ($d['address1']) {
			$addresses[] = $d['address1'];
		}

		if ($d['address2']) {
			$addresses[] = $d['address2'];
		}

		$orders[] = array(
			'created_at' => $d['date_add'],
			'updated_at' => $d['date_upd'],
			'destination_country_iso2' => $d['country_name'],
			'destination_state' => $d['state_name'],
			'destination_city' => $d['city'],
			'destination_zip' => $d['postcode'],
			'destination_address' => join(', ', $addresses),
			'tracking_number' => strtoupper($d['shipping_number']),
			'customer_name' => $d['firstname'].' '.$d['lastname'],
			'emails' => array($d['email']),
			'order_id' => $d['reference'],
			'smses' => $d['phone_mobile']?array($d['phone_mobile']):array($d['phone']),
			'slug' => $d['slug']?array($d['slug']):array($d['slug']),
			'products' => array()
		);

		$order_ids[] = '"'.$d['reference'].'"';
	}

	$q = "SELECT o.`reference`, od.`product_name` as product_name, od.`product_quantity` as product_quantity
		  FROM `"._DB_PREFIX_."orders` o, `"._DB_PREFIX_."order_detail` od
		  WHERE o.id_order = od.id_order
		  AND o.reference in (".implode(",", $order_ids).")
		  ";

	$r = $db->query($q);

	for ($i=0;$i<$r->num_rows;$i++) {
		$d = $r->fetch_assoc();

		$n = count($orders);
		for ($j=0;$j<$n; $j++){
			if ($orders[$j]['order_id'] === $d['reference']){
				$orders[$j]['products'][] = array(
					'name' => $d['product_name'],
					'quantity' => intval($d['product_quantity'])
				);
			}
		}
	}

	$q = "SELECT o.`reference`, GROUP_CONCAT(m.`message` SEPARATOR ', ') AS msg
		  FROM `"._DB_PREFIX_."customer_message` m, `"._DB_PREFIX_."customer_thread` t, `"._DB_PREFIX_."orders` o
		  WHERE t.id_customer_thread = m.id_customer_thread
		  AND t.id_order = o.id_order
		  AND o.reference IN (".implode(",", $order_ids).")
		  GROUP BY t.id_order
		  ";

	$r = $db->query($q);

	for ($i=0;$i<$r->num_rows;$i++) {
		$d = $r->fetch_assoc();

		$n = count($orders);
		for ($j=0;$j<$n; $j++){
			if ($orders[$j]['order_id'] === $d['reference']){
				$orders[$j]['note'] = $d['msg'];
			}
		}
	}
	render(200, null, array('orders' => $orders, 'page' => $page, 'limit' => $limit, 'store_id' => $shop_id));
}

function getHostData($host_name) {
	$host_data = explode(':', $host_name);
	if (count($host_data) == 2) {
		return array('host' => $host_data[0], 'port' => $host_data[1]);
	} else {
		return array('host' => $host_data[0], 'port' => 3306);
	}
}

//////////////////////////////////////////////////////

require('../../../config/settings.inc.php');

//db connection
//if unable to connect, then die
$host_data = getHostData(_DB_SERVER_);
$db = new mysqli($host_data['host'], _DB_USER_, _DB_PASSWD_, _DB_NAME_, $host_data['port']);

/* check connection */
if ($db->connect_errno) {
	render(500, 'Database error');
	exit();
}

/* utf-8 */
$db->query("SET NAMES utf8");

$token = getKey();

/* check api key */
$store_id = checkKey($token);

/* check if module enabled */
checkEnabled($store_id);

$action = isset($_GET['action'])?$_GET['action']:'';

switch ($action) {
	case "auth":
		auth();
		break;
	case "orders":
		orders($store_id);
		break;
	case "info":
		info();
		break;
	default:
		render(500, 'Action not supported');
		break;
}

?>
