<?php

class CdekApi
{
  protected static $_instance = null;

  public $token = "";
  public $client = null;
  public $weight = null;
  public $tariff = null;
  public $woocommerce = null;
  public $mygeo = null;

  public static function instance()
  {
    if (is_null(self::$_instance)) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  private function __construct()
  {
    $this->woocommerce = $GLOBALS["woocommerce"];
    $this->mygeo = $GLOBALS["mygeo"];
    $this->get_token();
  }

  private function get_token()
  {
    /* Тестовые учетные данные */
    $this->client = new GuzzleHttp\Client([
      "base_uri" => "https://api.edu.cdek.ru/v2/",
    ]);

    $response = $this->client->request("POST", "oauth/token?parameters", [
      "form_params" => [
        "grant_type" => "client_credentials",
        "client_id" => "EMscd6r9JnFiQ3bLoyjJY6eM78JrJceI",
        "client_secret" => "PjLZkKBHEiLK3YsjtNrt3TGNG0ahs3kG",
      ],
    ]);
    

    $this->token = json_decode($response->getBody(), true);
  }

  public function get_tariff($tariff_code)
  {
    $this->weight = round($this->woocommerce->cart->get_cart_contents_weight(), 0, PHP_ROUND_HALF_UP);

    $response = $this->client->request("POST", "calculator/tariff", [
      "headers" => [
        "Authorization" => "Bearer " . $this->token["access_token"],
      ],
      "json" => [
        "tariff_code" => $tariff_code,
        "type" => 1,
        "from_location" => [
          "code" => "148",
        ],
        "to_location" => [
          "code" => $this->mygeo->state["cdek_city_code"],
        ],
        "packages" => [
          [
            "weight" => $this->weight,
          ],
        ],
      ],
    ]);

    $this->tariff = json_decode($response->getBody(), true);
  }

  public function get_pvz()
  {
    function sort_cdek_data_pvz($response)
    {
      //сортируем пвз по городам
      $cdek_data = [];
      foreach ($response as $item) {
        $cdek_data["pvz_city_lists"][$item["location"]["city_code"]][$item["code"]] = $item;
      }
      split_cdek_data($cdek_data);
    }

    function split_cdek_data($result)
    {
      //разбиваем общую базу на отдельные файлы для каждого города
      foreach ($result["pvz_city_lists"] as $city_code => $city_list) {
        $city_data = [];
        $city_data["pvz"] = [];
        $city_data["postamat"] = [];
        foreach ($city_list as $pvz_code => $pvz) {
          if($pvz["type"] == "PVZ"){
            $city_data["pvz"][$pvz_code] = $pvz;
          }
          elseif($pvz["type"] == "POSTAMAT"){
            $city_data["postamat"][$pvz_code] = $pvz;
          }
        }
        save_json($city_data, $city_code);
      }
    }

    function save_json($arr, $city_code)
    {
      if (!file_exists(MYCDEK_PLUGIN_DIR . "assets/json") && !is_dir(MYCDEK_PLUGIN_DIR . "assets/json")) {
        mkdir(MYCDEK_PLUGIN_DIR . "assets/json", 0700, true);
      }

      $fp = fopen(MYCDEK_PLUGIN_DIR . "assets/json/pvz_" . $city_code . ".json", "w");
      fwrite($fp, json_encode($arr));
      fclose($fp);
    }

    //запрашиваем список всех пвз для россии
    try {
      $response = $this->client->request("GET", "deliverypoints", [
        "headers" => [
          "Authorization" => "Bearer " . $this->token["access_token"],
        ],
        "query" => [
          "type" => "ALL",
          "country_code" => "RU",
        ],
      ]);
    } catch (RequestException $e) {
      echo Psr7\Message::toString($e->getRequest());
      if ($e->hasResponse()) {
        echo Psr7\Message::toString($e->getResponse());
      }
    }

    $this->tariff = json_decode($response->getBody(), true);

    sort_cdek_data_pvz($this->tariff);
  }

  public function reg_order()
  {
    $order_id = (int) $_GET["order_id"];
    //получаем инфу о клиенте
    $order = wc_get_order($order_id);
    $items = $order->get_items();
    $key = key($order->get_items("shipping"));
    $shipping_method = $order->get_shipping_methods()[$key];
    preg_match("/\d.*/", $shipping_method->get_method_id(), $tariff_code);

    $billing["fio"] =
      $order->get_billing_last_name() .
      " " .
      $order->get_billing_first_name() .
      " " .
      get_post_meta($order->id, "Отчество", true);
    $billing["phone"] = $order->get_billing_phone();
    $billing["address_1"] = $order->get_billing_address_1();
    $billing["house"] = get_post_meta($order->id, "Дом", true);
    $cdek["weight"] = explode(" ", $shipping_method->get_meta("Общий вес товара"));

    $delivery_type = $shipping_method->get_meta("Тип доставки");

    if ($delivery_type == "Самовывоз") {
      $cdek["pvz"]["raw"] = $shipping_method->get_meta("Данные пункта выдачи");
      $cdek["pvz"]["arr"] = json_decode(strip_tags($cdek["pvz"]["raw"]), true);

      try {
        $response = $this->client->request("POST", "orders", [
          "headers" => [
            "Authorization" => "Bearer {$this->token["access_token"]}",
          ],
          //самовывоз
          "json" => [
            "number" => (string) $order_id,
            "tariff_code" => $tariff_code[0],
            "shipment_point" => "NEK1",
            "delivery_point" => $cdek["pvz"]["arr"]["code"],
            "recipient" => [
              "name" => $billing["fio"],
              "phones" => [
                [
                  "number" => $billing["phone"],
                ],
              ],
            ],
            "packages" => [
              "number" => (string) $order_id,
              "weight" => $cdek["weight"][0],
              /*"length" => 1,
                            "width" => 1,
                            "height" => 1,*/
              "items" => [
                [
                  "name" => "Ткани",
                  "ware_key" => (string) $order_id,
                  "payment" => [
                    "value" => 0,
                  ],
                  "cost" => $order->get_subtotal(),
                  "weight" => $cdek["weight"][0],
                  "amount" => 1,
                ],
              ],
            ],
          ],
        ]);
      } catch (GuzzleHttp\Exception\RequestException $e) {
        echo "<pre>";
        echo GuzzleHttp\Psr7\Message::toString($e->getRequest()) . "\n\n";
        if ($e->hasResponse()) {
          echo GuzzleHttp\Psr7\Message::toString($e->getResponse()) . "\n\n";
        }
        echo "</pre>";
      }
    } elseif ($delivery_type == "Курьером") {
      $address =
        WC()->countries->get_countries()[$order->get_billing_country()] .
        ", " .
        WC()->countries->get_states()[$order->get_billing_country()][$order->get_billing_state()] .
        ", г." .
        $order->get_billing_city() .
        ", ул." .
        $order->get_billing_address_1() .
        ", д." .
        get_post_meta($order->id, "Дом", true) .
        ", стр." .
        get_post_meta($order->id, "Строение", true) .
        ", корп." .
        get_post_meta($order->id, "Корпус", true) .
        ", кв." .
        get_post_meta($order->id, "Квартира", true) .
        ", " .
        $order->get_billing_postcode();

      try {
        $response = $this->client->request("POST", "orders", [
          "headers" => [
            "Authorization" => "Bearer {$this->token["access_token"]}",
          ],
          "json" => [
            "number" => (string) $order_id,
            "tariff_code" => $tariff_code[0],
            "shipment_point" => "NEK1",
            "recipient" => [
              "name" => $billing["fio"],
              "phones" => [
                [
                  "number" => $billing["phone"],
                ],
              ],
            ],
            "to_location" => [
              "address" => $address,
            ],
            "packages" => [
              "number" => (string) $order_id,
              "weight" => $cdek["weight"][0],
              /*"length" => 1,
                            "width" => 1,
                            "height" => 1,*/
              "items" => [
                [
                  "name" => "Ткани",
                  "ware_key" => (string) $order_id,
                  "payment" => [
                    "value" => 0,
                  ],
                  "cost" => $order->get_subtotal(),
                  "weight" => $cdek["weight"][0],
                  "amount" => 1,
                ],
              ],
            ],
          ],
        ]);
      } catch (GuzzleHttp\Exception\RequestException $e) {
        echo "<pre>";
        echo GuzzleHttp\Psr7\Message::toString($e->getRequest()) . "\n\n";
        if ($e->hasResponse()) {
          echo GuzzleHttp\Psr7\Message::toString($e->getResponse()) . "\n\n";
        }
        echo "</pre>";
      }
    }

    /*
      echo json_encode(array(
          "number" => (string) $order_id,
          "tariff_code" => $cdek["delivery"]["arr"]["tariff_code"],
          "shipment_point" => "NEK1",
          "delivery_point" => $cdek["pvz"]["arr"]["code"],
          "recipient" => array(
              "name" => $billing["fio"],
              "phones" => array(
                  "number" => $billing["phone"],
              ),
          ),
          "packages" => array(
              "number" => (string) $order_id,
              "weight" => $cdek["weight"][0],
              "length" => 1,
              "width" => 1,
              "height" => 1,
              "items" => array(
                  array(
                      "name" => "Ткани",
                      "ware_key" => (string) $order_id,
                      "payment" => 0,
                      "value" => 0,
                      "cost" => $order->get_subtotal(),
                      "weight" => $cdek["weight"][0],
                      "amount" => 1,
                  )
              ),
          ),
      ));
      */
    $cdek_order = json_decode($response->getBody(), true);

    $this->get_order_info($cdek_order["entity"]["uuid"], $shipping_method);
    die();
  }
  public function get_order_info($cdek_number, $shipping_method)
  {
    try {
      $response = $this->client->request("GET", "orders/$cdek_number", [
        "headers" => [
          "Authorization" => "Bearer {$this->token["access_token"]}",
        ],
      ]);
    } catch (GuzzleHttp\Exception\RequestException $e) {
      echo "<pre>";
      echo GuzzleHttp\Psr7\Message::toString($e->getRequest()) . "\n\n";
      if ($e->hasResponse()) {
        echo GuzzleHttp\Psr7\Message::toString($e->getResponse()) . "\n\n";
      }
      echo "</pre>";
    }
    $str = json_encode(json_decode($response->getBody(), true));
    $shipping_method->update_meta_data("Данные CDEK", "<span class=app-order>$str</span>");
    $shipping_method->save();
    echo "true";
  }
}
