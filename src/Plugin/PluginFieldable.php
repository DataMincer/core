<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Exception\PluginException;
use DataMincerCore\Util;

class PluginFieldable extends Plugin implements PluginFieldableInterface {

//  /**
//   * @throws PluginException
//   */
//  public function bootstrap() {
//    parent::bootstrap();
//    $this->bootstrapFields($this->config);
//    // Evaluate this component  fields
////    $this->values = $this->evaluateFields($this->getConfig());
////    $this->setData([self::pluginType() => $this->values]);
//    // Continue bootstrapping
//  }

//  /**
//   * @param $config
//   * @throws PluginException
//   */
//  protected function bootstrapFields($config) {
//    foreach ($config as $key => $info) {
//      if (is_object($info) && $info instanceof PluginFieldInterface) {
//        $info->bootstrap();
//      }
//      else if (is_array($info) && count($info)) {
//        $this->bootstrapFields($info);
//      }
//    }
//  }

  /**
   * @param $config
   * @param array $data
   * @return array
   * @throws PluginException
   */
  protected function evaluateFields($config, $data = []) {
    $paths = Util::arrayPaths($config);
    $result = [];
    foreach ($paths as $path) {
      $this->evaluateFieldsByPath($result, $path, $config, $data);
    }
    return $result;
  }

  /**
   * @param $result
   * @param $path
   * @param $values
   * @param $data
   * @throws PluginException
   */
  protected function evaluateFieldsByPath(&$result, $path, $values, $data) {
    $leaf_key = array_pop($path);
    $r =& $result;
    foreach ($path as $part) {
      if (!isset($r[$part])) {
        $r[$part] = NULL;
      }
      $r =& $r[$part];
      $values = $values[$part];
    }
    $info = $values[$leaf_key];
    if (is_object($info)) {
      if ($info instanceof PluginFieldInterface) {
        $r[$leaf_key] = $this->evaluateField($info, $data + $result);
        return;
      }
    }
    else {
      $r[$leaf_key] = $info;
    }
    $r[$leaf_key] = $info;
  }

  /**
   * @param PluginFieldInterface $field
   * @param $data
   * @return mixed
   * @throws PluginException
   */
  protected function evaluateField(PluginFieldInterface $field, $data) {
    return $field->value($data);
  }

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
