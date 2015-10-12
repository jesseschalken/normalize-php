<?php

namespace NormalizePhp;

require_once __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '-1');
ini_set('xdebug.max_nesting_level', '100000');

function get_files($dir) {
    $result = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $p) {
        $path = $dir . DIRECTORY_SEPARATOR . $p;
        if (filetype($path) === 'dir') {
            foreach (get_files($path) as $p_) {
                $result[] = $p . DIRECTORY_SEPARATOR . $p_;
            }
        } else {
            $result [] = $p;
        }
    }
    return $result;
}

/**
 * @param string[] $argv
 * @return int
 */
function main($argv) {
    $argv = array_slice($argv, 1);

    if (count($argv) < 2) {
        echo 'Please call with two arguments (SRC and DST)';
        return 1;
    } else {
        list($src, $dst) = $argv;
    }

    $srcFiles = filter_php(get_files($src));
    $dstFiles = filter_php(get_files($dst));

    foreach (array_diff($srcFiles, $dstFiles) as $remove) {
        print "REMOVED $remove\n";
        unlink($src . DS . $remove);
    }

    foreach (array_diff($dstFiles, $srcFiles) as $add) {
        print "ADDED $add\n";
        copy($dst . DS . $add, $src . DS . $add);
    }

    foreach (array_intersect($dstFiles, $srcFiles) as $file) {
        $srcCode  = file_get_contents($src . DS . $file);
        $dstCode  = file_get_contents($dst . DS . $file);
        $srcNodes = parse_php($srcCode, $srcHashBang);
        $dstNodes = parse_php($dstCode, $dstHashBang);

        if (
            ($srcHashBang !== $dstHashBang) ||
            (array_keys($srcNodes) !== array_keys($dstNodes))
        ) {
            $srcCode = $dstCode;
            $changed = true;
        } else {
            $srcCode = new StringReplacements($srcCode);
            foreach ($srcNodes as $k => $srcNode) {
                replace_node($srcCode, $srcNode, $dstNodes[$k], $dstCode);
            }
            $changed = $srcCode->hasChanges();
            $srcCode = $srcCode->toString();
        }

        if ($changed) {
            print "CHANGED $file\n";
            file_put_contents($src . DS . $file, $srcCode);
        } else {
            print "unchanged $file\n";
        }
    }

    return 0;
}

exit(main($_SERVER['argv']));

