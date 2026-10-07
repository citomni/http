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

namespace CitOmni\Http\Tests\Request;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Built-in web server router for tests/request/run.php. Not a suite of its own;
 * tests/run.php only collects run.php and database.php.
 *
 * Every request answers with a JSON report of ip(), json(), baseUrl() and
 * pathFromAppRoot() from the real Request service.
 *
 * Query parameters (the router's settings, not part of what is tested):
 * - peer=<ip>        Stands in for REMOTE_ADDR; every request arrives from 127.0.0.1.
 * - trust=1          Sets http.trust_proxy.
 * - proxies=<a,b>    Replaces http.trusted_proxies.
 * - arg=true|false   Passed to ip(); absent means ip() without an argument.
 * - root=<url>       Defines CITOMNI_PUBLIC_ROOT_URL for this request.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

\define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);
require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Service/Request'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

if (isset($_GET['peer'])) {
	$_SERVER['REMOTE_ADDR'] = (string)$_GET['peer'];
}
if (isset($_GET['root'])) {
	\define('CITOMNI_PUBLIC_ROOT_URL', (string)$_GET['root']);
}
$http = [];
if (($_GET['trust'] ?? '') === '1') {
	$http['trust_proxy'] = true;
}
if (isset($_GET['proxies'])) {
	$http['trusted_proxies'] = \explode(',', (string)$_GET['proxies']);
}

$request = new Request(new App(['http' => mergeLastWins(Registry::CFG_HTTP['http'], $http)]));
$arg = match ($_GET['arg'] ?? '') {
	'true'  => true,
	'false' => false,
	default => null,
};

\header('Content-Type: application/json');
echo \json_encode([
	'ip'       => $request->ip($arg),
	'json'     => $request->json(),
	'base_url' => $request->baseUrl(),
	'path'     => $request->pathFromAppRoot(),
], \JSON_THROW_ON_ERROR);
