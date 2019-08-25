<?php

namespace DataMincerCore\Exception;

use DataMincerCore\DataMincer;
use RuntimeException;
use DataMincerCore\Util;

class UnitException extends RuntimeException {

  public function __construct($message, $config = NULL, $schema = NULL) {
    if (DataMincer::isDebug()) {
      $config_text = !is_null($config) ? "Config\n" . Util::serializeArray($config, 10) : "";
      $schema_text = !is_null($schema) ? "Schema\n" . Util::serializeArray($schema, 20) : "";
      parent::__construct($message . "\n$config_text\n$schema_text", 0, NULL);
    }
    else {
      parent::__construct($message);
    }
  }

}

