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
    $this->options = $this->getOptions($options);
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

  protected function getOptions($overrides) {
    // Read defaults
    $options = $this->getDefaultOptions();
    // Read options from composer.json
    $composer_options = [];
    $composer_options_parsed = [];
    if (file_exists('composer.json')) {
      $composer = Util::getJson('composer.json');
      if (isset($composer['extra']['data-mincer-options']) && is_array($composer['extra']['data-mincer-options'])) {
        $composer_options = $composer['extra']['data-mincer-options'];
      }
      if (isset($composer['extra']['data-mincer-options-parsed']) && is_array($composer['extra']['data-mincer-options-parsed'])) {
        $composer_options_parsed = $composer['extra']['data-mincer-options-parsed'];
      }
    }
    $options_list_parsed = [];
    foreach ([$options, $composer_options, $overrides] as $index => $local_options) {
      $options_list_parsed[$index] = $this->parseOptions($local_options);
    }
    foreach ($composer_options_parsed as $key => $value) {
      if (isset($options_list_parsed[1][$key])) {
        $options_list_parsed[1][$key] = $composer_options_parsed[$key];
      }
    }

    foreach ($options_list_parsed as $index => $options_parsed) {
      foreach ($options as $key => $option) {
        if (!empty($options_parsed[$key])) {
          if (is_array($option)) {
            $options[$key] = Util::arrayMergeDeep($options[$key], $options_parsed[$key]);
          }
          else {
            $options[$key] = $options_parsed[$key];
          }
        }
      }
    }
    return $options;
  }

  public function initUnits() {
    foreach ($this->bundles as $bundle_name => $bundle) {
      $this->unitsByBundle[$bundle_name] = new Units($bundle, [$this, 'initUnit']);
    }
  }

  /**
   * @param $config
   * @param $data
   * @return mixed
   * @throws PluginException
   */
  public function initUnit($config, $data) {
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

    $file_manager = new FileManager([
      'bundlesPath' =>  $this->options['bundlesPath'],
      'buildPath' =>  $this->options['buildPath'],
      'tempPath' => $this->options['tempPath'],
      'cachePath' => $this->options['cachePath'],
      'bundleName' => $data['bundle']['name'],
      'bundlePath' => $data['bundle']['path'],
    ]);

    $state = new State($file_manager->resolveUri($this->options['statePath'] . '/' . $data['bundle']['name']));
    $cache = new Cache($file_manager->resolveUri($this->options['cachePath']) . '/' . $data['bundle']['name']);

    /** @var PluginUnitInterface $unit */
    $unit = $this->createUnit($config, $state, $cache, $file_manager);

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
      $result_config = [];
      if (!is_null($plugin)) {
        list($children_extension, $partials_extensions, $mixin) = $this->getPluginSchemaChildren($plugin);
        $result_partials += $partials_extensions;
        $result_schema['_children'] += $children_extension;
        $config = Util::arrayMergeDeep($config, $this->getDefaultConfig($plugin), TRUE);
        if ($mixin) {
          $config['_mixin'] = $mixin;
        }
      }

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
        list($plugin_name, $plugin_class, $plugin_args) = $this->getPluginInfo($config[$plugin_type], $plugin_type);
        $config[$plugin_type] = $plugin_name;
        $config['_pluginArgs'] = $plugin_args;
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

  protected function createUnit($config, $state, $cache, $file_manager, $key = NULL, $path = []) {
    if (array_key_exists('_pluginType', $config)) {
      $plugin_type = $config['_pluginType'];
      unset($config['_pluginType']);
      $plugin_config = $this->createUnit($config, $state, $cache, $file_manager, $key, $path);
      /** @var ReflectionClass $class */
      $class = $this->pluginsInfo[$plugin_type][$config[$plugin_type]];
      /** @var PluginInterface $plugin */
      $plugin = $class->newInstance($key, $plugin_config, $state, $cache, $file_manager, $path);
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
          $result[$key] = $this->createUnit($info, $state, $cache, $file_manager, $key, $path);
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
    $result = [[], [], []];
    if ($class->implementsInterface('DataMincerCore\\Plugin\\PluginInterface')) {
      try {
        $extra_keys = [
          '_pluginType' => ['_type' => 'text', '_required' => TRUE],
          '_pluginArgs' => ['_type' => 'prototype', '_required' => TRUE, '_min_items' => 0, '_prototype' => [
            '_type' => 'text', '_required' => TRUE,
          ]]
        ];
        $result[0] = $extra_keys + $class->getMethod('getSchemaChildren')->invoke(NULL);
        $result[1] = $class->getMethod('getSchemaPartials')->invoke(NULL);
        // Read mixins
        $mixin_schema = $class->getMethod('getMixinSchema')->invoke(NULL);
        if ($mixin_schema) {
          $mixin = $class->getMethod('getMixin')->invoke(NULL);
          if (is_string($mixin)) {
            // We have YAML
            try {
              $mixin = Util::fromYaml($mixin);
            }
            catch (DataMincerException $e) {
              throw new UnitException("Cannot read mixin definition: " . $e->getMessage());
            }
          }
          $result[0]['_mixin'] = $mixin_schema;
          $result[2] = $mixin;
        }
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
    foreach($this->getDefaultOptions() as $k => $v) {
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
        $pairs = isset($matches[2]) && trim($matches[2])? explode(static::FILTERS_SEPARATOR, $matches[2]) : [];
        foreach($bundle_list as $bundle_name) {
          if (!isset($filter_list[$bundle_name])) {
            $filter_list[$bundle_name] = $pairs;
          }
          else {
            $filter_list[$bundle_name] = array_unique(array_merge($filter_list[$bundle_name], $pairs), SORT_STRING);
          }
        }
      }
      else {
        throw new DataMincerException("Incorrect filter format: $filter");
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

  protected function processArgOverrides($overrides) {
    $override_list = [];
    foreach ($overrides as $override) {
      if (preg_match('~^([^:]*)?(?::(.*))?$~', $override, $matches)) {
        $bundle_list = trim($matches[1])? explode(static::FILTERS_SEPARATOR, $matches[1]) : ['*'];
        $value = Util::fromYaml($matches[2]);
        foreach($bundle_list as $bundle_name) {
          if (!isset($override_list[$bundle_name])) {
            $override_list[$bundle_name] = $value;
          }
          else {
            $override_list[$bundle_name] = Util::arrayMergeDeep($override_list[$bundle_name], $value);
          }
        }
      }
      else {
        throw new DataMincerException("Incorrect override format: $override");
      }
    }
    // Merge overrides
    if (isset($override_list['*'])) {
      foreach (array_keys($override_list) as $bundle_name) {
        $override_list[$bundle_name] = array_unique(array_merge($override_list[$bundle_name], $override_list['*']), SORT_STRING);
      }
    }
    return $override_list;
  }

  protected function initBundles() {
    $bundles_data = $this->discoverBundles();
    $filters = $this->options['filters'];
    $overrides = $this->options['overrides'];
    $this->bundles = [];
    foreach ($bundles_data as $name => $bundle_data_info) {
      if (count($filters) && !array_key_exists($name, $filters) && !array_key_exists('*', $filters)) {
        // Filter out bundles
        continue;
      }
      $extra_data = [
        'path' => $bundle_data_info['path']
      ];
      $bundle_filters = $filters[$name] ?? ($filters['*'] ?? []);
      $bundle_overrides = $overrides[$name] ?? ($overrides['*'] ?? []);
      $this->bundles[$name] = new Bundle($name, $bundle_data_info['data'], $bundle_filters, $bundle_overrides, $extra_data);
    }
  }

  protected function discoverBundles() {
    $bundles_data = [];
    $opt = $this->options;
    $bundle_name = '';
    $bundle_real_path = '';

    if (!empty($opt['bundlesPath'])) {
      // Multi-bundle mode
      foreach (new DirectoryIterator($opt['bundlesPath']) as $fileInfo) {
        if ($fileInfo->isDot() || !$fileInfo->isDir()) {
          continue;
        }
        $dir = $fileInfo->getFilename();
        if (file_exists($file = $opt['bundlesPath'] . '/' . $dir . '/bundle.yml')) {
          $bundle_real_path = realpath($file);
          $bundle_name = $dir;
        }
      }
    }
    else {
      // Single-bundle mode
      $bundle_path = $opt['bundlePath'] . '/bundle.yml';
      if (!file_exists($bundle_path)) {
        throw new UnitException('Bundle not found: ' . $bundle_path);
      }
      $bundle_real_path = realpath($bundle_path);
      $bundle_name = basename(dirname($bundle_real_path));
      if (empty($bundle_name)) {
        throw new UnitException('Cannot determine the bundle name.');
      }
    }
    $bundles_data[$bundle_name] = [
      'path' => dirname($bundle_real_path),
      'data' => Util::getYaml($bundle_real_path),
    ];
    return $bundles_data;
  }

  protected function getDefaultOptions() {
    return [
      'bundlesPath' => '',
      'bundlePath' => '',
      'buildPath' => 'build',
      'tempPath' => sys_get_temp_dir() . '/datamincer/tmp',
      'cachePath' => sys_get_temp_dir() . '/datamincer/cache',
      'statePath' => sys_get_temp_dir() . '/datamincer/state',
      'filters' => [],
      'overrides' => [],
      'novalidate' => FALSE,
      'verbose' => 0,
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
