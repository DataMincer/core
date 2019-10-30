<?php

namespace DataMincerCore;

class Cache {

  const CACHE_BIN_DEFAULT = 'default';

  /** @var FileManager */
  protected $fileManager;
  protected $path;
  /** @var CacheBin[] */
  protected $cacheBins = [];

  public function __construct($path) {
    $this->path = $path;
  }

  public function exists($key, $bin = self::CACHE_BIN_DEFAULT) {
    $cid = $this->getCidFromString($key);
    return $this->getBin($bin)->exists($cid);
  }

  public function getData($key, $bin = self::CACHE_BIN_DEFAULT) {
    $cid = $this->getCidFromString($key);
    return $this->getBin($bin)->getData($cid);
  }

  public function getFile($key, $bin = self::CACHE_BIN_DEFAULT) {
    $cid = $this->getCidFromString($key);
    return $this->getBin($bin)->getFile($cid);
  }

  protected function getBin($bin) {
    if (!array_key_exists($bin, $this->cacheBins)) {
      $this->cacheBins[$bin] = new CacheBin($this->path, $bin);
    }
    return $this->cacheBins[$bin];
  }

  public function setData($key, $data, $bin = self::CACHE_BIN_DEFAULT) {
    $cid = $this->getCidFromString($key);
    $this->getBin($bin)->setData($cid, $data);
  }

  public function setFile($key, $path, $bin = self::CACHE_BIN_DEFAULT) {
    $cid = $this->getCidFromString($key);
    $this->getBin($bin)->setFile($cid, $path);
  }

  protected function getCidFromString($string) {
    return sha1($string,FALSE);
  }
}
