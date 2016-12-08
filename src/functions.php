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

function map_node_stmts(Node $node, callable $mapper) {
    $mapper = function ($stmts) use ($mapper) {
        if (\is_array($stmts)) {
            return $mapper($stmts);
        }
        return $stmts;
    };
    if ($node instanceof Node\Expr\Closure) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Case_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Catch_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\ClassMethod) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Declare_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Do_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Else_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\ElseIf_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Finally_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\For_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Foreach_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Function_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\If_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\Namespace_) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\TryCatch) {
        $node->stmts = $mapper($node->stmts);
    }
    if ($node instanceof Node\Stmt\While_) {
        $node->stmts = $mapper($node->stmts);
    }
    return $node;
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
            if (!($node->flags & Node\Stmt\Class_::VISIBILITY_MODIFER_MASK)) {
                $node->flags |= Node\Stmt\Class_::MODIFIER_PUBLIC;
            }
        }
        if ($node instanceof Node\Stmt\Property) {
            if (!($node->flags & Node\Stmt\Class_::VISIBILITY_MODIFER_MASK)) {
                $node->flags |= Node\Stmt\Class_::MODIFIER_PUBLIC;
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

        if ($node instanceof Node\Stmt\If_) {
            $else = $node->else;
            if ($else) {
                // Remove empty else block
                if (!$else->stmts) {
                    $node->else = null;
                }
                // Convert "else if"s to "elseif"s.
                if (\count($else->stmts) == 1) {
                    $stmt = $else->stmts[0];
                    if ($stmt instanceof Node\Stmt\If_) {
                        // Merge the if statement into the outer if statement as an "elseif"
                        $node->elseifs[] = new Node\Stmt\ElseIf_($stmt->cond, $stmt->stmts);
                        foreach ($stmt->elseifs as $elseIf) {
                            $node->elseifs[] = $elseIf;
                        }
                        $node->else = $stmt->else;
                    }
                }
            }
        }

        if ($node instanceof Node\Expr\BinaryOp\LogicalAnd) {
            $node = new Node\Expr\BinaryOp\BooleanAnd($node->left, $node->right);
        }

        if ($node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $node = new Node\Expr\BinaryOp\BooleanOr($node->left, $node->right);
        }

        // Normalize concatenation to be left-associative
        // Evaluation order is unchanged (left to right)
        $node = normalize_associative_op($node, Node\Expr\BinaryOp\Concat::class);
        // Normalize AND expressions
        $node = normalize_associative_op($node, Node\Expr\BinaryOp\BooleanAnd::class);
        // Normalize OR expressions
        $node = normalize_associative_op($node, Node\Expr\BinaryOp\BooleanOr::class);
        // Normalize addition expressions
        // If the value is a floating point, technically addition is NOT associative and this is
        // an invalid normalization. :(((((
        // $node = normalize_associative_op($node, Node\Expr\BinaryOp\Plus::class);
        // Normalize multiplication expressions
        // Also probably invalid in the case of floating point number :(((
        // $node = normalize_associative_op($node, Node\Expr\BinaryOp\Mul::class);

        // Add explicit "0" parameter to exit;
        if ($node instanceof Node\Expr\Exit_) {
            if (!$node->expr) {
                $node->expr = new Node\Scalar\LNumber(0);
            }
        }

        // Expand echo statements with multiple expressions into multiple statements 
        $node = map_node_stmts($node, function (array $stmts) {
            $stmts2 = [];
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Echo_) {
                    foreach ($stmt->exprs as $expr) {
                        $stmts2[] = new Node\Stmt\Echo_([$expr]);
                    }
                } else if ($stmt instanceof Node\Expr\Print_) {
                    // Convert "print" used as a statement to "echo"
                    $stmts2[] = new Node\Stmt\Echo_([$stmt->expr]);
                } else {
                    $stmts2[] = $stmt;
                }
            }
            return $stmts2;
        });

        return $node;
    });

    return $nodes;
}

function normalize_associative_op(Node $node, $class) {
    $nodes = split_associative_op($node, $class);
    $node  = join_associative_op($nodes, $class);
    return $node;
}

/**
 * @param Node   $node
 * @param string $class
 * @return Node[]
 */
function split_associative_op(Node $node, $class) {
    if ($node instanceof $class) {
        /** @var Node\Expr\BinaryOp $node */
        return array_merge(
            split_associative_op($node->left, $class),
            split_associative_op($node->right, $class)
        );
    }
    return [$node];
}

/**
 * @param Node[] $parts
 * @param string $class
 * @return Node
 */
function join_associative_op(array $parts, $class) {
    $node = null;
    foreach ($parts as $part) {
        $node = $node ? new $class($node, $part) : $part;
    }
    return $node;
}

function pretty_print(array $nodes) {
    return (new Standard)->prettyPrintFile($nodes);
}

