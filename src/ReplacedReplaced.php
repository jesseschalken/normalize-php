<?php

namespace PhpSyntaxDiff;

use Exception;

class Replacement {
    /** @var int */
    public $length = 0;
    /** @var string */
    public $replacement = '';

    /**
     * @param int    $length
     * @param string $replacement
     */
    public function __construct($length, $replacement) {
        $this->length      = $length;
        $this->replacement = $replacement;
    }
}

class ReplacedString {
    /** @var string */
    private $string;
    /** @var Replacement[][] */
    private $replacements = [];

    /**
     * @param string $string
     */
    public function __construct($string) {
        $this->string = $string;
    }

    /**
     * @param int    $offsetStart
     * @param int    $offsetEnd
     * @param string $replacement
     */
    public function replace($offsetStart, $offsetEnd, $replacement) {
        $this->replacements[$offsetStart][] = new Replacement($offsetEnd - $offsetStart, $replacement);
    }

    public function toString() {
        ksort($this->replacements, SORT_NUMERIC);
        $pos = 0;
        $str = '';
        foreach ($this->replacements as $offset => $replacements) {
            foreach ($replacements as $replacement) {
                if ($offset < $pos) {
                    throw new Exception('Replacement overlap');
                } else {
                    $str .= substr($this->string, $pos, $offset - $pos);
                    $pos = $offset + $replacement->length;
                }
                $str .= $replacement->replacement;
            }
        }
        $str .= substr($this->string, $pos);
        return $str;
    }

    public function hasChanges() {
        foreach ($this->replacements as $offset => $replaces) {
            if ($replaces) {
                return true;
            }
        }
        return false;
    }
}

