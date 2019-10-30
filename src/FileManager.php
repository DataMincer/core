<?php

namespace DataMincerCore;

class FileManager {

  protected $options;

  public function __construct($options) {
    $this->options = $options;
  }

  protected function getSchemes() {
    $bundle = $this->options['bundleName'];
    return [
      'build' => $this->options['buildPath'] . '/' . $bundle,
      'bundle' => $this->options['bundlePath'],
      'tmp' => $this->options['tempPath'] . '/' . $bundle,
      'cache' => $this->options['cachePath'] . '/' . $bundle,
    ];
  }

  public function resolveUri($uri) {
    if (!($scheme_pos = strpos($uri, '://'))) {
      return $uri;
    }
    $scheme = substr($uri, 0, $scheme_pos);
    $path = substr($uri, $scheme_pos + 3);
    if (array_key_exists($scheme, $schemes = $this->getSchemes())) {
      return $schemes[$scheme] . '/' . $path;
    }
    else {
      // Unknown scheme, just return the original $uri
      return $uri;
    }
  }

  public function isLocal($uri) {
    return !strpos($uri, '://');
  }

  public function prepareDirectory($path) {
    Util::prepareDir($path);
  }


}
