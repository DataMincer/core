<?php

namespace DataMincerCore\Plugin;

use Exception;

/**
 * Class ParamParser
 * @package DataMincerCore\Plugin
 *
 * Parses param expression like: {"../"} word { "." word | "[" word "]" }
 * and returns chunks of the expression.
 * For example: ../../var1.var2[var.3].var4 => [../../var1, var2, var.3, var4]
 */
class ParamParser {

  private $input;
  private $buf = '';
  private $position = 0;
  /**
   * @var array
   */
  private $chunks = [];

  /**
   * @param $input
   * @return array
   * @throws Exception
   */
  public function parse($input) {
    $this->input = $input;
    $this->start();
    return $this->chunks;
  }

  /**
   * @throws Exception
   */
  public function start() {
    $chunk = '';
    while ($prefix = $this->prefix()) {
      $chunk .= $prefix;
    }
    if ($word = $this->word()) {
      $chunk .= $word;
    }
    else {
      throw new Exception("Expected word at position $this->position");
    }
    $this->chunks[] = $chunk;
    while ($chunk = $this->words()) {
      $this->chunks[] = $chunk;
    }
  }

  protected function prefix() {
    if ($this->lookahead(3) == '../') {
      $this->gets(3);
      return $this->buf;
    }
    return FALSE;
  }

  protected function word($except = ['.', '[', ']']) {
    $chunk = '';
    while (!in_array($this->lookahead(1), array_merge($except, [""]))) {
      $this->gets(1);
      $chunk .= $this->buf;
    }
    if (!empty($chunk)) {
      return $chunk;
    }
    return FALSE;
  }

  /**
   * @return bool|string
   * @throws Exception
   */
  protected function words() {
    if ($chunk = $this->word_d()) {
      return $chunk;
    }
    if ($chunk = $this->word_l()) {
      return $chunk;
    }
    return FALSE;
  }

  /**
   * @return bool|string
   * @throws Exception
   */
  protected function word_d() {
    if ($this->lookahead(1) == '.') {
      $this->gets(1);
      if ($chunk = $this->word()) {
        return $chunk;
      }
      throw new Exception("Expected word after dot at position $this->position");
    }
    return FALSE;
  }

  /**
   * @return bool|string
   * @throws Exception
   */
  protected function word_l() {
    if ($this->lookahead(1) == '[') {
      $this->gets(1);
      if (!($chunk = $this->word(['[', ']']))) {
        throw new Exception("Expected word after '[' at position $this->position");
      }
      if ($this->lookahead(1) != ']') {
        throw new Exception("Expected ']' at position $this->position");
      }
      $this->gets(1);
      return $chunk;
    }
    return FALSE;
  }

  protected function gets($length) {
    $this->buf = mb_substr($this->input, $this->position, $length);
    $this->position += $length;
  }

  protected function lookahead($length) {
    return mb_substr($this->input, $this->position, $length);
  }
}
