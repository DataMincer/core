<?php

namespace DataMincerCore\Plugin;

use Generator;
use DataMincerCore\Exception\PluginException;
use DataMincerCore\Exception\PluginNoException;

/**
 * @property PluginWorkerInterface[] workers
 */
abstract class PluginGeneratorBase extends Plugin implements PluginGeneratorInterface {

  protected static $pluginType = 'generator';

  /**
   * @inheritDoc
   * @throws PluginException
   */
  public function process($generator_data = [], $global_data = []) {
    $workers_info = [];
    if (empty($this->workers)) {
      return;
    }
    foreach($this->workers as $key => $worker) {
      $workers_info[] = [
        $key,
        $worker,
        $generator_data['workers'][$key],
      ];
    }
    $process_result = $this->processWorkers($workers_info, $global_data);
    $this->finalizeWorkers($workers_info, $process_result);
  }

  /**
   * @param $workers_info
   * @param array $parent_data
   * @return array
   * @throws PluginException
   */
  protected function processWorkers($workers_info, $parent_data = []) {
    $result = [];
    /** @var PluginWorkerInterface $worker */
    list($key, $worker, $config) = array_shift($workers_info);
    /** @var Generator $task */
    $task = $worker->process($config);
    $force_quit = FALSE;
    try {
      $task->send($parent_data);
    }
      /** @noinspection PhpRedundantCatchClauseInspection */
    catch (PluginNoException $e) {
      // This is not actually an exception but a way to break the loop
      $force_quit = TRUE;
    }
    while (!$force_quit && $task->valid()) {
      $data = $task->current();
      if ($worker instanceof PluginBufferingWorkerBase) {
        if ($worker->isBuffering()) {
          $worker->bufferItem($data);
        } else if (!$worker->isBufferEmpty()) {
          $new_data = $worker->processBuffer();
          $result[$key][] = $new_data;
          // We still need to buffer the current $data or we're gonna lose it.
          $worker->bufferItem($data);
          if ($workers_info) {
            $res = $this->processWorkers($workers_info, $new_data);
            $result = $this->mergeWorkerResults($result, $res);
          }
        }
      }
      else if (count($workers_info)) {
        $result[$key][] = $data;
        $res = $this->processWorkers($workers_info, $data);
        $result = $this->mergeWorkerResults($result, $res);
      }
      else {
        $result[$key][] = $data;
      }
      try {
        $task->next();
      }
        /** @noinspection PhpRedundantCatchClauseInspection */
      catch (PluginNoException $e) {
        // This is not actually an exception but a way to break the loop
        $force_quit = TRUE;
      }
    }
    return $result;
  }

  /**
   * @param $workers_info
   * @param $workers_results
   * @throws PluginException
   */
  protected function finalizeWorkers($workers_info, $workers_results) {
    /** @var PluginWorkerBase $worker */
    list($key, $worker, $config) = array_shift($workers_info);
    $result = &$workers_results;
    if ($worker instanceof PluginBufferingWorkerBase) {
      if (!$worker->isBufferEmpty()) {
        $data = $worker->processBuffer();
        $result[$key][] = $data;
        if (count($workers_info)) {
          $res = $this->processWorkers($workers_info, $data);
          $result = $this->mergeWorkerResults($result, $res);
        }
      }
    }
    else {
      $worker->finalize($config, $result[$key]);
    }
    if (count($workers_info)) {
      $this->finalizeWorkers($workers_info, $workers_results);
    }
  }

  protected function mergeWorkerResults($data, $new_data) {
    $result = [];
    foreach ($data as $key => $item) {
      if (array_key_exists($key, $new_data)) {
        $result[$key] = array_merge($item, $new_data[$key]);
      }
      else {
        $result[$key] = $item;
      }
    }
    return $result + $new_data;
  }

  static function getSchemaChildren() {
    return [
      'workers' => [ '_type' => 'prototype', '_required' => FALSE, '_prototype' => [
        '_type' => 'partial', '_required' => TRUE, '_partial' => 'worker',
      ]],
    ];
  }

}
