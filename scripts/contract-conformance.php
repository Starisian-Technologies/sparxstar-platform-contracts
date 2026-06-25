#!/usr/bin/env php
<?php
/**
 * contract-conformance.php — does the consumer's code honor the fetched contract?
 *
 * This is the NEW enforcement logic for the SPARXSTAR Contracts Registry. It is
 * NOT spec-validation and NOT a linter. It is grounded in the real structure of
 * the contracts this registry distributes:
 *
 *   - Interfaces  (interface SPX*Interface)        — typed method obligations.
 *   - Backed enums (enum SPX*: int|string)         — a fixed, closed set of cases.
 *   - Final value objects (final class SPX*)       — public constants + methods.
 *
 * Given a directory of canonical contract .php files (pulled fresh at the pinned
 * contract-ref) and a directory of consumer code, it asserts that the consumer
 * HONORS the contract's shape:
 *
 *   A. IMPLEMENT-ALL  Every consumer class declared `implements <CanonicalInterface>`
 *                     must define every public method that interface declares.
 *                     A partial implementation is a conformance failure.
 *   B. NO-FORK        The consumer must not locally re-declare a canonical symbol
 *                     (same fully-qualified name). Depend on the contract package;
 *                     do not vendor/copy it. A local fork silently drifts.
 *   C. VALID-MEMBER   Any `CanonicalEnum::CASE` or `CanonicalClass::CONST` the
 *                     consumer references must actually exist in the canonical
 *                     symbol. Referencing a removed/renamed case is a hard break.
 *
 * Usage:
 *   php contract-conformance.php --contracts <dir> --consumer <dir> [--mode advisory|gate]
 *
 * Exit codes:
 *   0  no failures (or advisory mode — never blocks)
 *   1  one or more conformance failures AND mode=gate
 *   2  bad invocation
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------
$opts = getopt('', ['contracts:', 'consumer:', 'mode:']);
if (empty($opts['contracts']) || empty($opts['consumer'])) {
    fwrite(STDERR, "usage: php contract-conformance.php --contracts <dir> --consumer <dir> [--mode advisory|gate]\n");
    exit(2);
}
$contractsDir = rtrim($opts['contracts'], '/');
$consumerDir  = rtrim($opts['consumer'], '/');
$mode         = (isset($opts['mode']) && $opts['mode'] !== false && $opts['mode'] !== '') ? $opts['mode'] : 'advisory';
if (!in_array($mode, ['advisory', 'gate'], true)) {
    fwrite(STDERR, "--mode must be 'advisory' or 'gate'\n");
    exit(2);
}
foreach ([$contractsDir, $consumerDir] as $d) {
    if (!is_dir($d)) {
        fwrite(STDERR, "not a directory: $d\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// PHP source parser (token-based; no Composer / autoload required).
// Returns: types declared in a file + static member references made by the file.
// ---------------------------------------------------------------------------
/**
 * @return array{types: array<int, array{kind:string, fqn:string, implements:string[], methods:string[], cases:string[], consts:string[]}>, refs: array<int, array{fqn:string, member:string, line:int}>, namespace:string, uses:array<string,string>}
 */
function parse_php(string $path): array
{
    $code   = file_get_contents($path);
    $tokens = token_get_all($code);

    $namespace = '';
    $uses      = []; // alias(lower) => FQN
    $types     = [];
    $refs      = [];

    $n = count($tokens);

    // First pass: namespace + use imports.
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $ns = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $tj = $tokens[$j];
                if ($tj === ';' || $tj === '{') {
                    break;
                }
                if (is_array($tj) && in_array($tj[0], name_token_ids(), true)) {
                    $ns .= $tj[1];
                }
            }
            $namespace = trim($ns, '\\');
        } elseif ($t[0] === T_USE) {
            // Only top-level use imports (depth 0); skip trait `use` inside classes
            // by ignoring those that appear after a '{' class body — handled below.
            $import = '';
            $alias  = '';
            $sawAs  = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $tj = $tokens[$j];
                if ($tj === ';' || $tj === '{' || $tj === '(') {
                    break;
                }
                if (is_array($tj)) {
                    if ($tj[0] === T_AS) {
                        $sawAs = true;
                        continue;
                    }
                    if (in_array($tj[0], name_token_ids(), true)) {
                        if ($sawAs) {
                            $alias .= $tj[1];
                        } else {
                            $import .= $tj[1];
                        }
                    }
                }
            }
            $import = trim($import, '\\');
            if ($import !== '' && strpos($import, '\\') !== false || $import !== '') {
                if ($alias === '') {
                    $parts = explode('\\', $import);
                    $alias = end($parts);
                }
                if ($alias !== '') {
                    $uses[strtolower($alias)] = $import;
                }
            }
        }
    }

    $resolve = function (string $name) use ($namespace, $uses): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            $rest = count($parts) > 1 ? '\\' . implode('\\', array_slice($parts, 1)) : '';
            return $uses[$first] . $rest;
        }
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    };

    // Second pass: type declarations, members, and static references.
    // Track brace depth and which type (if any) owns the current body.
    $depth        = 0;
    $typeStack    = []; // depth => index into $types
    $kindTokens   = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) {
        $kindTokens[] = T_ENUM;
    }

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if ($t === '{') {
            $depth++;
            continue;
        }
        if ($t === '}') {
            unset($typeStack[$depth]);
            $depth--;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }

        // Type declaration.
        if (in_array($t[0], $kindTokens, true)) {
            // Skip `class` used as `::class` or anonymous-class edge cases is fine here.
            $kind = strtolower(token_name($t[0]) === 'T_ENUM' ? 'enum' : strtolower($t[1] === null ? '' : $t[1]));
            $kind = $t[0] === T_INTERFACE ? 'interface' : ($t[0] === T_TRAIT ? 'trait' : (defined('T_ENUM') && $t[0] === T_ENUM ? 'enum' : 'class'));

            // Read the type name.
            $name = '';
            $j = $i + 1;
            while ($j < $n && (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)) {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], name_token_ids(), true)) {
                $name = $tokens[$j][1];
            }
            if ($name === '') {
                continue; // anonymous class
            }

            // Collect implements (and interface `extends`) up to the body brace.
            $implements = [];
            $mode2      = '';
            for ($k = $j + 1; $k < $n; $k++) {
                $tk = $tokens[$k];
                if ($tk === '{') {
                    break;
                }
                if (is_array($tk)) {
                    if ($tk[0] === T_IMPLEMENTS) {
                        $mode2 = 'impl';
                        continue;
                    }
                    if ($tk[0] === T_EXTENDS) {
                        $mode2 = ($kind === 'interface') ? 'impl' : 'ext';
                        continue;
                    }
                    if ($mode2 === 'impl' && in_array($tk[0], name_token_ids(), true)) {
                        // Accumulate a possibly namespaced name across tokens.
                        $full = $tk[1];
                        while ($k + 1 < $n && is_array($tokens[$k + 1]) && in_array($tokens[$k + 1][0], name_token_ids(), true)) {
                            $full .= $tokens[$k + 1][1];
                            $k++;
                        }
                        $implements[] = $resolve($full);
                    }
                }
            }

            $fqn = $namespace !== '' ? $namespace . '\\' . $name : $name;
            $idx = count($types);
            $types[$idx] = [
                'kind'       => $kind,
                'fqn'        => $fqn,
                'implements' => $implements,
                'methods'    => [],
                'cases'      => [],
                'consts'     => [],
            ];
            // The body opens at the next '{' which bumps depth to current+1.
            $typeStack[$depth + 1] = $idx;
            continue;
        }

        // Members of the type that owns the current depth.
        $ownerIdx = $typeStack[$depth] ?? null;

        if ($ownerIdx !== null) {
            if ($t[0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                // Skip `&` for by-ref returns.
                if ($j < $n && $tokens[$j] === '&') {
                    $j++;
                    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $types[$ownerIdx]['methods'][] = $tokens[$j][1];
                }
            } elseif ($t[0] === T_CASE && $types[$ownerIdx]['kind'] === 'enum') {
                // Enum cases tokenise as T_CASE (there is no T_ENUM_CASE). Only count
                // them at the enum body level (owner kind is enum), never switch-case.
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $types[$ownerIdx]['cases'][] = $tokens[$j][1];
                }
            } elseif ($t[0] === T_CONST) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $types[$ownerIdx]['consts'][] = $tokens[$j][1];
                }
            }
        }

        // Static member references: NAME :: MEMBER  (NAME may be namespaced).
        if (in_array($t[0], name_token_ids(), true)) {
            // Build the left-hand name.
            $full = $t[1];
            $k = $i;
            while ($k + 1 < $n && is_array($tokens[$k + 1]) && in_array($tokens[$k + 1][0], name_token_ids(), true)) {
                $full .= $tokens[$k + 1][1];
                $k++;
            }
            if ($k + 1 < $n && is_array($tokens[$k + 1]) && $tokens[$k + 1][0] === T_DOUBLE_COLON) {
                $m = $k + 2;
                while ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_WHITESPACE) {
                    $m++;
                }
                if ($m < $n && is_array($tokens[$m]) && in_array($tokens[$m][0], [T_STRING], true)) {
                    $member = $tokens[$m][1];
                    if ($member !== 'class') {
                        $refs[] = [
                            'fqn'    => $resolve($full),
                            'member' => $member,
                            'line'   => is_array($t) ? ($t[2] ?? 0) : 0,
                        ];
                    }
                }
            }
        }
    }

    return ['types' => $types, 'refs' => $refs, 'namespace' => $namespace, 'uses' => $uses];
}

/** Token ids that form a (possibly qualified) name across PHP versions. */
function name_token_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $ids = [T_STRING, T_NS_SEPARATOR];
    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
        if (defined($c)) {
            $ids[] = constant($c);
        }
    }
    return $ids;
}

/** @return string[] absolute paths of every .php file under $dir */
function php_files(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// 1. Build the canonical contract surface.
// ---------------------------------------------------------------------------
$canonical = []; // fqn => ['kind','methods','cases','consts','file']
foreach (php_files($contractsDir) as $file) {
    $p = parse_php($file);
    foreach ($p['types'] as $ty) {
        $canonical[$ty['fqn']] = [
            'kind'    => $ty['kind'],
            'methods' => array_values(array_unique($ty['methods'])),
            'cases'   => array_values(array_unique($ty['cases'])),
            'consts'  => array_values(array_unique($ty['consts'])),
            'file'    => $file,
        ];
    }
}

if (empty($canonical)) {
    fwrite(STDERR, "::error::no contract symbols found under $contractsDir — nothing to check against.\n");
    exit($mode === 'gate' ? 1 : 0);
}

$canonInterfaces = array_filter($canonical, fn($c) => $c['kind'] === 'interface');

// ---------------------------------------------------------------------------
// 2. Scan consumer code and run the three assertions.
// ---------------------------------------------------------------------------
$failures = [];   // hard conformance failures
$notes    = [];   // advisory observations
$checked  = 0;    // number of consumer classes that touched a canonical symbol

$contractsReal = realpath($contractsDir);
foreach (php_files($consumerDir) as $file) {
    // Never check the fetched contract copy against itself.
    $real = realpath($file);
    if ($contractsReal && $real && strpos($real, $contractsReal) === 0) {
        continue;
    }
    $p = parse_php($file);
    $rel = ltrim(str_replace($consumerDir, '', $file), '/');

    foreach ($p['types'] as $ty) {
        // B. NO-FORK — consumer re-declares a canonical FQN.
        if (isset($canonical[$ty['fqn']])) {
            $failures[] = [
                'rule' => 'NO-FORK',
                'file' => $rel,
                'msg'  => "re-declares canonical contract symbol '{$ty['fqn']}'. Depend on the contract package; do not copy it locally.",
            ];
            $checked++;
        }

        // A. IMPLEMENT-ALL — consumer class implements a canonical interface.
        foreach ($ty['implements'] as $impl) {
            if (isset($canonInterfaces[$impl])) {
                $checked++;
                $required = $canonInterfaces[$impl]['methods'];
                $have     = array_map('strtolower', $ty['methods']);
                $missing  = [];
                foreach ($required as $m) {
                    if (!in_array(strtolower($m), $have, true)) {
                        $missing[] = $m;
                    }
                }
                if ($missing) {
                    $failures[] = [
                        'rule' => 'IMPLEMENT-ALL',
                        'file' => $rel,
                        'msg'  => "class '{$ty['fqn']}' implements '{$impl}' but does not define: " . implode(', ', $missing) . '().',
                    ];
                } else {
                    $notes[] = "OK  {$ty['fqn']} fully implements {$impl} (" . count($required) . ' method(s)).';
                }
            }
        }
    }

    // C. VALID-MEMBER — references to canonical enum cases / class constants.
    foreach ($p['refs'] as $ref) {
        if (!isset($canonical[$ref['fqn']])) {
            continue;
        }
        $sym = $canonical[$ref['fqn']];
        if ($sym['kind'] === 'enum') {
            $checked++;
            if (!in_array($ref['member'], $sym['cases'], true)) {
                $failures[] = [
                    'rule' => 'VALID-MEMBER',
                    'file' => "$rel:{$ref['line']}",
                    'msg'  => "references '{$ref['fqn']}::{$ref['member']}', which is not a case of the canonical enum (cases: " . implode(', ', $sym['cases']) . ').',
                ];
            }
        } elseif ($sym['kind'] === 'class' && !empty($sym['consts'])) {
            // Only assert when we actually catalogued constants for this class.
            if (!in_array($ref['member'], $sym['consts'], true)) {
                $checked++;
                $notes[] = "WARN {$rel}:{$ref['line']} references {$ref['fqn']}::{$ref['member']} (not a known public const; could be a method/property).";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Report.
// ---------------------------------------------------------------------------
echo "SPARXSTAR contract conformance\n";
echo "  contracts: $contractsDir (" . count($canonical) . " symbol(s), " . count($canonInterfaces) . " interface(s))\n";
echo "  consumer:  $consumerDir\n";
echo "  mode:      $mode\n";
echo "  canonical symbols: " . implode(', ', array_keys($canonical)) . "\n\n";

if ($checked === 0) {
    echo "No consumer code references any canonical contract symbol. Nothing asserted.\n";
    echo "(If this consumer is bound to a contract in MANIFEST.json, it should implement or use it.)\n";
}

foreach ($notes as $note) {
    echo "  - $note\n";
}

if (empty($failures)) {
    echo "\nPASS — no conformance failures.\n";
    exit(0);
}

echo "\nConformance failures (" . count($failures) . "):\n";
foreach ($failures as $f) {
    $line = "  [{$f['rule']}] {$f['file']}: {$f['msg']}";
    echo $line . "\n";
    // GitHub annotation.
    fwrite(STDERR, "::error file={$f['file']}::[{$f['rule']}] {$f['msg']}\n");
}

if ($mode === 'gate') {
    echo "\nGATE: blocking — consumer code does not honor the contract.\n";
    exit(1);
}
echo "\nADVISORY: not blocking. Switch enforcement_mode to 'gate' once this consumer is clean.\n";
exit(0);
