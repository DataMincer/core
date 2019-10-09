<?php

namespace DataMincerCore\Plugin;

interface PluginInterface {

  public static function pluginId();
  public static function pluginType();
  public static function pluginDeps();
  public function name();
  public function path();
  public static function isDefault();
  public function getConfig();
  public function setData($data, $recursive = TRUE);
  public function getData();
  public static function getSchemaChildren();
  public static function getSchemaPartials();
  public static function getMixin();
  public static function getMixinSchema();
  public static function defaultConfig($data = NULL);
  public function mixin();
  public function initialize();
  public function evaluate($data = []);
  public function setDependencies($plugins);
  public function getDependencies();


}
