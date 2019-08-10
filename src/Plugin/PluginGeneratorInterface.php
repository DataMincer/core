<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Exception\PluginException;

interface PluginGeneratorInterface extends PluginInterface {

  /**
   * @param array $generator_data
   * @param array $global_data
   * @return array
   */
  public function process($generator_data = [], $global_data = []);

}
