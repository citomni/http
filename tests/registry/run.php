<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Http\Tests\Registry;

use CitOmni\Http\Boot\Registry;

/*
 * Isolated suite for the cfg reads in src/: every path the package owns is read
 * directly and exists in Registry::CFG_HTTP, and every path it does not own is
 * read with a fallback.
 *
 * Usage:
 *   php tests/registry/run.php
 *
 * Notes:
 * - Loads src/Boot/Registry.php and scans src/ with the tokenizer, so commented-out
 *   code is ignored. No kernel doubles, no Composer.
 * - Every class in src/ runs in HTTP mode, so CFG_HTTP is the baseline for all of
 *   them.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// CFG_HTTP derives paths from CITOMNI_APP_PATH; any path works here.
if (!\defined('CITOMNI_APP_PATH')) {
	\define('CITOMNI_APP_PATH', \dirname(__DIR__, 2));
}
require \dirname(__DIR__, 2) . '/src/Boot/Registry.php';

/** Guarded reads of own cfg that the reading code documents: the file below src/, a space, the dotted path. */
const DOCUMENTED_GUARDS = [
	// Kernel::guardFetchMetadata(): a missing security.fetch_metadata counts as off, so a
	// compiled config from before the node existed does not block the reset-cache webhook.
	'Kernel.php security.fetch_metadata',
	// Kernel::definePublicRootUrl(): the same for http.base_url, which boot() reads.
	'Kernel.php http.base_url',
];

$passed  = 0;
$failed  = 0;

function check(string $name, callable $test): void {
	global $passed, $failed;
	try {
		$test();
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (\Throwable $error) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $error->getMessage() . "\n");
	}
}

function ok(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

/**
 * Collect the App cfg reads in package source code.
 *
 * Behavior:
 * - Follows ->cfg->... property chains, and chains through a local variable assigned
 *   from one in the same function ($c = $this->app->cfg->security->csrf), also when the
 *   assignment continues with ?? (the local then holds the cfg node or the fallback). A
 *   local assigned the cfg root ($cfg = $this->app->cfg) is followed too; the assignment
 *   itself is not a read. A local assigned anything else stops being followed.
 * - Records each read's dotted path, the file below src/, the line, and whether it uses
 *   array syntax on a Cfg node ($this->app->cfg['http'], $c['enabled']).
 * - Marks a read guarded when it is followed by ??, is an argument of isset() or empty(),
 *   or uses ?-> anywhere in its chain.
 *
 * Notes:
 * - Every ->cfg property counts as App cfg.
 * - A chain ends at a method call (->toArray()), at a dynamic property name and at array
 *   access. A named function starts a new scope; a closure body keeps the enclosing one.
 *   Variables are not followed across functions or through arrays.
 *
 * @param  string  $srcDir  Absolute path to the package src/ directory.
 * @return list<array{file: string, line: int, path: string, guarded: bool, array: bool}>  One row per read.
 */
function scanCfgReads(string $srcDir): array {
	$reads = [];
	$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		if ($file->getExtension() !== 'php') {
			continue;
		}
		$relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($srcDir) + 1));
		$tokens = \array_values(\array_filter(\token_get_all((string)\file_get_contents($file->getPathname())),
			static fn(mixed $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
		$is = static fn(int $i, int|string $kind, ?string $text = null): bool => isset($tokens[$i])
			&& (\is_array($tokens[$i]) ? $tokens[$i][0] === $kind && ($text === null || $tokens[$i][1] === $text) : $tokens[$i] === $kind);
		$locals = [];
		$reassigned = null;
		$realiased = false;
		for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
			// -- 1. Scope and reassignment ----------------------------------
			if ($is($i, \T_FUNCTION) && ($is($i + 1, \T_STRING) || ($is($i + 1, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) && $is($i + 2, \T_STRING)))) {
				$locals = [];
				$reassigned = null;
				continue;
			}
			if ($is($i, ';') && $reassigned !== null) {
				if (!$realiased) {
					unset($locals[$reassigned]);
				}
				$reassigned = null;
			}
			if ($is($i, \T_VARIABLE) && $is($i + 1, '=') && isset($locals[$tokens[$i][1]])) {
				$reassigned = $tokens[$i][1];
				$realiased = false;
				continue;
			}

			// -- 2. Start of a chain -----------------------------------------
			if ($is($i, \T_STRING, 'cfg') && ($is($i - 1, \T_OBJECT_OPERATOR) || $is($i - 1, \T_NULLSAFE_OBJECT_OPERATOR)) && !$is($i + 1, '(')) {
				$path = [];
				$next = $i + 1;
				$start = $i - 1;
				$guarded = $is($start, \T_NULLSAFE_OBJECT_OPERATOR);
				while ($start > 0 && ($is($start - 1, \T_VARIABLE) || $is($start - 1, \T_STRING) || $is($start - 1, \T_OBJECT_OPERATOR) || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR))) {
					$guarded = $guarded || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR);
					$start--;
				}
			} elseif ($is($i, \T_VARIABLE) && isset($locals[$tokens[$i][1]])
				&& ($is($i + 1, \T_OBJECT_OPERATOR) || $is($i + 1, \T_NULLSAFE_OBJECT_OPERATOR) || $is($i + 1, '['))) {
				$path = $locals[$tokens[$i][1]];
				$next = $i + 1;
				$start = $i;
				$guarded = false;
			} else {
				continue;
			}

			// -- 3. Follow the chain and classify the read -------------------
			while (($is($next, \T_OBJECT_OPERATOR) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR)) && $is($next + 1, \T_STRING) && !$is($next + 2, '(')) {
				$guarded = $guarded || $is($next, \T_NULLSAFE_OBJECT_OPERATOR);
				$path[] = $tokens[$next + 1][1];
				$next += 2;
			}
			// The innermost open bracket around the chain decides whether it is an isset() or empty() argument.
			$depth = 0;
			for ($j = $start - 1; $j >= 0; $j--) {
				if ($is($j, ')') || $is($j, ']')) {
					$depth++;
				} elseif ($is($j, '(') || $is($j, '[')) {
					if ($depth === 0) {
						break;
					}
					$depth--;
				} elseif ($depth === 0 && ($is($j, ';') || $is($j, '{') || $is($j, '}'))) {
					$j = -1;
					break;
				}
			}
			$inGuardCall = $j > 0 && $is($j, '(') && ($is($j - 1, \T_ISSET) || $is($j - 1, \T_EMPTY));
			$guarded = $guarded || $is($next, \T_COALESCE) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR) || $inGuardCall;
			$array = $is($next, '[');

			// -- 4. Record ----------------------------------------------------
			if (($is($next, ';') || $is($next, \T_COALESCE)) && $is($start - 1, '=') && $is($start - 2, \T_VARIABLE)) {
				$locals[$tokens[$start - 2][1]] = $path;
				$realiased = $realiased || $tokens[$start - 2][1] === $reassigned;
			}
			if ($path !== [] || $array) {
				$reads[] = ['file' => $relative, 'line' => (int)$tokens[$i][2], 'path' => \implode('.', $path), 'guarded' => $guarded, 'array' => $array];
			}
			$i = $next - 1;
		}
	}
	\usort($reads, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
	return $reads;
}

/**
 * Whether the package owns a cfg path.
 *
 * Notes:
 * - A root in CFG_HTTP belongs to the package, except security, which other packages
 *   share. Below security the package owns the keys CFG_HTTP ships.
 *
 * @param  string  $path  Dotted cfg path.
 * @return bool
 */
function ownedPath(string $path): bool {
	$segments = \explode('.', $path);
	if (!\array_key_exists($segments[0], Registry::CFG_HTTP)) {
		return false;
	}
	if ($segments[0] !== 'security' || \count($segments) === 1) {
		return true;
	}
	return \array_key_exists($segments[1], Registry::CFG_HTTP['security']);
}

/** True when $path (dot-separated) names a key in $cfg. */
function cfgHasPath(array $cfg, string $path): bool {
	foreach (\explode('.', $path) as $key) {
		if (!\is_array($cfg) || !\array_key_exists($key, $cfg)) {
			return false;
		}
		$cfg = $cfg[$key];
	}
	return true;
}

/** Format a read as file:line path for a report. */
function describe(array $read): string {
	return $read['file'] . ':' . $read['line'] . ' ' . ($read['path'] !== '' ? $read['path'] : '(cfg root)');
}

/** Whether a read is one of DOCUMENTED_GUARDS. */
function documented(array $read): bool {
	return \in_array($read['file'] . ' ' . $read['path'], DOCUMENTED_GUARDS, true);
}

$reads = [];

check('The cfg scan finds known reads in src/', function () use (&$reads): void {
	$reads = scanCfgReads(\dirname(__DIR__, 2) . '/src');
	$paths = \array_column($reads, 'path');
	foreach (['security.csrf.enabled', 'http.base_url', 'view.template_layers', 'locale.charset', 'locale.icu_locale'] as $known) {
		ok(\in_array($known, $paths, true), "Scan missed {$known}; the patterns are stale");
	}
});

check('Cfg is read with property syntax', function () use (&$reads): void {
	$array = \array_map('CitOmni\Http\Tests\Registry\describe', \array_values(\array_filter($reads,
		static fn(array $read): bool => $read['array'])));
	ok($array === [], 'Array syntax, which the scan cannot follow: ' . \implode('; ', $array));
});

check('Package-owned cfg is read without fallbacks', function () use (&$reads): void {
	$guarded = \array_map('CitOmni\Http\Tests\Registry\describe', \array_values(\array_filter($reads,
		static fn(array $read): bool => !$read['array'] && $read['guarded'] && ownedPath($read['path']) && !documented($read))));
	ok($guarded === [], \count($guarded) . ' guarded read(s): ' . \implode('; ', $guarded));
});

check('Every package-owned cfg path read in src/ exists in CFG_HTTP', function () use (&$reads): void {
	$missing = \array_map('CitOmni\Http\Tests\Registry\describe', \array_values(\array_filter($reads,
		static fn(array $read): bool => !$read['array'] && ownedPath($read['path']) && !cfgHasPath(Registry::CFG_HTTP, $read['path']))));
	ok($missing === [], 'Missing from CFG_HTTP: ' . \implode('; ', $missing));
});

check('Cfg the package does not own is read with a fallback', function () use (&$reads): void {
	$direct = \array_map('CitOmni\Http\Tests\Registry\describe', \array_values(\array_filter($reads,
		static fn(array $read): bool => !$read['array'] && !$read['guarded'] && !ownedPath($read['path']))));
	ok($direct === [], \count($direct) . ' direct read(s): ' . \implode('; ', $direct));
});

check('Every documented guard is still a guarded read in src/', function () use (&$reads): void {
	$found = \array_map(static fn(array $read): string => $read['file'] . ' ' . $read['path'],
		\array_values(\array_filter($reads, static fn(array $read): bool => $read['guarded'])));
	$stale = \array_values(\array_diff(DOCUMENTED_GUARDS, $found));
	ok($stale === [], 'No longer guarded in src/: ' . \implode('; ', $stale));
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
