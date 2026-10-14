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

namespace CitOmni\Http\Tests\ErrorHandler;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\ErrorHandler;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Router for the FixtureServer cases in run.php. Installs the real ErrorHandler with the
 * shipped baseline, templates included, the way Kernel::run() does: one top-level output
 * buffer first. Then runs one case. Logs go to <docroot>/logs.
 *
 * Query parameters:
 * - case: download, stream-csv, stream-html, php-warning, output-handler, exception,
 *   exception-chain or status-419.
 * - depth: for exception-chain, the number of previous exceptions, 0 when missing.
 * - env: CITOMNI_ENVIRONMENT, prod when missing. Developer detail is on in dev.
 * - lang: cfg locale.language, en when missing.
 * - root: CITOMNI_PUBLIC_ROOT_URL, undefined when missing.
 * - render: render.trigger, 0 when missing.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

\define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);
\define('CITOMNI_ENVIRONMENT', (string)($_GET['env'] ?? 'prod'));
if (isset($_GET['root'])) {
	\define('CITOMNI_PUBLIC_ROOT_URL', (string)$_GET['root']);
}

require \dirname(__DIR__) . '/support/doubles.php';

/** Stand-in for \CitOmni\Kernel\Arr, which the kernel doubles do not provide. */
final class ArrDouble {
	public static function mergeAssocLastWins(array $a, array $b): array {
		return mergeLastWins($a, $b);
	}
}

\class_alias(ArrDouble::class, 'CitOmni\Kernel\Arr');

require \dirname(__DIR__, 2) . '/src/Boot/Registry.php';
require \dirname(__DIR__, 2) . '/src/Service/ErrorHandler.php';

/** Exception with a string code, as a PDOException carries its SQLSTATE. */
final class StringCodeException extends \RuntimeException {
	public function __construct(string $message, string $code, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
		$this->code = $code;
	}
}

\ob_start();

$errorHandler = mergeLastWins(Registry::CFG_HTTP['error_handler'], [
	'log'    => ['path' => \CITOMNI_APP_PATH . '/logs'],
	'render' => ['trigger' => (int)($_GET['render'] ?? 0), 'detail' => ['level' => 1]],
]);
$handler = new ErrorHandler(new App([
	'error_handler' => $errorHandler,
	'locale'        => ['language' => (string)($_GET['lang'] ?? 'en')],
]));
$handler->install();

switch ((string)($_GET['case'] ?? '')) {

	// An earlier response queued download headers, as Response::download() does, and failed
	// before its body.
	case 'download':
		while (\ob_get_level() > 0) {
			\ob_end_clean();
		}
		\header('Content-Type: text/csv');
		\header('Content-Disposition: attachment; filename="report.csv"');
		\header('Content-Length: 5242880');
		throw new \RuntimeException('Download source vanished');

	// Streaming had begun: headers and the first part of the body are out.
	case 'stream-csv':
	case 'stream-html':
		while (\ob_get_level() > 0) {
			\ob_end_clean();
		}
		$csv = $_GET['case'] === 'stream-csv';
		\header('Content-Type: ' . ($csv ? 'text/csv' : 'text/html; charset=UTF-8'));
		echo $csv ? "id,amount\n1,100\n" : "<p>first rows</p>\n";
		\flush();
		throw new \RuntimeException('Stream failed');

	case 'php-warning':
		\file_get_contents(\CITOMNI_APP_PATH . '/missing/secret.php');
		echo 'not reached';
		break;

	// An app-level output handler, such as a minifier, that throws when its buffer is discarded.
	case 'output-handler':
		\ob_start(static function (string $buffer, int $phase): string {
			if (($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
				throw new \RuntimeException('Output handler refused to discard');
			}
			return $buffer;
		});
		echo '<p>half a page</p>';
		throw new \RuntimeException('Controller failed');

	case 'exception':
		echo '<p>half a page</p>';
		throw new \RuntimeException('Controller failed');

	// An exception whose code looks like a status, wrapping <depth> previous exceptions:
	// "previous 1" is its direct cause and has the string code 23000, "previous 2" has code 2,
	// and so on.
	case 'exception-chain':
		$previous = null;
		for ($i = (int)($_GET['depth'] ?? 0); $i >= 1; $i--) {
			$previous = ($i === 1)
				? new StringCodeException("previous {$i}", '23000', $previous)
				: new \LogicException("previous {$i}", $i, $previous);
		}
		throw new \RuntimeException('Order could not be saved', 404, $previous);

	case 'status-419':
		$handler->httpError(419);

	default:
		throw new \LogicException('Unknown case');
}
