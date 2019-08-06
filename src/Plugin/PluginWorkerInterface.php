<?php

namespace DataMincerCore\Plugin;

use Generator;
use DataMincerCore\Exception\PluginException;

interface PluginWorkerInterface extends PluginInterface {

  /**
   * @return Generator
   * @throws PluginException
   */
  public function process();

  public function finalizeWrapper();

  public function finalize();


}
