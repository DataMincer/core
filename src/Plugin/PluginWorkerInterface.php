<?php

namespace DataMincerCore\Plugin;

use Generator;
use DataMincerCore\Exception\PluginException;

interface PluginWorkerInterface extends PluginInterface {

  /**
   * @param $config
   * @return Generator
   * @throws PluginException
   */
  public function process($config);

  /**
   * @param $config
   * @param $results
   * @return mixed|void
   * @throws PluginException
   */
  public function finalize($config, $results);

}
