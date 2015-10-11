#!/bin/env php
<?php

namespace NormalizePhp;

use Exception;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require_once __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

function node_props(Node $node) {
    $props = [];
    foreach ($node->getSubNodeNames() as $prop) {
        $props[$prop] = $node->$prop;
    }
    return $props;
}

function array_flatten(array $foo) {
    $flat = [];
    foreach ($foo as $k => $v) {
        if (is_array($v)) {
            foreach (array_flatten($v) as $k1 => $v1) {
                $flat["$k.$k1"] = $v1;
            }
        } else {
            $flat[$k] = $v;
        }
    }
    return $flat;
}

/**
 * @param StringReplacements $string1
 * @param Node               $node1
 * @param Node               $node2
 * @param string             $string2
 */
function replace_node(StringReplacements $string1, Node $node1, Node $node2, $string2) {
    if (get_class($node1) !== get_class($node2)) {
        $replace = true;
    } else {
        $props1 = array_flatten(node_props($node1));
        $props2 = array_flatten(node_props($node2));

        if (array_keys($props1) !== array_keys($props2)) {
            $replace = true;
        } else {
            $replace = false;
            foreach ($props1 as $k => $v1) {
                $v2 = $props2[$k];
                if (gettype($v1) !== gettype($v2)) {
                    $replace = true;
                    break;
                } else if (is_scalar($v1) || is_null($v2)) {
                    if ($v1 !== $v2) {
                        $replace = true;
                        break;
                    }
                } else if (
                    $v1 instanceof Node &&
                    $v2 instanceof Node
                ) {
                } else {
                    $replace = true;
                    break;
                }
            }

            if (!$replace) {
                foreach ($props1 as $k => $v1) {
                    $v2 = $props2[$k];
                    if (
                        $v1 instanceof Node &&
                        $v2 instanceof Node
                    ) {
                        replace_node($string1, $v1, $v2, $string2);
                    }
                }
            }
        }
    }

    if ($replace) {
        $node1Start = $node1->getAttribute('startFilePos');
        $node1End   = $node1->getAttribute('endFilePos') + 1;
        $node2Start = $node2->getAttribute('startFilePos');
        $node2End   = $node2->getAttribute('endFilePos') + 1;

        $replacement = substr($string2, $node2Start, $node2End - $node2Start);
        $string1->replace($node1Start, $node1End, $replacement);
    }
}

class StringReplacement {
    /** @var int */
    public $length = 0;
    /** @var string */
    public $replacement = '';

    /**
     * @param int    $length
     * @param string $replacement
     */
    function __construct($length, $replacement) {
        $this->length      = $length;
        $this->replacement = $replacement;
    }
}

class StringReplacements {
    /** @var string */
    private $string;
    /** @var StringReplacement[][] */
    private $replacements = [];

    /**
     * @param string $string
     */
    function __construct($string) {
        $this->string = $string;
    }

    /**
     * @param int    $offsetStart
     * @param int    $offsetEnd
     * @param string $replacement
     */
    public function replace($offsetStart, $offsetEnd, $replacement) {
        $this->replacements[$offsetStart][] = new StringReplacement($offsetEnd - $offsetStart, $replacement);
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
}

/**
 * @param string $path
 * @return string[]
 */
function recursive_scan($path) {
    switch (filetype($path)) {
        case 'file':
            return [$path];
        case 'dir':
            $paths = [];
            foreach (array_diff(scandir($path), ['.', '..']) as $part) {
                foreach (recursive_scan($path . DIRECTORY_SEPARATOR . $part) as $path2) {
                    $paths[] = $path2;
                }
            }
            return $paths;
        default:
            return [];
    }
}

/**
 * @param string $file
 * @return string[]
 */
function find_php($file) {
    switch (filetype($file)) {
        case 'file':
            return [$file];
        case 'dir':
            return array_filter(recursive_scan($file), function ($path) {
                return pathinfo($path, PATHINFO_EXTENSION) === 'php';
            });
        default:
            return [];
    }
}

class UndoSemanticChanges {
    function undo($oldFile, $newFIle) {
    }
}

class Normalizer {
    /**
     * @param Node[]   $nodes
     * @param callable $map
     * @return \PhpParser\Node[]
     */
    private static function mapNodes(array $nodes, callable $map) {
        foreach ($nodes as $k => $node) {
            if (!$node instanceof Node)
                continue;
            foreach ($node->getSubNodeNames() as $prop) {
                $value = $node->$prop;
                if ($value instanceof Node) {
                    $node->$prop = self::mapNodes([$value], $map)[0];
                } else if (is_array($value) && isset($value[0]) && $value[0] instanceof Node) {
                    $node->$prop = self::mapNodes($value, $map);
                }
            }

            $nodes[$k] = $map($node);
        }
        return $nodes;
    }

    private $parser;
    private $prettyPrinter;

    function __construct() {
        $lexer = new Lexer([
            'usedAttributes' => [
                'startFilePos',
                'endFilePos',
            ],
        ]);

        $this->parser        = (new ParserFactory)->create(ParserFactory::ONLY_PHP5, $lexer);
        $this->prettyPrinter = (new Standard);
    }

    /**
     * @param string $php
     * @return string
     */
    function normalize($php) {
        // Remove the hash-bang line if there, since
        // PhpParser doesn't support it
        if (substr($php, 0, 2) === '#!') {
            $pos      = strpos($php, "\n") + 1;
            $hashBang = substr($php, 0, $pos);
            $php      = substr($php, $pos);
        } else {
            $hashBang = '';
        }

        $nodes = $this->parser->parse($php);

        // Convert FALSE, TRUE and NULL into lowercase
        $nodes = self::mapNodes($nodes, function (Node $node) {
            if ($node instanceof Node\Expr\ConstFetch) {
                $name  = $node->name;
                $lower = strtolower($name->toString());
                if (
                    $lower === 'false' ||
                    $lower === 'true' ||
                    $lower === 'null'
                ) {
                    $name->parts = array_map('strtolower', $name->parts);
                }
            }
            return $node;
        });

        return $hashBang . $this->prettyPrinter->prettyPrintFile($nodes);
    }
}

/**
 * @param string[] $argv
 * @return int
 */
function main($argv) {
    $normalizer = new Normalizer;
    foreach (array_slice($argv, 1) as $file_) {
        foreach (find_php($file_) as $file) {
            print "$file\n";
            print $normalizer->normalize(file_get_contents($file));
            print "\n";
        }
    }
    return 0;
}

exit(main($_SERVER['argv']));

