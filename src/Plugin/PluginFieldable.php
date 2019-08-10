<?php

namespace DataMincerCore\Plugin;

class PluginFieldable extends Plugin implements PluginFieldableInterface {

  public function __isset($name) {
    return array_key_exists($name, $this->config);
  }

  public function &__get($name) {
    $result = NULL;
    if (array_key_exists($name, $this->config)) {
      $result = $this->config[$name];
    }
    return $result;
  }

  public function __set($name, $value) {
    if (array_key_exists($name, $this->config)) {
      $this->config[$name] = $value;
    }
    else {
      // Add this property
      $this->config[$name] = $value;
    }
  }

}
