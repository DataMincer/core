<?php

namespace DataMincerCore\Plugin;

interface PluginBufferingWorkerInterface extends PluginWorkerInterface {

  /**
   * Defines if the worker needs to buffer its items
   */
  public function isBuffering();

  /**
   * Checks if the items buffer is empty
   */
  public function isBufferEmpty();

  /**
   * Buffers an item
   * @param $data
   */
  public function bufferItem($data);

  /**
   * Processes buffered items
   */
  public function processBuffer();
}
