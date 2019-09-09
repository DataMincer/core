<?php

namespace DataMincerCore;

use ReflectionClass;
use ReflectionException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use DataMincerCore\Exception\DataMincerException;

class Util {

  public static function getYaml($filename) {
    if (($content = @file_get_contents($filename)) === FALSE) {
      throw new DataMincerException("Cannot read YAML file '$filename'");
    }
    try {
      return Yaml::parse($content, TRUE);
    }
    catch (ParseException $e) {
      throw new DataMincerException("Error parsing YAML file '$filename' :\n" . $e->getMessage());
    }
  }

  public static function getJson($filename) {
    if (($content = @file_get_contents($filename)) === FALSE) {
      throw new DataMincerException("Cannot read JSON file '$filename'");
    }
    /** @noinspection PhpComposerExtensionStubsInspection */
    $decoded = json_decode($content, TRUE);
    if ($decoded === NULL) {
      /** @noinspection PhpComposerExtensionStubsInspection */
      throw new DataMincerException("Error parsing JSON file '$filename' :\n" . json_last_error_msg());
    }
    return $decoded;
  }

  public static function toJson($data, $sort = FALSE) {
    /** @noinspection PhpComposerExtensionStubsInspection */
    if ($sort) {
      static::recursiveKSort($data);
    }
    /** @noinspection PhpComposerExtensionStubsInspection */
    $result = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $result = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $result);
    return $result;
  }

  public static function recursiveKSort(&$array) {
    foreach ($array as &$value) {
      if (is_array($value)) static::recursiveKSort($value);
    }
    return ksort($array);
  }

  public static function serializeArray($array, $indent = 2) {
    return Yaml::dump($array, $indent);
  }

  public static function ifExtends($class_fqn, $parent_fqn, &$error) {
    return self::checkClass($class_fqn, NULL, $parent_fqn, $error);
  }

  public static function ifImplements($class_fqn, $interface_fqn, &$error) {
    return self::checkClass($class_fqn, $interface_fqn, NULL, $error);
  }

  public static function checkClass($class_fqn, $interface_fqn, $parent_fqn, &$error) {
    try {
      $class = new ReflectionClass($class_fqn);
      if (!is_null($interface_fqn) && !$class->implementsInterface($interface_fqn)) {
        $error = "Class '$class_fqn' doesn\'t implement required interface '$interface_fqn'.";
        return FALSE;
      }
      if (!is_null($parent_fqn) && !$class->isSubclassOf($parent_fqn)) {
        $error = "Class '$class_fqn' must be a subclass of '$parent_fqn'.";
        return FALSE;
      }
    }
    catch(ReflectionException $e) {
      $error = $e->getMessage();
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Checks if $class_name is a subclass of the $parent
   *
   * @param $class_fqn
   * @param $parent_fqn
   * @param $error
   * @return bool
   */
  public static function ensureSubclass($class_fqn, $parent_fqn, &$error) {
    if (!empty($class_fqn) && static::checkClass($class_fqn, NULL, $parent_fqn, $error)) {
      return $class_fqn;
    }
    return FALSE;
  }

  public static function arrayMergeDeep($array1, $array2, $reverse = FALSE, $preserve_integer_keys = FALSE) {
    return static::arrayMergeDeepArray([$array1, $array2], $reverse, $preserve_integer_keys);
  }

  public static function arrayMergeDeepArray(array $arrays, $reverse = FALSE, $preserve_integer_keys = FALSE) {
    $result = [];
    foreach ($arrays as $array) {
      foreach ($array as $key => $value) {

        // Renumber integer keys as array_merge_recursive() does unless
        // $preserve_integer_keys is set to TRUE. Note that PHP automatically
        // converts array keys that are integer strings (e.g., '1') to integers.
        if (is_integer($key) && !$preserve_integer_keys) {
          $result[] = $value;
        }
        elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
          $result[$key] = static::arrayMergeDeepArray([$result[$key], $value], $reverse, $preserve_integer_keys);
        }
        else {
          if (!$reverse || !array_key_exists($key, $result)) {
            $result[$key] = $value;
          }
        }
      }
    }
    return $result;
  }

  public static function dashesToCamelCase($string, $capitalizeFirstCharacter = false) {

    $str = str_replace('-', '', ucwords($string, '-'));

    if (!$capitalizeFirstCharacter) {
      $str = lcfirst($str);
    }

    return $str;
  }

  public static function isAssocArray(array $arr) {
    if ($arr === []) {
      return FALSE;
    }
    ksort($arr);
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

  public static function prepareDir($dir) {
    $result = TRUE;
    // Prepare destination dir
    if (!file_exists($dir)) {
      $result = @mkdir($dir, 0755, TRUE);
    }

    if (!$result || !is_dir($dir)) {
      throw new DataMincerException("Cannot create directory: $dir");
    }
  }

  public static function arrayPaths($array) {
    $result = [];
    foreach ($array as $key => $value) {
      if (is_array($value) && !empty($value)) {
        $res = static::arrayPaths($value);
        foreach($res as $part) {
          if (is_array($part)) {
            $result[] = array_merge([$key], $part);
          }
          else {
            $result[] = [$key, $part];
          }
        }
      }
      else {
        $result[] = [$key];
      }
    }
    return $result;
  }

}
