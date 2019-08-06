<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Exception\PluginException;

/**
 * @property string|null persistent
 * @property string|null scope
 */
abstract class PluginFieldBase extends PluginFieldable implements PluginFieldInterface {

  protected static $pluginType = 'field';

  /**
   * @param array $data
   * @return array|mixed|null
   * @throws PluginException
   */
  public function evaluate($data = []) {
    return $this->value($data);
  }

  /**
   * @param array $data
   * @return mixed|null
   * @throws PluginException
   */
  public function value($data = NULL) {
    $state_key = NULL;
    if ($this->persistent) {
      $state_key = $this->resolveParam($data, $this->persistent);
      $value = $this->state->get($state_key, $this->name());
      if (!is_null($value)) {
        return $value;
      }
    }
    $value = $this->getValue($data);
    if ($this->persistent) {
      $this->state->set($state_key, $this->name(), $value);
    }
    return $value;
  }

  /**
   * This shouldn't be called normally, only when a field
   * was not resolved and we try to convert it to JSON e.g.
   * @return mixed
   * @throws PluginException
   */
  public function __toString() {
    // TODO Add warning!
    return $this->value();
  }

  /**
   * @param $data
   * @param $params
   * @return array
   * @throws PluginException
   */
  protected function resolveParams($data, $params) {
    if (is_array($params)) {
      $items = [];
      foreach ($params as $key => $item) {
        $items[$key] = $this->resolveParam($data, $item);
      }
      return $items;
    }
    else {
      $param = $params;
      return $this->resolveParam($data, $param);
    }
  }

  /**
   * @param $data
   * @param $param
   * @return array|mixed
   * @throws PluginException
   */
  protected function resolveParam($data, $param) {
    if (is_array($param)) {
      return $this->resolveParams($data, $param);
    }
    if (is_string($param) && strpos($param, '@') === 0) {
      $expr = substr($param, 1);
      // Allow patterns like ../../variable
      $parts = preg_split('~(?<!\.)\.(?!\./)~', $expr);
      foreach ($parts as $part) {
        if (is_object($data) && isset($data->$part)) {
          $data = $data->$part;
        }
        else if (is_array($data) && array_key_exists($part, $data)) {
          $data = $data[$part];
        }
        else {
          $this->error("Cannot resolve param '$param': unknown index '$part'");
        }
        if ($data instanceof PluginFieldInterface) {
          $data = $data->value();
        }
      }
      return $data;
    }
    else {
      return $param;
    }
  }

  static function getSchemaChildren() {
    return [
      'field' => ['_type' => 'text', '_required' => TRUE],
      'persistent' => ['_type' => 'text', '_required' => FALSE],
    ];
  }

  static function defaultConfig($data = NULL) {
    return [
      'field' => self::pluginId(),
    ] + parent::defaultConfig($data);
  }

}
