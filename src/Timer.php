<?php

namespace DataMincerCore;

Timer::begin(md5('TOTAL'));

class Timer {

  const TIME_PRECISION = 3;

  /** @var Timer */
  protected static $timer = NULL;

  protected $point_probes;
  protected $paired_probes;
  protected $intervals;
  protected $pairs;
  protected $enabled;

  protected $firstProbe;
  protected $lastProbe;

  protected $maxMem;

  public static function setEnabled($enable = TRUE) {
    static::getTimer()->enabled = (bool) $enable ? TRUE : FALSE;
  }

  protected function __construct() {
    $this->firstProbe = $this->lastProbe = $this->makeProbe();
  }

  /**
   * Singleton
   * @return Timer
   */
  protected static function getTimer() {
    if (is_null(static::$timer)) {
      static::$timer = new Timer();
    }
    return static::$timer;
  }

  /**
   * Sets new starting zero
   */
  public static function reset() {
    $t = static::getTimer();
    $t->firstProbe = $t->lastProbe = $t->makeProbe();
  }

  /**
   * Sets point probe
   * @param $name
   */
  public static function probe($name) {
    static::getTimer()->probeProbe($name);
  }

  protected function probeProbe($name) {
    if (!$this->enabled) return;
    $probe = $this->makeProbe();
    $interval = $this->makeInterval($this->lastProbe, $probe);
    $this->printProbe($name, $interval);
    $this->lastProbe = $probe;
  }

  /**
   * Sets starting mark of a paired probe
   * @param $name
   */
  public static function begin($name) {
    static::getTimer()->beginProbe($name);
  }

  protected function beginProbe($name) {
    $this->paired_probes[$name][] = $this->makeProbe();
  }

  /**
   * Sets ending part of a paired probe
   * @param $name
   */
  public static function end($name) {
    static::getTimer()->endProbe($name);
  }

  protected function endProbe($name) {
    if (!$this->enabled) return;
    if (array_key_exists($name, $this->paired_probes)) {
      $probe_id = $this->arrayKeyLast($this->paired_probes[$name]);
      if (is_null($probe_id)) {
        // Unmatched 'end' - ignoring
        DataMincer::logger()->warn('Timer: unmatched END for "' . $name . '" mark');
        return;
      }
      $this->intervals[$name][] = $this->makeInterval($this->paired_probes[$name][$probe_id], $this->makeProbe());
      unset($this->paired_probes[$name][$probe_id]);
    }
  }

  public static function result($sort_column = 'time_part') {
    static::end(md5('TOTAL'));
    static::getTimer()->printResult($sort_column);
  }

  protected function printResult($sort_column = 'time_part') {
    if (!$this->enabled) return;
    // Calculate totals
    $total_interval = $this->makeInterval($this->firstProbe, $this->makeProbe());
    // Override mem with maxMem
    $total_interval[1] = $this->maxMem;
    $totals = [];
    $total_probe = md5('TOTAL');
    foreach ($this->intervals as $probe_name => $list) {
      foreach ($list as $id => $item) {
        $totals[$probe_name]['name'] = $probe_name == $total_probe ? 'TOTAL' : $probe_name;
        $totals[$probe_name]['time'] = ($totals[$probe_name]['time'] ?? 0) + $item[0];
        $totals[$probe_name]['mem'] = ($totals[$probe_name]['mem'] ?? 0) + $item[1];
        $totals[$probe_name]['count'] = ($totals[$probe_name]['count'] ?? 0) + 1;
        $totals[$probe_name]['time_part'] = round($totals[$probe_name]['time'] / $total_interval[0] * 100, 2);
        $totals[$probe_name]['mem_part'] = round($totals[$probe_name]['mem'] / $total_interval[1] * 100, 2);
      }
    }
    uasort($totals, function($a, $b) use ($sort_column) {
      if ($a[$sort_column] == $b[$sort_column]) {
        return 0;
      }
      return ($a[$sort_column] > $b[$sort_column]) ? -1 : 1;
    });
    $columns_def = [
      'name' => [NULL, 'probe', '-'],
      'time' => [[$this, 'formatTime'], 'time, s', '+'],
      'mem' => [[$this, 'formatMem'], 'mem, Mb', '+'],
      'time_part' => [[$this, 'formatPercent'], 'time, %', '+'],
      'mem_part' => [[$this, 'formatPercent'], 'mem, %', '+'],
    ];
    $columns_info = [];
    foreach($columns_def as $column => $def) {
      $columns_info[$column] = ['label' => $def[1], 'len' => strlen($def[1]), 'pad' => $def[2]];
    }
    $data = [];
    foreach ($totals as $probe_name => $total) {
      foreach($columns_def as $column => $def) {
        $v = !is_null($def[0]) ? call_user_func($def[0], $total[$column]) : $total[$column];
        $data[$probe_name][$column] = $v;
        if ($columns_info[$column]['len'] < ($l = strlen($v))) {
          $columns_info[$column]['len'] = $l;
        }
      }
    }
    DataMincer::logger()->info('Timer result:');
    // Print columns
    $line = '';
    foreach($columns_info as $column => $info) {
      $line .= str_pad($info['label'], $info['len'], ' ', STR_PAD_RIGHT) . '  ';
    }
    DataMincer::logger()->info($line);
    // Print dashes
    $line = '';
    foreach($columns_info as $column => $info) {
      $line .= str_repeat('-', $info['len']) . '  ';
    }
    DataMincer::logger()->info($line);
    foreach ($data as $probe_name => $total) {
      $line = '';
      foreach($columns_info as $column => $info) {
        $pad = $info['pad'] . $info['len'];
        $line .= sprintf("%{$pad}s", $total[$column]) . '  ';
      }
      DataMincer::logger()->info($line);
    }
  }

  protected function printProbe($name, $probe) {
    DataMincer::logger()->info(sprintf('Probe "%s": time - %s, mem - %s', $name, $this->formatTime($probe[0]), $this->formatMem($probe[1])));
  }

  protected function makeProbe() {
    return [$this->getTime(), $this->getMem()];
  }

  protected function makeInterval($probe1, $probe2) {
    return [$probe2[0] - $probe1[0], $probe2[1] - $probe1[1]];
  }

  protected function formatMem($value) {
    return number_format($value/1024/1024, 2, '.', ',');
  }

  protected function formatTime($value) {
    return number_format($value, static::TIME_PRECISION, '.', '');
  }

  protected function formatPercent($value) {
    return sprintf('%01.2f', $value);
  }

  protected function getMem() {
    $mem = memory_get_usage();
    if ($mem > $this->maxMem ?? 0) {
      $this->maxMem = $mem;
    }
    return $mem;
  }

  protected function getTime() {
    return round(microtime(TRUE), static::TIME_PRECISION);
  }

  protected function arrayKeyLast($array) {
    if (!function_exists('array_key_last')) {
      /**
       * Polyfill for array_key_last() function added in PHP 7.3.
       *
       * Get the last key of the given array without affecting
       * the internal array pointer.
       *
       * @param array $array An array
       *
       * @return mixed The last key of array if the array is not empty; NULL otherwise.
       */
      $key = NULL;
      if (is_array($array)) {
        end($array);
        $key = key($array);
      }

      return $key;
    }
    else {
      return array_key_last($array);
    }
  }

}
