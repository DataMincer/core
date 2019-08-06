<?php

namespace DataMincerCore\Plugin;

use Generator;
use DataMincerCore\Exception\PluginException;
use DataMincerCore\Exception\PluginNoException;

/**
 * @property PluginWorkerInterface[] workers
 */
abstract class PluginGeneratorBase extends PluginFieldable implements PluginGeneratorInterface {

  protected static $pluginType = 'generator';

  /**
   * @inheritDoc
   * @throws PluginException
   */
  public function evaluate($data = []) {
    $process_result = $this->processWorkers($this->config['workers'], $data);
    $finalize_result = $this->finalizeWorkers($this->config['workers']);
    $result = array_merge($process_result, $finalize_result);
    return $result;
  }

  /**
   * @param $workers
   * @param array $parent_data
   * @return array
   * @throws PluginException
   */
  protected function processWorkers($workers, $parent_data = []) {
    $result = [];
    /** @var PluginWorkerBase $worker */
    $worker = array_shift($workers);
    /** @var Generator $task */
    $task = $worker->process();
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
          // We still need to buffer the current $data or we're gonna lose it.
          $worker->bufferItem($data);
          if ($workers) {
            $this->processWorkers($workers, $new_data);
          }
        }
      }
      else if (count($workers)) {
        $this->processWorkers($workers, $data);
      }
      else {
        $result[] = $data;
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
   * @param $workers
   * @return array
   * @throws PluginException
   */
  protected function finalizeWorkers($workers) {
    $result = [];
    /** @var PluginWorkerBase $worker */
    $worker = array_shift($workers);
    if ($worker instanceof PluginBufferingWorkerBase) {
      if (!$worker->isBufferEmpty()) {
        $data = $worker->processBuffer();
        if (count($workers)) {
          $res = $this->processWorkers($workers, $data);
          $result = array_merge($result, $res);
        }
        else {
          $result[] = $data;
        }
      }
    }
    else {
      $worker->finalizeWrapper();
    }
    if (count($workers)) {
      $this->finalizeWorkers($workers);
    }
    return $result;
  }

  static function getSchemaChildren() {
    return [
      'workers' => [ '_type' => 'prototype', '_required' => TRUE, '_prototype' => [
        '_type' => 'partial', '_required' => TRUE, '_partial' => 'worker',
      ]],
      'description' => ['_type' => 'text', '_required' => FALSE],
    ];
  }

}
