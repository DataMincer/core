<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\DataMincer;
use DataMincerCore\Exception\UnitException;

/**
 * @property PluginGeneratorInterface[] generators
 */
abstract class PluginUnitBase extends PluginFieldable implements PluginUnitInterface {

  protected static $pluginType = 'unit';

  protected $id;
  protected $tasks;

  public function initialize() {
    $this->id = $this->makeUnitId();
    $this->tasks = $this->discoverTasks();
    parent::initialize();
  }

  public function getData() {
    return $this->data;
  }

  protected function discoverTasks() {
    $task_methods = array_filter(get_class_methods($this), function($item) {
      return strpos($item, 'task') === 0;
    });
    $tasks = [];
    foreach ($task_methods as $task_method) {
      $tasks[strtolower(substr($task_method, 4))] = ['method' => $task_method, 'help' => $this->help($task_method)];
    }
    return $tasks;
  }

  /**
   * @param array $generators
   */
  public function taskGenerate($generators = []) {
    // Evaluate all except for generators for now
    $data = $this->evaluateChildren($this->data, [], [['generators']]) + $this->data;
    // Generators section is not required
    if (!empty($this->generators)) {
      if ($diff = array_diff($generators, array_keys($this->generators))) {
        throw new UnitException('Unknown generator(s): ' . implode(', ', $diff));
      }
      foreach($this->generators as $generator_name => $generator) {
        if (empty($generators) || in_array($generator_name, $generators)) {
          DataMincer::logger()->msg("Running generator: $generator_name");
          $generator_data = $generator->evaluate($data);
          $generator->process($generator_data, $data);
        }
      }
    }
  }

  protected function makeUnitId() {
    return sha1(serialize($this->config));
  }

  public function id($short = FALSE) {
    return $short ? substr($this->id, 1,8) : $this->id;
  }

  public function getSummary() {
    return $this->renderOrigin();
  }

  protected function renderOrigin() {
    $result = [];
    foreach ($this->data['origin'] as $dimension_name => $registers_info) {
      foreach ($registers_info as $register => $info) {
        $register_num = substr($register, 1);
        $register_prefix = '';
        for ($i = 0; $i < $register_num; $i++) {
          $register_prefix .= ':';
        }
        $result[] = $register_prefix . $dimension_name . '=' . $info['domain'] . '.' . $info['value'];
      }
    }
    return implode(' ', $result);
  }

  public function getTasks() {
    return $this->tasks;
  }

  public function getTask($name) {
    if (!array_key_exists($name, $this->tasks)) {
      return FALSE;
    }
    return $this->tasks[$name];
  }

  public function getConfig() {
    return $this->config;
  }

  protected function help($key) {
    switch ($key) {
      case 'taskGenerate':
        return "Generate cards data.";
        break;
      default:
        return NULL;
    }
  }

  static function getSchemaChildren() {
    return [
      'services'    => [ '_type' => 'prototype', '_required' => FALSE, '_prototype' => [
        '_type' => 'partial', '_required' => TRUE, '_partial' => 'service',
      ]],
      'generators' => [ '_type' => 'prototype', '_required' => FALSE, '_min_items' => 1, '_prototype' => [
        '_type' => 'partial', '_required' => TRUE, '_partial' => 'generator',
      ]],
    ];
  }

  static function getSchemaPartials() {
    return [
      'service' => [
        '_type' => 'choice', '_required' => TRUE, '_choices' => [
          'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
            'service' => [ '_type' => 'text',  '_required' => TRUE ],
          ]],
        ],
      ],
      'generator' => [
        '_type' => 'choice', '_required' => TRUE, '_choices' => [
          'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
            'generator' => [ '_type' => 'text',  '_required' => TRUE ],
          ]],
        ],
      ],
      'worker' => [
        '_type' => 'choice', '_required' => TRUE, '_choices' => [
          'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
            'worker' => [ '_type' => 'text',  '_required' => TRUE ],
          ]],
        ],
      ],
      'row' => [
        '_type' => 'choice', '_required' => TRUE, '_choices' => [
          'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
            'row' => [ '_type' => 'text',  '_required' => TRUE ],
          ]],
        ],
      ],
      'field' => [
        '_type' => 'choice', '_required' => TRUE, '_choices' => [
          'default' => [ '_type' => 'text', '_required' => TRUE ],
          'one' => [ '_type' => 'array', '_required' => TRUE, '_children' => [
            'field' => [ '_type' => 'text',  '_required' => TRUE ],
            'scope' => [ '_type' => 'text',  '_required' => FALSE ],
          ]],
        ],
      ],
    ];
  }

}
