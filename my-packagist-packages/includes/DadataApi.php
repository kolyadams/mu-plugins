<?php

class DadataApi
{
  protected static $_instance = null;

  private $token = "956056d50c1c1a63d794498c1b57f87583fcc209";
  private $client = null;

  public $state = [
    "address" => null,
    "suggestions" => null,
    "deliveryCodes" => null,
  ];

  private function __construct()
  {
    $this->client = new GuzzleHttp\Client(["base_uri" => "https://suggestions.dadata.ru/suggestions/api/4_1/rs/"]);
    $this->init();
  }

  public static function instance()
  {
    if (is_null(self::$_instance)) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  public function init()
  {
    if (isset($_GET["action"]) && $_GET["action"] == "getSuggestions") {
      $this->getSuggestions($_GET["sugQuery"]);
    } elseif (isset($_GET["action"]) && $_GET["action"] == "getDeliveryCodes") {
      $this->getDeliveryCodes($_GET["kladr"]);
    }
  }

  public function initMyGeo()
  {
    $this->getGeo();
    $this->getDeliveryCodesJson($this->state["address"]["location"]["data"]["city_kladr_id"]);
  }

  public function getSuggestions($address)
  {
    $this->getSuggestionsJson($address);
    echo json_encode($this->state["suggestions"]);
    die();
  }

  public function getDeliveryCodes($kladr)
  {
    $this->getDeliveryCodesJson($kladr);
    echo json_encode($this->state["deliveryCodes"]);
    die();
  }

  public function getGeo()
  {
    // This creates the Reader object, which should be reused across
    // lookups.
    $ip = WC_Geolocation::get_ip_address();
    //$ip = "109.184.14.163"; //нижний новгород
    //$ip = "109.205.253.39"; //питер
    //$ip = "79.105.134.131"; //блоговещенск
    //$ip = "178.219.186.12"; //москва
    //$ip = "94.29.124.215"; //москва мгтс
    //$ip = "188.191.19.242"; //крым
    //$ip = "109.248.235.1"; //Крым Ялта
    //$ip = "109.200.128.1"; //Крым Симферополь
    //$ip = "193.25.120.22"; //Крым Алушта
    //$ip = "178.217.152.4"; //адыгея
    //$ip = "37.212.56.78"; //Белоруссия → Витебская Oбласть → Сенно
    //$ip = "209.95.50.129"; //США Нью-Йорк
    //$ip = "95.108.181.101"; //США Нью-Йорк
    //$ip = "45.141.156.14"; //США Нью-Йорк

    $response = $this->client->request("GET", "iplocate/address", [
      "headers" => [
        "Authorization" => "Token " . $this->token,
      ],
      "query" => [
        "ip" => $ip,
      ],
    ]);

    $this->state["address"] = json_decode($response->getBody(), true);
  }

  public function getSuggestionsJson($address)
  {
    $response = $this->client->request("POST", "suggest/address", [
      "headers" => [
        "Authorization" => "Token " . $this->token,
      ],
      "json" => [
        "query" => $address,
        "from_bound" => ["value" => "city"],
        "to_bound" => ["value" => "city"],
      ],
    ]);

    $this->state["suggestions"] = json_decode($response->getBody(), true);
  }

  public function getDeliveryCodesJson($kladr)
  {
    $response = $this->client->request("POST", "findById/delivery", [
      "headers" => [
        "Authorization" => "Token " . $this->token,
      ],
      "json" => [
        "query" => $kladr,
      ],
    ]);

    $this->state["deliveryCodes"] = json_decode($response->getBody(), true);
  }
}