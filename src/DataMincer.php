<?php

namespace DataMincerCore;

use Psr\Log\LoggerInterface;

class DataMincer {

  public const VERSION = '0.2';

  protected static $debug = FALSE;
  /** @var LoggerInterface */
  protected static $logger;

  public static function setLogger(LoggerInterface $logger) {
    static::$logger = $logger;
  }

  public static function logger() {
    return static::$logger;
  }

  public static function setDebug($debug) {
    return static::$debug = $debug;
  }

  public static function isDebug() {
    return static::$debug;
  }

}
