<?php

namespace DataMincerCore\Plugin;

use DataMincerCore\Exception\PluginException;

interface PluginFieldInterface extends PluginInterface {

  /**
   * @param array|null $data
   * @throws PluginException
   */
  public function value($data = NULL);


  /**
   * @param $data
   * @throws PluginException
   */
  public function getValue($data);

}
