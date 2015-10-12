<?php

namespace NormalizePhp;

require_once __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

/**
 * @param string[] $argv
 * @return int
 */
function main($argv) {
    foreach (array_slice($argv, 1) as $file_) {
        foreach (filter_php(recursive_scan($file_)) as $file) {
            print "$file\n";
            $nodes = parse_php(file_get_contents($file), $hashBang);
            print $hashBang . pretty_print($nodes);
            print "\n";
        }
    }
    return 0;
}

exit(main($_SERVER['argv']));

