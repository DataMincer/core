<?php

namespace DataMincerCore\Plugin;

class PluginFieldable extends Plugin implements PluginFieldableInterface {

  public function __isset($name) {
    return array_key_exists($name, $this->_config);
  }

  public function &__get($name) {
    $result = NULL;
    if (array_key_exists($name, $this->_config)) {
      $result = $this->_config[$name];
    }
    return $result;
  }

  public function __set($name, $value) {
    if (array_key_exists($name, $this->_config)) {
      $this->_config[$name] = $value;
    }
    else {
      // Add this property
      $this->_config[$name] = $value;
    }
  }

}
