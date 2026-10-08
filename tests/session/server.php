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

use CitOmni\Http\Boot\Registry;
use CitOmni\Http\Exception\CsrfVerificationException;
use CitOmni\Http\Service\Cookie;
use CitOmni\Http\Service\Csrf;
use CitOmni\Http\Service\Flash;
use CitOmni\Http\Service\Request;
use CitOmni\Http\Service\Session;
use CitOmni\Http\Tests\Support\App;
use function CitOmni\Http\Tests\Support\mergeLastWins;

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
 * - cfg.session, cfg.cookie and cfg.http are the shipped baseline (Registry::CFG_HTTP)
 *   plus per-case overrides. Session resolves its cookie through the real Cookie
 *   service, which asks the real Request service for HTTPS.
 * - The real Flash and Csrf services read and write through this Session, for the
 *   cases about reads that must not create a session.
 * - PHP warnings are collected and execution continues, as the production
 *   ErrorHandler does for non-fatal errors. A throwing handler would turn the
 *   warning into an exception before Session sees the false return value that
 *   these cases are about.
 * - The session is written and closed before the report is sent, so session files
 *   are final when run.php inspects them.
 * - The secure_* cases leave the Secure flag to the inference and have no other HTTPS
 *   signal than the request: base_url is http, and the server listens on plain HTTP.
 */

if (\PHP_SAPI !== 'cli-server') {
	throw new \RuntimeException('Built-in web server router only.');
}

require \dirname(__DIR__) . '/support/doubles.php';
foreach (['Boot/Registry', 'Enum/CsrfFailureReason', 'Exception/CsrfException', 'Exception/CsrfVerificationException', 'Service/Request', 'Service/Cookie', 'Service/Session', 'Service/Flash', 'Service/Csrf'] as $file) {
	require \dirname(__DIR__, 2) . '/src/' . $file . '.php';
}

// Registry::CFG_HTTP evaluates CITOMNI_APP_PATH; any path works here.
\define('CITOMNI_APP_PATH', $_SERVER['DOCUMENT_ROOT']);

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
$session = [
	'save_path'     => $_SERVER['DOCUMENT_ROOT'] . '/sessions/' . $dir,
	'cookie_secure' => false,
];
$cookie = [];
$http   = ['base_url' => 'http://127.0.0.1'];

switch ($case) {
	case 'secure_trusted_proxy':
	case 'secure_untrusted_proxy':
		$session['cookie_secure'] = null;
		$http['trust_proxy'] = $case === 'secure_trusted_proxy';
		$http['trusted_proxies'] = ['127.0.0.1'];
		break;
	case 'cookie_from_cookie_service':
		$session['cookie_secure'] = null;
		$cookie = ['secure' => false, 'httponly' => false, 'samesite' => 'strict', 'path' => '/app/', 'domain' => 'Example.TEST'];
		break;
	case 'cookie_session_overrides':
		$cookie = ['samesite' => 'Strict', 'domain' => 'example.test', 'path' => '/app'];
		$session += ['cookie_samesite' => 'Lax', 'cookie_domain' => '', 'cookie_path' => '/'];
		break;
	case 'cookie_none_without_secure':
		$cookie = ['samesite' => 'None', 'secure' => true];
		break;
	case 'removed_rotate_interval':
		$session['rotate_interval'] = 1800;
		break;
	case 'removed_fingerprint':
		$session['fingerprint'] = ['bind_user_agent' => false, 'bind_ip_octets' => 2, 'bind_ip_blocks' => 0];
		break;
	case 'removed_options_off':
		$session['rotate_interval'] = 0;
		$session['fingerprint'] = ['bind_user_agent' => false, 'bind_ip_octets' => 0, 'bind_ip_blocks' => 0];
		break;
	case 'save_path_not_creatable':
		\file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/a-file', '');
		$session['save_path'] = $_SERVER['DOCUMENT_ROOT'] . '/a-file/sessions';
		break;
	case 'ini_refused':
	case 'retry_after_refusal':
		// PHP 8.2+ rejects a divisor below 1, as a server lock would reject any value.
		$session['gc_divisor'] = 0;
		break;
	case 'name_refused':
		// PHP rejects a numeric session name; session_name() would only warn.
		$session['name'] = '12345';
		break;
}

$app = new App([
	'http'     => mergeLastWins(Registry::CFG_HTTP['http'], $http),
	'session'  => mergeLastWins(Registry::CFG_HTTP['session'], $session),
	'cookie'   => mergeLastWins(Registry::CFG_HTTP['cookie'], $cookie),
	'security' => ['csrf' => Registry::CFG_HTTP['security']['csrf']],
]);
$app->set('request', new Request($app));
$app->set('cookie', new Cookie($app));
$session = new Session($app);
$app->set('session', $session);
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
		break;

	// The storage handler cannot destroy the old session during rotation.
	case 'regenerate_storage_failure':
		\session_set_save_handler(new FailingDestroy(), true);
		$session->set('pre_login', 'x');
		$before = $session->id();
		$attempt(static fn () => $session->regenerate(true));
		$out['no_new_id'] = \session_id() === $before || \session_id() === '';
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

	// The session cookie follows Cookie's attributes; the cfg switches above set them.
	case 'cookie_from_cookie_service':
	case 'cookie_session_overrides':
	case 'cookie_none_without_secure':
	case 'removed_rotate_interval':
	case 'removed_fingerprint':
	case 'removed_options_off':
	case 'save_path_not_creatable':
	case 'ini_refused':
	case 'name_refused':
		$attempt(static fn () => $session->start());
		break;

	// The caller catches a refused setting and tries again in the same request.
	case 'retry_after_refusal':
		$attempt(static fn () => $session->start());
		$out['first'] = $out['exception'];
		$attempt(static fn () => $session->set('kept', 'value'));
		$out['active'] = $session->isActive();
		break;

	// Storage settings come from cfg.session, whatever php.ini says. The values set
	// first stand in for a Debian/Ubuntu php.ini with a persistent session cookie.
	case 'storage_settings':
		\ini_set('session.gc_probability', '0');
		\ini_set('session.gc_maxlifetime', '24');
		\ini_set('session.use_strict_mode', '0');
		\ini_set('session.cookie_lifetime', '3600');
		$session->start();
		foreach (['gc_maxlifetime', 'gc_probability', 'gc_divisor', 'save_path', 'use_strict_mode', 'cookie_lifetime'] as $key) {
			$out[$key] = \ini_get('session.' . $key);
		}
		break;

	// Existing session (cookie from 'seed') whose storage cannot destroy it.
	case 'destroy_storage_failure':
		\session_set_save_handler(new FailingDestroy(), true);
		$out['kept_before'] = $session->get('kept');
		$attempt(static fn () => $session->destroy());
		break;

	// Existing session (cookie from 'seed') destroyed normally.
	case 'destroy_existing':
		$out['kept_before'] = $session->get('kept');
		$attempt(static fn () => $session->destroy());
		break;

	// Every Session read on a request without a session cookie.
	case 'guest_read':
		$out['get'] = $session->get('kept');
		$out['has'] = $session->has('kept');
		$session->remove('kept');
		$session->destroy();
		$out['active'] = $session->isActive();
		break;

	// Flash readers, and writes that change nothing, on a request without a session.
	case 'flash_guest':
		$flash = new Flash($app);
		$out['pull'] = $flash->pullAll();
		$out['peek_all'] = $flash->peekAll();
		$out['peek'] = $flash->peek('error');
		$out['take'] = $flash->take('error');
		$out['old_value'] = $flash->oldValue('username', 'default');
		$out['has_old'] = $flash->hasOld('username');
		$flash->clear();
		$flash->keep(false);
		$flash->forgetMsg('error');
		$flash->forgetOld(['username']);
		$flash->old([]);
		$flash->fieldErrors([]);
		$out['active'] = $session->isActive();
		break;

	// A form post redirects with a flash message; the next requests present the cookie.
	case 'flash_write':
	case 'flash_write_kept':
		$flash = new Flash($app);
		$flash->error('Wrong password.');
		$flash->old(['username' => 'alice']);
		if ($case === 'flash_write_kept') {
			$flash->keep();
		}
		break;
	case 'flash_pull':
		$out['pull'] = (new Flash($app))->pullAll();
		break;

	// A POST without a session: A forged request, or a form left open past expiry.
	case 'csrf_guest_post':
		$csrf = new Csrf($app);
		try {
			$csrf->requireValid();
			$out['reason'] = null;
		} catch (CsrfVerificationException $error) {
			$out['reason'] = $error->reason->value;
		}
		$csrf->clear();
		$out['active'] = $session->isActive();
		break;

	// A form page issues a token; its POST presents the session cookie and the token.
	case 'csrf_issue':
		$out['token'] = (new Csrf($app))->token();
		break;
	case 'csrf_post':
		try {
			(new Csrf($app))->requireValid();
			$out['reason'] = null;
		} catch (CsrfVerificationException $error) {
			$out['reason'] = $error->reason->value;
		}
		break;

	// A read with a session cookie (from 'seed', or one whose id storage does not know).
	case 'cookie_read':
		$out['get'] = $session->get('kept');
		$out['id'] = $session->id();
		break;

	// A session written and closed earlier in this request, then read again.
	case 'read_after_write_close':
		$session->set('kept', 'value');
		$out['id_before'] = $session->id();
		\session_write_close();
		$out['get'] = $session->get('kept');
		$out['id'] = $session->id();
		break;

	// A read after destroy() in the same request (cookie from 'seed').
	case 'read_after_destroy':
		$out['kept_before'] = $session->get('kept');
		$session->destroy();
		$out['get'] = $session->get('kept');
		$out['active'] = $session->isActive();
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
