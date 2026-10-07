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

namespace CitOmni\Http\Tests\WebhooksAuth;

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Exception\WebhooksAuthVerificationException;
use CitOmni\Http\Service\Nonce;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Service\WebhooksAuth;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

/*
 * Built-in web server router for tests/webhooks-auth/run.php. Not a suite of its
 * own; tests/run.php only collects run.php and database.php.
 *
 * Every request is one webhook delivery. The document root (-t) is the suite's
 * CITOMNI_APP_PATH, so the secret file and nonce ledger are the ones run.php uses.
 * Answers with a JSON report: the body requireValid() returned, or the reason it
 * threw.
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
foreach ([
	'Boot/Registry', 'Enum/WebhooksAuthFailureReason',
	'Exception/WebhooksAuthException', 'Exception/WebhooksAuthConfigException', 'Exception/WebhooksAuthVerificationException',
	'Exception/NonceException', 'Exception/NonceConfigException',
	'Service/Request', 'Service/Nonce', 'Service/WebhooksAuth',
] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

$app = new App([
	'http'     => Registry::CFG_HTTP['http'],
	'webhooks' => mergeLastWins(Registry::CFG_HTTP['webhooks'], ['enabled' => true]),
	'nonce'    => Registry::CFG_HTTP['nonce'],
]);
$app->set('request', new Request($app));
$app->set('nonce', new Nonce($app));

try {
	$report = ['body' => (new WebhooksAuth($app))->requireValid(), 'reason' => null];
} catch (WebhooksAuthVerificationException $error) {
	$report = ['body' => null, 'reason' => $error->reason->value];
}

\header('Content-Type: application/json');
echo \json_encode($report, \JSON_THROW_ON_ERROR);
