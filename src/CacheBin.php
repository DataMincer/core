<?php

namespace DataMincerCore;

use DataMincerCore\Exception\DataMincerException;
use Exception;

class CacheBin {

  const CACHE_INDEX = 'cache.index';

  protected $path;
  protected $bin;
  protected $indexDb = NULL;

  public function __construct($path, $bin) {
    $this->bin = $bin;
    $this->path = $path . "/" . $bin;
    $this->indexDb = $this->readIndexDb();
  }

  public function exists($cid) {
    return array_key_exists($cid, $this->indexDb) ? $this->indexDb[$cid] : FALSE;
  }

  public function getData($cid) {
    if (!$this->exists($cid)) {
      return NULL;
    }
    $file = $this->getCacheFilePath($cid);
    if (!file_exists($file)) {
      return NULL;
    }
    $data = file_get_contents($file);
    if ($data === FALSE) {
      return NULL;
    }
    $data = $this->unSerialize($data);
    if ($data === FALSE) {
      return NULL;
    }
    return $data;
  }

  public function getFile($cid) {
    if (!$this->exists($cid)) {
      return NULL;
    }
    $file = $this->getCacheFilePath($cid);
    if (!file_exists($file)) {
      return NULL;
    }
    return $file;
  }

  public function setData($cid, $data) {
    Util::prepareDir($this->path);
    if (file_put_contents($this->getCacheFilePath($cid), serialize($data)) === FALSE) {
      throw new DataMincerException("Cannot write cache file: $cid.");
    };
    // Update cache index
    $this->indexDb[$cid] = time();
    $this->saveIndexDb();
  }

  public function setFile($cid, $file) {
    Util::prepareDir($this->path);
    copy($file, $this->getCacheFilePath($cid));
    // Update cache index
    $this->indexDb[$cid] = time();
    $this->saveIndexDb();
  }

  protected function getIndex($cid) {
    return array_key_exists($cid, $this->indexDb) ? $this->indexDb[$cid] : FALSE;
  }

  protected function getIndexDb() {
    if (is_null($this->indexDb)) {
      $this->indexDb = $this->readIndexDb();
    }
    return $this->indexDb;
  }

  protected function readIndexDb() {
    if (file_exists($cache_index_file = $this->getIndexDbFileName())) {
      $data = file_get_contents($cache_index_file);
      $data = $this->unSerialize($data);
      if ($data !== FALSE) {
        return $data;
      }
    }
    return [];
  }

  protected function getIndexDbFileName() {
    return $this->path . '/' . self::CACHE_INDEX;
  }

  // Helper functions

  protected function unSerialize($data) {
    try {
      return unserialize($data);
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  protected function getCacheFilePath($cid) {
    return $this->path . '/' . $cid;
  }

  protected function saveIndexDb() {
    file_put_contents($this->getIndexDbFileName(), serialize($this->indexDb));
  }

}
