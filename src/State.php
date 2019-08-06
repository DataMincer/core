<?php

namespace DataMincerCore;

class State {

  const FILENAME = 'state.json';

  protected $filepath;
  protected $data;

  public function __construct($path) {
    $this->filepath = $path . '/' . self::FILENAME;
    $this->data = $this->readData();
  }

  protected function readData() {
    if (!file_exists($this->filepath)) {
      return [];
    }
    $data = file_get_contents($this->filepath);
    /** @noinspection PhpComposerExtensionStubsInspection */
    return json_decode($data, TRUE);
  }

  public function get($collection, $key) {
    if (array_key_exists($collection, $this->data) && array_key_exists($key, $this->data[$collection])) {
      return $this->data[$collection][$key];
    }
    return NULL;
  }

  public function set($collection, $key, $value) {
    $this->data[$collection][$key] = $value;
    $this->saveData($this->data);
  }

  protected function saveData($data) {
    file_put_contents($this->filepath, Util::toJson($data));
  }
}
