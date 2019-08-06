<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Util;

abstract class PluginWorkerBase extends PluginFieldable implements PluginWorkerInterface {

  protected static $pluginType = 'worker';

  /**
   * @inheritDoc
   */
  public function process() {
    $data = yield;
    yield $data;
  }
  /**
   * @inheritDoc
   */
  final public function finalizeWrapper() {
    $this->finalize();
  }

  protected function mergeResult($row, $data, $config) {
    $result = [];
    $var = $config['var'];
    switch ($config['merge']) {
      case 'source':
        if (array_key_exists($var, $data)) {
          $result = $data;
        }
        else {
          $data[$var] = $row;
          $result = $data;
        }
        break;
      case 'dest':
        $data[$var] = $row;
        $result = $data;
        break;
      case 'merge':
        if (array_key_exists($var, $data)) {
          $result = Util::arrayMergeDeep($data, [$var => $row], TRUE);
          //$result = [$var => Util::arrayMergeDeep($data[$var], $row, TRUE)] + $data;
        }
        else {
          $result = [$var => $row] + $data;
        }
    }
    return $result;
  }

  /**
   * @inheritDoc
   */
  public function finalize() { }

  static function getSchemaChildren() {
    return parent::getSchemaChildren() + [
      'var' => ['_type' => 'text', '_required' => FALSE ],
      'merge' => ['_type' => 'enum', '_values' => ['merge', 'source', 'dest'], '_required' => FALSE]
    ];
  }

  static function defaultConfig($data = NULL) {
    return parent::defaultConfig($data) + [
      'var' => 'row',
      'merge' => 'dest',
    ];
  }

}

