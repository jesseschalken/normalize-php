#!/usr/bin/env php
<?php

namespace PhpSyntaxDiff;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @param string[] $argv
 * @return int
 */
function main(array $argv) {
    $argv = array_slice($argv, 1);

    if (count($argv) < 2) {
        echo 'Please call with two arguments (SRC and DST)';
        return 1;
    } else {
        list($src, $dst) = $argv;
    }

    $srcFiles = php_files($src);
    $dstFiles = php_files($dst);

    foreach (array_diff($srcFiles, $dstFiles) as $remove) {
        print "! removed: $remove\n";
        unlink($src . DIR_SEP . $remove);
    }

    foreach (array_diff($dstFiles, $srcFiles) as $add) {
        print "! added: $add\n";
        copy($dst . DIR_SEP . $add, $src . DIR_SEP . $add);
    }

    foreach (array_intersect($dstFiles, $srcFiles) as $file) {
        $srcCode  = file_get_contents($src . DIR_SEP . $file);
        $dstCode  = file_get_contents($dst . DIR_SEP . $file);
        $srcNodes = parse_php($srcCode);
        $dstNodes = parse_php($dstCode);

        if (array_keys($srcNodes) !== array_keys($dstNodes)) {
            $srcCode = $dstCode;
            $changed = true;
        } else {
            $srcCode = new ReplacedString($srcCode);
            foreach ($srcNodes as $k => $srcNode) {
                replace_node($srcCode, $srcNode, $dstNodes[$k], $dstCode);
            }
            $changed = $srcCode->hasChanges();
            $srcCode = $srcCode->toString();
        }

        if ($changed) {
            print "! changed: $file\n";
            file_put_contents($src . DIR_SEP . $file, $srcCode);
        } else {
            print "  unchanged: $file\n";
        }
    }

    return 0;
}

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

exit(main($_SERVER['argv']));

