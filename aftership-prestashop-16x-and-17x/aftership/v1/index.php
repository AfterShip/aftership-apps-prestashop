<?php


error_reporting(0);

class AfterShipConnector
{
    private $dbPrefix;
    /** @var $db mysqli */
    private $db;
    private $shopId;

    /**
     * AfterShipConnector constructor.
     * @param $dbHost
     * @param $dbPort
     * @param $dbUser
     * @param $dbPassword
     * @param $dbName
     * @param $dbPrefix
     */
    public function __construct($dbHost, $dbPort, $dbUser, $dbPassword, $dbName, $dbPrefix)
    {
        $this->dbPrefix = $dbPrefix;
        $this->db = $this->getDatabaseConnection($dbHost, $dbPort, $dbUser, $dbPassword, $dbName);
        $this->shopId = $this->getShopId();
        $this->checkEnabled();
        $this->checkKey();
    }

    /**
     * connect to database
     * @param $dbHost
     * @param $dbPort
     * @param $dbUser
     * @param $dbPassword
     * @param $dbName
     * @return mysqli
     */
    private function getDatabaseConnection($dbHost, $dbPort, $dbUser, $dbPassword, $dbName)
    {
        $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
        if ($db->connect_errno) {
            $this->render(500, 'Database error');
        }
        $db->query("SET NAMES utf8");
        return $db;
    }

    /**
     * get shop id by domain
     * @return int
     */
    private function getShopId()
    {
        $q = "SELECT `id_shop` FROM `" . $this->dbPrefix . "shop_url` WHERE `domain` = '" . $this->db->real_escape_string($_SERVER['HTTP_HOST']) . "' LIMIT 1";
        $r = $this->db->query($q);
        if ($r->num_rows == 0) {
            $this->render(401, 'Unauthorized');
        }
        $d = $r->fetch_assoc();
        return (int)$d['id_shop'];
    }

    /**
     * check if plugin is enabled
     * @return bool
     */
    private function checkEnabled()
    {

        $q = "SELECT * FROM `" . $this->dbPrefix . "module` m, `" . $this->dbPrefix . "module_shop` ms
		  WHERE m.`id_module` = ms.`id_module`
		  AND m.`name` = 'aftership'
		  AND m.`active` = '1'
		  AND ms.`id_shop` = '" . $this->shopId . "'
		  LIMIT 1";

        $r = $this->db->query($q);
        if ($r->num_rows != 1) {
            $this->render(403, 'Module disabled');
        }

        return ($r->num_rows != 1);
    }

    /**
     * verify apiKey
     * @return bool
     */
    private function checkKey()
    {

        //get the key here
        $headers = $this->apache_request_headers();

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

        if (!isset($token)) {
            $this->render(401, 'Unauthorized');
        } else {
            $q = "SELECT wa.`active` FROM `" . $this->dbPrefix . "webservice_account` wa, `" . $this->dbPrefix . "webservice_account_shop` was
		WHERE wa.`key` = '" . $this->db->real_escape_string($token) . "'
		AND wa.`active` = '1'
		AND was.`id_shop` = '" . $this->shopId . "'
		AND was.`id_webservice_account` = wa.`id_webservice_account`";

            $r = $this->db->query($q);
            if ($r->num_rows == 1) {
                return true;
            } else {
                $this->render(401, 'Unauthorized');
            }
        }

        return false;
    }

    /**
     * get token from http headers
     * @return array
     */
    private function apache_request_headers()
    {
        $arh = array();
        $rx_http = '/\AHTTP_/';
        foreach ($_SERVER as $key => $val) {
            if (preg_match($rx_http, $key)) {
                $arh_key = preg_replace($rx_http, '', $key);
                $rx_matches = array();
                // do some nasty string manipulations to restore the original letter case
                // this should work in most cases
                $rx_matches = explode('_', $arh_key);
                if (count($rx_matches) > 0 and strlen($arh_key) > 2) {
                    foreach ($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
                    $arh_key = implode('-', $rx_matches);
                }
                $arh[$arh_key] = $val;
            }
        }
        return ($arh);
    }

    /**
     * render apit return json
     * @param $code
     * @param string $error_msg
     * @param array $data
     */
    private function render($code, $error_msg = '', $data = array())
    {
        $output = array();
        $output['meta'] = array();
        $output['meta']['code'] = $code;
        $output['meta']['error_msg'] = $error_msg;
        $output['data'] = array();
        foreach ($data as $key => $value) {
            $output['data'][$key] = $value;
        }

        $this->http_response_code($code);
        header('Content-type: application/json');
        echo json_encode($output);
        exit();
    }

    /**
     * check connection
     */
    private function auth()
    {
        $this->render(200);
    }

    /**
     * get orders
     * by default it will sync last 3 days
     */
    private function orders()
    {

        $last_created_at = isset($_GET['last_created_at']) ? (int)trim($_GET['last_created_at']) : (time() - 3 * 60 * 60 * 24);
        $last_updated_at = isset($_GET['last_updated_at']) ? (int)trim($_GET['last_updated_at']) : (time() - 3 * 60 * 60 * 24);
        $page = isset($_GET['page']) ? (int)trim($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)trim($_GET['limit']) : 100;

        $last_created_at = gmdate('Y-m-d H:i:s', $last_created_at);
        $last_updated_at = gmdate('Y-m-d H:i:s', $last_updated_at);

        if ($page < 1) {
            $page = 1;
        }

        if ($limit > 200) {
            $limit = 200;
        }

        $offset = ($page - 1) * $limit;

        $q = "SELECT o.`reference`, o.`shipping_number`, oc.`tracking_number`, c.`firstname`, c.`lastname`, c.`email`, a.`address1`, a.`address2`, a.`postcode`, a.`city`, a.`phone`, a.`phone_mobile`, cl.`name` as country_name, s.`name` as state_name
		  FROM `" . $this->dbPrefix . "orders` o, `" . $this->dbPrefix . "order_carrier` oc, `" . $this->dbPrefix . "customer` c, `" . $this->dbPrefix . "country` co, `" . $this->dbPrefix . "country_lang` cl, `" . $this->dbPrefix . "address` a
		  LEFT JOIN `" . $this->dbPrefix . "state` s
		  ON s.`id_state` = a.`id_state`
		  WHERE o.`id_customer` = c.`id_customer`
		  AND o.`id_order` = oc.`id_order`
		  AND o.`id_address_delivery` = a.`id_address`
		  AND o.`date_add` > '" . $last_created_at . "'
		  AND o.`date_upd` > '" . $last_updated_at . "'
		  AND o.`id_shop` = '" . $this->shopId . "'
		  AND a.`id_country` = co.`id_country`
		  AND co.`id_country` = cl.`id_country`
		  AND cl.`id_lang` = '1'
		  ORDER BY o.`date_upd` DESC
		  LIMIT " . $offset . ", " . $limit;

        $r = $this->db->query($q);

        $orders = array();

        $order_ids = array();

        for ($i = 0; $i < $r->num_rows; $i++) {
            $d = $r->fetch_assoc();

            $addresses = array();
            if ($d['address1']) {
                $addresses[] = $d['address1'];
            }

            if ($d['address2']) {
                $addresses[] = $d['address2'];
            }

            // consolidate tracking number, if shipping_number is empty, then use tracking_number instead
            $tracking_number = $d['shipping_number'];
            if (empty($d['shipping_number'])) {
                $tracking_number = $d['tracking_number'];
            }

            $orders[] = array(
                'destination_country_name' => $d['country_name'],
                'destination_country_iso3' => '',
                'destination_state' => $d['state_name'],
                'destination_city' => $d['city'],
                'destination_zip' => $d['postcode'],
                'destination_address' => join(', ', $addresses),
                'tracking_number' => strtoupper($tracking_number),
                'name' => $d['firstname'] . ' ' . $d['lastname'],
                'emails' => array($d['email']),
                'order_id' => $d['reference'],
                'smses' => $d['phone_mobile'] ? array($d['phone_mobile']) : array($d['phone']),
                'products' => array()
            );

            $order_ids[] = '"' . $d['reference'] . '"';
        }

        $q = "SELECT o.`reference`, od.`product_name` as product_name, od.`product_quantity` as product_quantity
		  FROM `" . $this->dbPrefix . "orders` o, `" . $this->dbPrefix . "order_detail` od
		  where o.id_order = od.id_order
		  AND o.reference in (" . implode(",", $order_ids) . ")
		  ";

        $r = $this->db->query($q);

        for ($i = 0; $i < $r->num_rows; $i++) {
            $d = $r->fetch_assoc();

            $n = count($orders);
            for ($j = 0; $j < $n; $j++) {
                if ($orders[$j]['order_id'] === $d['reference']) {
                    $orders[$j]['products'][] = array(
                        'name' => $d['product_name'],
                        'quantity' => intval($d['product_quantity'])
                    );
                }
            }
        }

        $this->render(200, null, array('orders' => $orders, 'page' => $page, 'limit' => $limit));
    }


    /**
     * send out a http response code
     * @param null $new_code
     * @return int|null
     */
    private function http_response_code($new_code = NULL)
    {
        static $code = 200;
        if ($new_code !== NULL) {
            header('X-PHP-Response-Code: ' . $new_code, true, $new_code);
            if (!headers_sent())
                $code = $new_code;
        }
        return $code;
    }

    /**
     * get database host and port from string
     * @param $host_name
     * @return array
     */
    public static function getHostData($host_name)
    {
        $host_data = explode(':', $host_name);
        if (count($host_data) == 2) {
            return array('host' => $host_data[0], 'port' => $host_data[1]);
        }
        return array('host' => $host_data[0], 'port' => 3306);
    }

    /**
     * process all the api request
     * @param $action
     */
    public function process($action)
    {
        switch ($action) {
            case "auth":
                $this->auth();
                break;
            case "orders":
                $this->orders();
                break;
            default:
                $this->render(500, 'Action not supported');
                break;
        }
    }

}

//////////////////////////////////////////////////////

// for 1.5.x and 1.6.x
if (file_exists('../../../config/settings.inc.php')) {
    require('../../../config/settings.inc.php');
    $host_data = AfterShipConnector::getHostData(_DB_SERVER_);
    $database_host = $host_data['host'];
    $database_port = $host_data['port'];
    $database_name = _DB_NAME_;
    $database_user = _DB_USER_;
    $database_password = _DB_PASSWD_;
    $database_prefix = _DB_PREFIX_;
}

// for 1.7.x
if (file_exists('../../../app/config/parameters.php')) {
    $config = require('../../../app/config/parameters.php');
    $parameters = $config['parameters'];
    $database_host = $parameters['database_host'];
    $database_port = 3306;
    if (!empty($parameters['database_port'])) {
        $database_port = $parameters['database_port'];
    }
    $database_name = $parameters['database_name'];
    $database_user = $parameters['database_user'];
    $database_password = $parameters['database_password'];
    $database_prefix = $parameters['database_prefix'];
}

// create aftership connector instance
$aftership = new AfterShipConnector($database_host, $database_port, $database_user, $database_password, $database_name, $database_prefix);
$action = isset($_GET['action']) ? $_GET['action'] : '';
// do the trick
$aftership->process($action);

?>
