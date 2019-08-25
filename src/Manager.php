<?php

namespace DataMincerCore;

use DirectoryIterator;
use Exception;
use ReflectionClass;
use ReflectionException;
use DataMincerCore\Exception\UnitException;
use DataMincerCore\Exception\PluginException;
use DataMincerCore\Exception\DataMincerException;
use DataMincerCore\Plugin\PluginUnitInterface;
use DataMincerCore\Plugin\PluginInterface;
use DataMincerCore\Plugin\PluginServiceInterface;
use YamlElc\Bundle;

class Manager {

  const FILTERS_SEPARATOR = ',';

  protected $options;

  /** @var Bundle[] */
  protected $bundles;
  protected $unitsByBundle;
  protected $pluginsInfo;
  protected $plugins;

  function __construct($options, $logger) {
    $this->options = $this->parseOptions($options);
    DataMincer::setDebug($options['debug']);
    DataMincer::setLogger($logger);
    Timer::setEnabled($options['timer']);
    $this->pluginsInfo = $this->discoverPlugins();
    $this->initBundles();
    $this->initUnits();
    if ($options['timer']) {
      register_shutdown_function(function() {
        Timer::result();
      });
    }
  }

  public function initUnits() {
    foreach ($this->bundles as $bundle_name => $bundle) {
      $this->unitsByBundle[$bundle_name] = new Units($bundle, [$this, 'initUnit']);
    }
  }

  /**
   * @param $config
   * @param State $state
   * @param $data
   * @return mixed
   * @throws PluginException
   */
  public function initUnit($config, $state, $data) {
    $schema = $this->baseSchema();

    // Extend schema and config

    try {
      $res = $this->getPluginSchemaAndConfig($config, $schema['root'], $schema['partials']);
    }
    catch (Exception $e) {
      throw new UnitException('Schema extension error: ' . $e->getMessage(), $config);
    }
    $config = $res['config'];
    $schema['root'] = $res['schema'];
    $schema['partials'] = $res['partials'];

    Timer::begin('Schema validation');
    if (!$this->options['novalidate'] && !Schema::validate($config, $schema, $error)) {
      throw new UnitException("Config validation error: " . $error, $config, $schema);
    }
    Timer::end('Schema validation');

    /** @var PluginUnitInterface $unit */
    $unit = $this->createUnit($config, $state);

    // Add extra data
    $unit->setData($data);

    // Resolve all dependencies
    $this->resolveDependencies($unit);

    // Initialize unit and its subcomponents
    $unit->initialize();

    return $unit;
  }

  protected function getPluginSchemaAndConfig($config, $schema, $partials, $plugin = NULL) {
    $result_partials = $partials;
    if ($schema['_type'] == 'prototype') {
      $result_schema = ['_type' => 'array', '_required' => TRUE, '_children' => []];
      $result_config = [];
      foreach ($config as $key => $info) {
        $res = $this->getPluginSchemaAndConfig($info, $schema['_prototype'], $result_partials);
        $result_schema['_children'][$key] = $res['schema'];
        $result_config[$key] = $res['config'];
        $result_partials += $res['partials'];
      }
    }
    else if ($schema['_type'] == 'array') {
      $result_schema = $schema;
      if (!is_null($plugin)) {
        list($children_extension, $partials_extensions) = $this->getPluginSchemaChildren($plugin);
        $result_partials += $partials_extensions;
        $result_schema['_children'] += $children_extension;
        $config = Util::arrayMergeDeep($config, $this->getDefaultConfig($plugin), TRUE);
      }
      $result_config = [];
      foreach ($config as $key => $info) {
        if (array_key_exists($key, $result_schema['_children'])) {
          $res = $this->getPluginSchemaAndConfig($info, $result_schema['_children'][$key], $result_partials);
          $result_schema['_children'][$key] = $res['schema'];
          $result_config[$key] = $res['config'];
          $result_partials += $res['partials'];
        }
        else {
          $result_config[$key] = $info;
        }
      }
      // Copy the rest of the original schema
      foreach ($schema['_children'] as $key => $info) {
        if (!array_key_exists($key, $result_schema['_children'])) {
          $result_schema['_children'][$key] = $info;
        }
      }
    }
    else if ($schema['_type'] == 'partial') {
      if (!isset($partials[$schema['_partial']])) {
        throw new UnitException('Partial "' . $schema['_partial'] . '" not found', $config, $schema);
      }

      $plugin_type = $schema['_partial'];

      $_schema = $partials[$plugin_type];
      if (!array_key_exists($plugin_type, $this->pluginsInfo)) {
        throw new UnitException('Plugin type not found: ' . $plugin_type);
      }

      if (is_null($config)) {
        // The case of empty value
        $config = "";
      }
      if (is_scalar($config)) {
        // Default plugin
        if (!($plugin_class = $this->getDefaultPlugin($plugin_type))) {
          throw new UnitException('Default plugin not found, type: ' . $plugin_type, $config, $schema);
        }
        $_schema = $partials[$plugin_type];
        $config = $this->getDefaultConfig($plugin_class, $config);
      }
      else if (is_array($config) && !array_key_exists($plugin_type, $config)) {
        $plugin_class = $this->getDefaultPlugin($plugin_type);
        $config += $this->getDefaultConfig($plugin_class, $config);
      }

      if (Util::isAssocArray($config)) {
        if (!array_key_exists($plugin_type, $config)) {
          throw new UnitException("Key '$plugin_type' not found in the config.", $config, $schema);
        }
        list($plugin_name, $plugin_class, $plugin_services) = $this->getPluginInfo($config[$plugin_type], $plugin_type);
        $config[$plugin_type] = $plugin_name;
        $config['_pluginArgs'] = $plugin_services;
        $config['_pluginType'] = $plugin_type;
        $_schema = $_schema['_choices']['one'];
        $res = $this->getPluginSchemaAndConfig($config, $_schema, $result_partials, $plugin_class);
        $result_schema = $res['schema'];
        $result_config = $res['config'];
        $result_partials += $res['partials'];
      }
      else {
        throw new UnitException("$plugin_type config must be an assoc array", $config, $schema);
      }
    }
    else {
      $result_schema = $schema;
      $result_config = $config;
    }
    return ['schema' => $result_schema, 'config' => $result_config, 'partials' => $result_partials];
  }

  protected function getPluginInfo($plugin_expr, $plugins_key) {
    $plugin_info = NULL;
    list($plugin_name, $arg_names) = $this->parsePluginName($plugin_expr);
    if (!array_key_exists($plugin_name, $this->pluginsInfo[$plugins_key])) {
      throw new UnitException('Plugin not found: ' . $plugin_name);
    }
    $plugin_info = $this->pluginsInfo[$plugins_key][$plugin_name];
    return [$plugin_name, $plugin_info, $arg_names];
  }

  protected function parsePluginName($plugin_expr) {
    $name = NULL;
    $args = [];
    if (preg_match('~^([^(]+)(?:\((.+)\))?$~', $plugin_expr, $matches)) {
      $name = $matches[1];
      if (array_key_exists(2, $matches)) {
        $args_expr = $matches[2];
        $args = preg_split('~,\s*~', $args_expr);
      }
    }
    return [$name, $args];
  }

  protected function createUnit($config, $state, $key = NULL, $path = []) {
    if (array_key_exists('_pluginType', $config)) {
      $plugin_type = $config['_pluginType'];
      unset($config['_pluginType']);
      $plugin_config = $this->createUnit($config, $state, $key, $path);
      /** @var ReflectionClass $class */
      $class = $this->pluginsInfo[$plugin_type][$config[$plugin_type]];
      /** @var PluginInterface $plugin */
      $plugin = $class->newInstance($key, $plugin_config, $state, $path);
      $this->addPlugin($plugin);
      return $plugin;
    }
    else {
      if (!is_null($key)) {
        $path[] = $key;
      }
      $result = [];
      foreach ($config as $key => $info) {
        if (is_array($info)) {
          $result[$key] = $this->createUnit($info, $state, $key, $path);
        }
        else {
          $result[$key] = $info;
        }
      }
      return $result;
    }

  }

  /**
   * @param PluginInterface $plugin
   */
  protected function addPlugin($plugin) {
    $type = $plugin::pluginType();
    $name = $plugin->name();
    $this->plugins[$type][$name] = $plugin;
  }

  /**
   * @param $type
   * @param $name
   * @return PluginInterface|bool
   */
  protected function getPlugin($type, $name) {
    return $this->plugins[$type][$name] ?? FALSE;
  }

  /**
   * @param PluginInterface $plugin
   * @throws PluginException
   */
  public function resolveDependencies($plugin) {
    $args = $plugin->getConfig()['_pluginArgs'];
    $defs = $plugin::pluginDeps();
    $deps = [];
    if ($defs > 0) {
      if (($required_count = count($defs)) > ($count = count($args))) {
        throw new PluginException("Plugin depends on $required_count arguments, $count given.", $plugin);
      }
      /** @var PluginServiceInterface $service_config */
      foreach ($defs as $index => $def) {
        if (($dependant = $this->getPlugin($def['type'], $args[$index])) === FALSE) {
          throw new PluginException("Plugin '$args[$index]' of type '$def[type]' not found.", $plugin);
        }
        $dep_name_pair = $this->getServiceNamePair($def['name']);
        $plugin_name_pair = $this->getServiceNamePair($dependant::pluginId());
        if ($dep_name_pair[0] == $plugin_name_pair[0]) {
          // Types match
          if (($dep_name_pair[1] == $plugin_name_pair[1]) || $dep_name_pair[1] == '*') {
            $deps[$def['type']][$args[$index]] = $dependant;
            unset($defs[$index]);
            unset($args[$index]);
            continue;
          }
        }
      }
      if (count($defs)) {
        $unresolved = array_map(function ($item) {
          return $item['type'] . '(' . $item['name'] . ')';
        }, $defs);
        throw new PluginException("Couldn't resolve required dependency(s): " . implode(', ', $unresolved), $plugin);
      }
    }
    if (count($args)) {
      throw new PluginException("Plugin declaration doesn't match its definition, extra argument(s): " . implode(', ', $args), $plugin);
    }
    if (count($deps)) {
      $plugin->setDependencies($deps);
    }
    $this->resolveDependenciesRecursively($plugin->getConfig());
  }

  /**
   * @param $config
   * @throws PluginException
   */
  protected function resolveDependenciesRecursively($config) {
    foreach ($config as $key => $info) {
      if (is_object($info)) {
        /** @var PluginInterface $plugin */
        $plugin = $info;
        $this->resolveDependencies($plugin);
      }
      else if (is_array($info)) {
        $this->resolveDependenciesRecursively($info);
      }
    }
  }

  protected function getServiceNamePair($name_expr) {
    if (($dot_pos = strpos($name_expr, '.')) === FALSE) {
      return ['default', $name_expr];
    }
    else {
      return [substr($name_expr, 0, $dot_pos), substr($name_expr, $dot_pos + 1)];
    }
  }

  protected function getDefaultPlugin($plugins_key) {
    /** @var ReflectionClass $class */
    foreach ($this->pluginsInfo[$plugins_key] as $plugin_id => $class) {
      try {
        if ($class->getMethod('isDefault')->invoke(NULL)) {
          return $class;
        }
      }
      catch (ReflectionException $e) {
        throw new UnitException($e->getMessage());
      }
    }
    return NULL;
  }

  protected function getPluginSchemaChildren(ReflectionClass $class) {
    $result = [[], []];
    if ($class->implementsInterface('DataMincerCore\\Plugin\\PluginInterface')) {
      try {
        $extra_keys = [
          '_pluginType' => ['_type' => 'text', '_required' => TRUE],
          '_pluginArgs' => ['_type' => 'prototype', '_required' => TRUE, '_min_items' => 0, '_prototype' => [
            '_type' => 'text', '_required' => TRUE,
          ]],
        ];
        $result = [
          $extra_keys + $class->getMethod('getSchemaChildren')->invoke(NULL),
          $class->getMethod('getSchemaPartials')->invoke(NULL),
        ];
        // Check for nested partials
      }
      catch(ReflectionException $e) {
        throw new UnitException("Schema extension error: " . $e->getMessage());
      }
    }
    return $result;
  }

  protected function getDefaultConfig(ReflectionClass $class, $arg = NULL) {
    $result = [];
    if ($class->hasMethod('defaultConfig')) {
      try {
        $result = $class->getMethod('defaultConfig')->invoke(NULL, $arg);
      }
      catch(ReflectionException $e) {
        throw new UnitException("Default config error: " . $e->getMessage());
      }
    }
    return $result;
  }

  protected function discoverPlugins() {
    $plugins_info = [];
    $class_names = get_declared_classes();
    foreach($class_names as $class_name) {
      try {
        $class = new ReflectionClass($class_name);
        foreach (['unit', 'service', 'generator', 'worker', 'field'] as $item) {
          if ($class->implementsInterface('DataMincerCore\\Plugin\\Plugin' . ucfirst($item) . 'Interface') && !$class->isAbstract()) {
            $id = $class->getMethod('pluginId')->invoke(NULL);
            $type = $class->getMethod('pluginType')->invoke(NULL);
            $plugins_info[$type][$id] = $class;
          }
        }
      } catch (ReflectionException $e) {
        throw new UnitException('Discover plugins error: ' . $e->getMessage());
      }
    }
    return $plugins_info;
  }

  protected function parseOptions($options) {
    $result = [];
    foreach($this->optionsDefaults() as $k => $v) {
      $value = array_key_exists($k, $options) ? $options[$k] : $v;
      if (method_exists($this, $method_name = 'processArg' . ucfirst($k))) {
        $value = $this->$method_name($value);
      }
      $result[$k] = $value;
    }
    return $result;
  }

  protected function processArgFilters($filters) {
    $filter_list = [];
    foreach ($filters as $filter) {
      if (preg_match('~^([^:]*)?(?::(.*))?$~', $filter, $matches)) {
        $bundle_list = trim($matches[1])? explode(static::FILTERS_SEPARATOR, $matches[1]) : ['*'];
        $conditions = isset($matches[2]) && trim($matches[2])? explode(static::FILTERS_SEPARATOR, $matches[2]) : [];
        foreach($bundle_list as $bundle_name) {
          if (!isset($filter_list[$bundle_name])) {
            $filter_list[$bundle_name] = $conditions;
          }
          else {
            $filter_list[$bundle_name] = array_unique(array_merge($filter_list[$bundle_name], $conditions), SORT_STRING);
          }
        }
      }
      else {
        throw new DataMincerException("Incorrect filter format.");
      }
    }
    // Merge filters
    if (isset($filter_list['*'])) {
      foreach (array_keys($filter_list) as $bundle_name) {
        $filter_list[$bundle_name] = array_unique(array_merge($filter_list[$bundle_name], $filter_list['*']), SORT_STRING);
      }
    }
    return $filter_list;
  }

  protected function initBundles() {
    $bundles_data = $this->discoverBundles();
    $filters = $this->options['filters'];
    $this->bundles = [];
    foreach ($bundles_data as $name => $bundle_data_info) {
      if (count($filters) && !array_key_exists($name, $filters)) {
        // Filter out bundles
        continue;
      }
      $this->bundles[$name] = new Bundle($name, $bundle_data_info['data'], $filters[$name] ?? ($filters['*'] ?? []), ['path' => $bundle_data_info['path']]);
    }
  }

  protected function discoverBundles() {
    $bundles_data = [];
    $opt = $this->options;
    // Find units in the unitsDir
    foreach (new DirectoryIterator($opt['basePath']) as $fileInfo) {
      if ($fileInfo->isDot() || !$fileInfo->isDir()) {
        continue;
      }
      $dir = $fileInfo->getFilename();
      if (file_exists($file = $opt['basePath'] . '/' . $dir . '/' . $dir . '.yml')) {
        $bundle_name = $dir;
        // TODO Add overrides
        $bundles_data[$bundle_name] = [
          'path' => $opt['basePath'] . '/' . $dir,
          'data' => Util::getYaml($file),
        ];
      }
    }
    return $bundles_data;
  }

  protected function optionsDefaults() {
    return [
      'basePath' => '.',
      'dataPath' => 'data',
      'filters' => [],
      'novalidate' => FALSE,
    ];
  }

  /**
   * @return Bundle[]
   */
  public function getBundles() {
    return $this->bundles;
  }

  /**
   * @param $bundle_name
   * @return Units
   */
  public function getUnits($bundle_name) {
    return $this->unitsByBundle[$bundle_name] ?? NULL;
  }

  private function baseSchema() {
    return [
      'root' => [ '_type' => 'partial', '_required' => TRUE, '_partial' => 'unit' ],
      'partials' => [
        'unit' => [
          '_type' => 'choice', '_required' => TRUE, '_choices' => [
            'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
              'unit' => [ '_type' => 'text',  '_required' => TRUE ],
            ]],
          ],
        ],
      ],
    ];
  }

}
