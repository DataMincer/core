<?php

namespace DataMincerCore;

use Iterator;
use YamlElc\Bundle;

class Decks implements Iterator {

  protected $products = [];
  protected $productsInfo = [];
  protected $items = [];
  protected $itemCallback;
  protected $position = 0;
  protected $bundle;
  /** @var State */
  protected $state;

  public function __construct(Bundle $bundle, $item_callback) {
    $this->state = new State($bundle->getExtraData()['path']);
    $this->products = $bundle->getProducts();
    $this->productsInfo = $bundle->getProductsInfo();
    $this->bundle = $bundle;
    $this->itemCallback = $item_callback;
    $this->position = 0;
  }

  public function current() {
    if (!array_key_exists($this->position, $this->items)) {
      $config = $this->products[$this->position];
      $origin = $this->prepareOrigin($this->productsInfo[$this->position]);
      $data = [
        'origin' => $origin,
        'version' => $config['version'] ?? DataMincer::VERSION,
        'bundle' => [
          'name' => $this->bundle->name(),
        ] + $this->bundle->getExtraData()
      ];
      $this->items[$this->position] = call_user_func($this->itemCallback, $config, $this->state, $data);
    }
    return $this->items[$this->position];
  }

  protected function prepareOrigin($info) {
    $result = [];
    foreach ($info as $dimension_name => $registers_info) {
      foreach ($registers_info as $register => $domain_info) {
        foreach ($domain_info as $domain_name => $value) {
          $result[$dimension_name][$register] = [
            'domain' => $domain_name,
            'value' => $value,
          ];
        }
      }
    }
    return $result;
  }

  public function next() {
    ++$this->position;
  }

  public function key() {
    return $this->position;
  }

  public function valid() {
    return array_key_exists($this->position, $this->products);
  }

  public function rewind() {
    $this->position = 0;
  }

  public function count() {
    return count($this->products);
  }

}
