#!/bin/env php
<?php

namespace NormalizePhp;

use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require_once __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

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

class Normalizer {
    private $parser;
    private $prettyPrinter;

    function __construct() {
        $this->parser = (new ParserFactory)->create(ParserFactory::ONLY_PHP5, new Lexer());
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
            $pos = strpos($php, "\n") + 1;
            $hashBang = substr($php, 0, $pos);
            $php = substr($php, $pos);
        } else {
            $hashBang = '';
        }

        return $hashBang . $this->prettyPrinter->prettyPrintFile($this->parser->parse($php));
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

