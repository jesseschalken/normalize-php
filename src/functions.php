<?php

namespace PhpSyntaxDiff;

use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

const DIR_SEP = \DIRECTORY_SEPARATOR;

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
 * @param ReplacedString $string1
 * @param Node           $node1
 * @param Node           $node2
 * @param string         $string2
 */
function replace_node(ReplacedString $string1, Node $node1, Node $node2, $string2) {
    if (get_class($node1) !== get_class($node2)) {
        $replace = true;
    } else {
        $props1 = array_flatten(node_props($node1));
        $props2 = array_flatten(node_props($node2));

        if (
            array_map(function ($v) { return $v instanceof Node ? null : $v; }, $props1) !==
            array_map(function ($v) { return $v instanceof Node ? null : $v; }, $props2)
        ) {
            $replace = true;
        } else {
            $replace = false;

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

    if ($replace) {
        $node1Start = $node1->getAttribute('startFilePos');
        $node1End   = $node1->getAttribute('endFilePos') + 1;
        $node2Start = $node2->getAttribute('startFilePos');
        $node2End   = $node2->getAttribute('endFilePos') + 1;

        $replacement = substr($string2, $node2Start, $node2End - $node2Start);
        $string1->replace($node1Start, $node1End, $replacement);
    }
}

/**
 * @param string $dir
 * @return string[]
 */
function php_files($dir) {
    $result = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $p) {
        $path = $dir . DIR_SEP . $p;
        if (filetype($path) === 'dir') {
            foreach (php_files($path) as $p_) {
                $result[] = $p . '/' . $p_;
            }
        } else if (pathinfo($p, PATHINFO_EXTENSION) === 'php') {
            $result [] = $p;
        }
    }
    return $result;
}

/**
 * @param Node[]   $nodes
 * @param callable $map
 * @return \PhpParser\Node[]
 */
function map_nodes_recursive(array $nodes, callable $map) {
    foreach ($nodes as $k => $node) {
        if (!$node instanceof Node)
            continue;
        foreach ($node->getSubNodeNames() as $prop) {
            $value = $node->$prop;
            if ($value instanceof Node) {
                $node->$prop = map_nodes_recursive([$value], $map)[0];
            } else if (is_array($value)) {
                $node->$prop = map_nodes_recursive($value, $map);
            }
        }

        $nodes[$k] = $map($node);
    }
    return $nodes;
}

/**
 * @param string $php
 * @return \PhpParser\Node[]
 */
function parse_php($php) {
    // Ignore the require_once injected by h2tp
    // Ideally this should be done after parsing, not before, but I cbf.
    $php = \preg_replace('/' . \preg_quote('require_once ($GLOBALS["HACKLIB_ROOT"]);') . '\s*/Ds', '', $php);

    $parser = (new ParserFactory)->create(ParserFactory::ONLY_PHP5, new Lexer([
        'usedAttributes' => [
            'startFilePos',
            'endFilePos',
        ],
    ]));

    $nodes = $parser->parse($php);

    $nodes = map_nodes_recursive($nodes, function (Node $node) {
        // Convert FALSE, TRUE and NULL into lowercase
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

        // Remove type annotations
        if ($node instanceof Node\Stmt\Function_) {
            $node->returnType = null;
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $node->returnType = null;
        }
        if ($node instanceof Node\Expr\Closure) {
            $node->returnType = null;
        }
        if ($node instanceof Node\Param) {
            $node->type = null;
        }

        // Add "public" to class members that don't have a visibility
        if ($node instanceof Node\Stmt\ClassMethod) {
            if (!($node->type & Node\Stmt\Class_::VISIBILITY_MODIFER_MASK)) {
                $node->type |= Node\Stmt\Class_::MODIFIER_PUBLIC;
            }
        }
        if ($node instanceof Node\Stmt\Property) {
            if (!($node->type & Node\Stmt\Class_::VISIBILITY_MODIFER_MASK)) {
                $node->type |= Node\Stmt\Class_::MODIFIER_PUBLIC;
            }
        }

        // Make all strings single quoted
        if ($node instanceof Node\Scalar\String_) {
            $node->setAttribute('kind', Node\Scalar\String_::KIND_SINGLE_QUOTED);
        }

        // Convert arrays to long form
        if ($node instanceof Node\Expr\Array_) {
            $node->setAttribute('kind', Node\Expr\Array_::KIND_LONG);
        }

        // Convert encaps ("foo $bar") into string concats ('foo '.$bar)
        if ($node instanceof Node\Scalar\Encapsed) {
            $parts = $node->parts;

            // Convert the parts into normal expressions
            $parts = \array_map(function ($part) {
                if (\is_string($part)) {
                    return new Node\Scalar\String_($part);
                }
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    return new Node\Scalar\String_($part->value);
                }
                return $part;
            }, $parts);

            switch (\count($parts)) {
                case 0:
                    $node = new Node\Scalar\String_('');
                    break;
                case 1:
                    // If it's just one expr, like "$foo", convert it to (string)$foo
                    $node = new Node\Expr\Cast\String_($parts[0]);
                    break;
                default:
                    $node = null;
                    foreach ($parts as $part) {
                        $node = $node ? new Node\Expr\BinaryOp\Concat($node, $part) : $part;
                    }
                    break;
            }
        }

        // Replace inline HTML with an echo of that HTML
        if ($node instanceof Node\Stmt\InlineHTML) {
            $node = new Node\Stmt\Echo_([new Node\Scalar\String_($node->value)]);
        }

        return $node;
    });

    return $nodes;
}

function pretty_print(array $nodes) {
    return (new Standard)->prettyPrintFile($nodes);
}

