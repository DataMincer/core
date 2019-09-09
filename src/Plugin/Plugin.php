<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Exception\PluginException;
use DataMincerCore\FileManager;
use DataMincerCore\State;
use DataMincerCore\Util;

abstract class Plugin implements PluginInterface {

  protected static $pluginId = NULL;
  protected static $pluginType = NULL;
  protected static $pluginDeps = [];
  protected static $isDefault = FALSE;
  protected $name;
  protected $config = [];
  protected $data = [];
  /** @var PluginInterface */
  protected $parent;
  /** @var Plugin[] */
  protected $dependencies = [];
  /** @var State */
  protected $state;
  /** @var FileManager */
  protected $fileManager;
  /**
   * @var array|null
   */
  protected $values = [];
  /**
   * @var array
   */
  protected $_pluginPath;
  /** @var boolean */
  protected $initialized;

  public function __construct($name, $config, $state, $file_manager, $path = []) {
    $this->name = $name;
    $this->config = $config;
    $this->state = $state;
    $this->data = ['name' => $name];
    $this->_pluginPath = $path;
    $this->initialized = FALSE;
    $this->fileManager = $file_manager;
  }
  public static function pluginId() {
    return static::$pluginId;
  }

  public static function pluginType() {
    return static::$pluginType;
  }

  public static function pluginDeps() {
    return static::$pluginDeps;
  }

  public function name() {
    return $this->name;
  }

  public function path() {
    return implode('/', $this->_pluginPath);
  }

  public function getConfig() {
    return $this->config;
  }

  public function initialize() {
    $this->initializeComponents($this->config);
    $this->initialized = TRUE;
  }

  protected function initializeComponents($config) {
    foreach ($config as $key => $info) {
      if (is_object($info) && $info instanceof PluginInterface && !$this->initialized) {
        $info->initialize();
      }
      else if (is_array($info) && count($info)) {
        $this->initializeComponents($info);
      }
    }
  }

  public function evaluate($data = []) {
    return $this->evaluateChildren($data);
  }

  public function evaluateChildren($data = [], $include_paths = [], $exclude_paths = []) {
    $paths = Util::arrayPaths($this->config);
    // Filter paths
    if ($include_paths) {
      $paths = array_filter($paths, function ($item) use ($include_paths) {
        return $this->arrayInArrays($item, $include_paths);
      });
    }
    if ($exclude_paths) {
      $paths = array_filter($paths, function ($item) use ($exclude_paths) {
        return !$this->arrayInArrays($item, $exclude_paths);
      });
    }
    $result = [];
    foreach ($paths as $path) {
      $this->evaluateByPath($result, $path, $this->config, $data);
    }
    return $result;
  }

  /**
   * @param $needle
   * @param $arrays
   * @return bool
   * @throws PluginException
   */
  protected function arrayInArrays($needle, $arrays) {
    foreach($arrays as $array) {
       if ($this->arrayIncludes($array, $needle)) {
         return TRUE;
       }
    }
    return FALSE;
  }

  /**
   * @param $a
   * @param $b
   * @return bool
   * @throws PluginException
   */
  protected function arrayIncludes($a, $b) {
    $result = FALSE;
    for ($i = 0; $i < count($a); $i++) {
      if (!array_key_exists($i, $b)) {
        $this->error('Wrong path constraint: ' . implode('.', $a));
      }
      if ($a[$i] === $b[$i]) {
        $result = TRUE;
      }
      else {
        return FALSE;
      }
    }
    return $result;
  }

  /**
   * @param $result
   * @param $path
   * @param $values
   * @param $data
   */
  protected function evaluateByPath(&$result, $path, $values, $data) {
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
      if ($info instanceof PluginInterface) {
        $r[$leaf_key] = $info->evaluate($result + $data);
        return;
      }
    }
    else {
      $r[$leaf_key] = $info;
    }
    $r[$leaf_key] = $info;
  }

  public function setData($data, $recursive = TRUE) {
    $this->data = Util::arrayMergeDeep($this->data, $data);
  }

  public function getData() {
    return [
      static::$pluginType => [
        'name' => $this->name()
      ]
    ] + $this->data;
  }

  public function setDependencies($dependencies) {
    $this->dependencies = $dependencies;
  }

  public function getDependencies() {
    return $this->dependencies;
  }

  public static function isDefault() {
    return static::$isDefault;
  }

  /**
   * @param $message
   * @throws PluginException
   */
  protected function error($message) {
    throw new PluginException($message, $this);
  }

  public static function defaultConfig($data = NULL) {
    return [
        static::$pluginType => static::$pluginId,
    ];
  }

  public static function getSchemaChildren() {
    return [];
  }

  public static function getSchemaPartials() {
    return [];
  }

  public function getDefaultDependency($type) {
    if (array_key_exists($type, $this->dependencies)) {
      return current($this->dependencies[$type]);
    }
    return FALSE;
  }

}
