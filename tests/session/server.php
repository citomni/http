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

namespace CitOmni\Http\Tests\Session;

use CitOmni\Http\Service\Request;
use CitOmni\Http\Service\Session;
use CitOmni\Http\Tests\Support\App;

/*
 * Built-in web server router for tests/session/run.php. Not a suite of its own;
 * tests/run.php only collects run.php and database.php.
 *
 * One request runs one case against the real Session service and answers with a
 * JSON report. The server's document root (-t) is the suite's temporary directory.
 * Session files are stored in <document root>/sessions/<dir>, where ?dir= gives each
 * case its own storage directory.
 *
 * Notes:
 * - PHP warnings are collected and execution continues, as the production
 *   ErrorHandler does for non-fatal errors. A throwing handler would turn the
 *   warning into an exception before Session sees the false return value that
 *   these cases are about.
 * - The session is written and closed before the report is sent, so session files
 *   are final when run.php inspects them.
 * - The secure_* cases leave the Secure flag to Session's inference and register the
 *   real Request service, which is the only HTTPS signal they have: base_url is
 *   http, and the server listens on plain HTTP.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

require \dirname(__DIR__) . '/support/doubles.php';
require \dirname(__DIR__, 2) . '/src/Service/Request.php';
require \dirname(__DIR__, 2) . '/src/Service/Session.php';

/** Storage handler whose destroy() fails, as a failing unlink() in the files handler would. */
final class FailingDestroy extends \SessionHandler {
	public function destroy(string $id): bool {
		return false;
	}
}

$warnings = [];
\set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
	$warnings[] = $errstr;
	return true;
});

$dir = (string)($_GET['dir'] ?? '');
if (\preg_match('/^[a-z0-9-]+$/', $dir) !== 1) {
	throw new \RuntimeException('Missing or invalid ?dir=.');
}
$case = (string)($_GET['case'] ?? '');

// Session creates the storage directory on first start.
$cfg = [
	'http'    => ['base_url' => 'http://127.0.0.1'],
	'session' => [
		'name'            => 'CITSESSID',
		'save_path'       => $_SERVER['DOCUMENT_ROOT'] . '/sessions/' . $dir,
		'use_strict_mode' => true,
		'lazy_write'      => true,
		'gc_maxlifetime'  => 1440,
		'cookie_secure'   => false,
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
		'cookie_path'     => '/',
		'cookie_domain'   => null,
	],
];
$inferSecure = \str_starts_with($case, 'secure_');
if ($inferSecure) {
	$cfg['session']['cookie_secure'] = null;
	$cfg['http']['trust_proxy'] = $case === 'secure_trusted_proxy';
	$cfg['http']['trusted_proxies'] = ['127.0.0.1'];
}
$app = new App($cfg);
if ($inferSecure) {
	$app->set('request', new Request($app));
}
$session = new Session($app);
$out = [];
$attempt = static function (callable $fn) use (&$out): void {
	try {
		$fn();
		$out['exception'] = null;
	} catch (\Throwable $error) {
		$out['exception'] = $error::class . ': ' . $error->getMessage();
	}
};

switch ($case) {

	// No session cookie and no active session before regenerate().
	case 'regenerate_without_session':
		$out['active_before'] = $session->isActive();
		$attempt(static fn () => $session->regenerate(true));
		$out['active_after'] = $session->isActive();
		$out['id'] = \session_id();
		break;

	// Create a session holding one value; the next request presents its cookie.
	case 'seed':
		$session->set('kept', 'value');
		$out['id'] = $session->id();
		break;

	// Existing session (cookie from 'seed'), as at a login boundary.
	case 'regenerate_existing':
		$out['kept_before'] = $session->get('kept');
		$out['id_before'] = $session->id();
		$attempt(static fn () => $session->regenerate(true));
		$out['id'] = \session_id();
		$out['kept_after'] = $_SESSION['kept'] ?? null;
		$out['rotated_at_recorded'] = isset($_SESSION['_sess_rotated_at']);
		break;

	// The storage handler cannot destroy the old session during rotation.
	case 'regenerate_storage_failure':
		\session_set_save_handler(new FailingDestroy(), true);
		$session->set('pre_login', 'x');
		$before = $session->id();
		$attempt(static fn () => $session->regenerate(true));
		$out['no_new_id'] = \session_id() === $before || \session_id() === '';
		$out['rotated_at_recorded'] = isset($_SESSION['_sess_rotated_at']);
		break;

	// Output has already been flushed when regenerate() runs.
	case 'regenerate_after_output':
		$session->start();
		$before = $session->id();
		echo "early output\n";
		\flush();
		$attempt(static fn () => $session->regenerate(true));
		$out['id_unchanged'] = \session_id() === $before;
		break;

	// TLS ends at a proxy on 127.0.0.1, which sends X-Forwarded-Proto: https.
	// Only the secure_trusted_proxy case trusts it.
	case 'secure_trusted_proxy':
	case 'secure_untrusted_proxy':
		$session->start();
		$out['request_is_https'] = $app->request->isHttps();
		break;

	default:
		\http_response_code(404);
		return;
}

if (\session_status() === \PHP_SESSION_ACTIVE) {
	\session_write_close();
}

$out['warnings'] = $warnings;
echo \json_encode($out, \JSON_THROW_ON_ERROR);
