<?php

namespace DataMincerCore\Exception;

use Exception;
use DataMincerCore\Plugin\PluginInterface;

class PluginException extends Exception {

  /**
   * PluginException constructor.
   * @param $message
   * @param PluginInterface $plugin
   */
  public function __construct($message, $plugin) {
    $message = ucfirst($plugin::pluginType()) . ' plugin \'' . $plugin::pluginId() . '\' error: ' . $message . "\nLocation: " . $plugin->path();
    parent::__construct($message);
  }


}
