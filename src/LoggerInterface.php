<?php

namespace DataMincerCore;

interface LoggerInterface {

  public function err($msg);
  public function warn($msg);
  public function msg($msg);
  public function info($msg);
  public function debug($msg);

}
