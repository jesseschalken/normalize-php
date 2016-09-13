#!/usr/bin/env php
<?php

namespace PhpSyntaxDiff;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param string[] $argv
 * @return int
 */
function main(array $argv) {
    if (!isset($argv[1])) {
        echo 'This script requires 1 argument (the directory containing .php files)';
        return 1;
    } else {
        $dir = $argv[1];
    }

    foreach (php_files($dir) as $file) {
        print "$file\n";
        $nodes = parse_php(file_get_contents($dir . DIRECTORY_SEPARATOR . $file));
        print pretty_print($nodes);
        print "\n";
    }
    return 0;
}

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

exit(main($_SERVER['argv']));

