<?php

namespace DataMincerCore;

use Exception;
use RomaricDrigon\MetaYaml\MetaYaml;

class Schema {

  public static function validate($data, $schema, &$error) {

    // Create validator and validate the schema

    try {
      $validator = new MetaYaml($schema, TRUE);
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      return FALSE;
    }

    // Validate data

    try {
      $validated = $validator->validate($data);
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      return FALSE;
    }
    if (!$validated) {
      return FALSE;
    }
    return TRUE;
  }
}
