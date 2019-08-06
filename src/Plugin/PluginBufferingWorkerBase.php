<?php

namespace DataMincerCore\Plugin;

abstract class PluginBufferingWorkerBase extends PluginWorkerBase implements PluginBufferingWorkerInterface {

  /** @var array[] */
  protected $buffer = [];

  /**
  /**
   * Defines if the worker needs to buffer its items
   *
   * By default it doesn't buffer anything
   */
  public function isBuffering() {
    return FALSE;
  }

  /**
   * Checks if the items buffer is empty
   *
   * @return int
   */
  public function isBufferEmpty() {
    return count($this->buffer) == 0;
  }

  /**
   * @inheritDoc
   */
  public function bufferItem($data) {
    $this->buffer[] = $data;
  }

  /**
   * Processes buffered items
   *
   * By default just return all the items
   */
  public function processBuffer() {
    return $this->buffer;
  }

}
