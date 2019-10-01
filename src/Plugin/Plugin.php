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
  protected $_name;
  protected $_config = [];
  protected $_data = [];
  /** @var PluginInterface */
  protected $_parent;
  /** @var Plugin[] */
  protected $_dependencies = [];
  /** @var State */
  protected $_state;
  /** @var FileManager */
  protected $_fileManager;
  /**
   * @var array
   */
  protected $_pluginPath;
  /** @var boolean */
  protected $initialized;

  /**
   * Plugin constructor.
   * @param $name
   * @param $config
   * @param $state
   * @param $file_manager
   * @param array $path
   * @throws PluginException
   */
  public function __construct($name, $config, $state, $file_manager, $path = []) {
    $this->_name = $name;
    // Prohibit using object property names for $config
    $prop_names = array_keys(get_object_vars($this));
    if ($prohibit_list = array_intersect_key(array_flip(array_keys($config)), array_flip($prop_names))) {
      $this->error("The following property names are reserved: " . implode(', ', $prohibit_list));
    }
    $this->_config = $config;
    $this->_state = $state;
    $this->_data = ['name' => $name];
    $this->_pluginPath = $path;
    $this->initialized = FALSE;
    $this->_fileManager = $file_manager;
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
    return $this->_name;
  }

  public function path() {
    return implode('/', $this->_pluginPath);
  }

  public function getConfig() {
    return $this->_config;
  }

  public function initialize() {
    $this->initializeComponents($this->_config);
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
    $paths = Util::arrayPaths($this->_config);
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
      $this->evaluateByPath($result, $path, $this->_config, $data);
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
    $this->_data = Util::arrayMergeDeep($this->_data, $data);
  }

  public function getData() {
    xdebug_break();
    return [
      static::$pluginType => [
        'name' => $this->name()
      ]
    ] + $this->_data;
  }

  public function setDependencies($_dependencies) {
    $this->_dependencies = $_dependencies;
  }

  public function getDependencies() {
    return $this->_dependencies;
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
    if (array_key_exists($type, $this->_dependencies)) {
      return current($this->_dependencies[$type]);
    }
    return FALSE;
  }

}
