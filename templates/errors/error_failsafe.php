<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni HTTP - High-performance HTTP runtime for CitOmni applications.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */

/*
 * CitOmni — Failsafe error template (no external deps)
 * Requirements:
 * - Expects $data array from ErrorHandler::renderHtml(...)
 * - No usage of $_SERVER or app services
 * - Minimal logic; robust even under partial failures
 */

// Guard input
$d = (isset($data) && is_array($data)) ? $data : [];

// ASCII-safe escaper
$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Fields (bounded defaults)
$status      = (int)($d['status']      ?? 500);
$statusText  = (string)($d['status_text'] ?? 'Error');
$errorId     = (string)($d['error_id'] ?? 'unknown');
$title       = (string)($d['title']     ?? 'An error occurred');
$message     = (string)($d['message']   ?? 'Please try again later.');
$requestId   = (string)($d['request_id'] ?? '');
$year        = (string)($d['year'] ?? date('Y'));

// Optional details (only if provided; encoded safely)
$detailsBlock = '';
if (isset($d['details'])) {
	$raw = is_string($d['details'])
		? $d['details']
		: json_encode($d['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	$detailsBlock = '<div class="panel panel--terminal" role="region" aria-label="Details"><pre>'
		. $e($raw)
		. '</pre></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?= $status ?> <?= $e($statusText) ?> | CitOmni</title>
	<style>
		:root{
			--md-bg:#0f1115;--md-surface:#151821;--md-text:#eef1f7;--md-text-muted:#b8bfcc;
			--md-primary:#82b1ff;--md-accent:#69f0ae;--md-danger:#ef5350;--md-border:#222838;
			--md-shadow:0 1px 2px rgba(0,0,0,.45),0 8px 24px rgba(0,0,0,.35);
			--radius:16px;--pad:20px;--gap:16px;--maxw:880px;
		}
		*{box-sizing:border-box}html,body{height:100%}
		body{
			margin:0;background:radial-gradient(1200px 800px at 80% -10%,rgba(130,177,255,.08),transparent 60%),
			radial-gradient(1000px 700px at -10% 100%,rgba(105,240,174,.08),transparent 55%),var(--md-bg);
			color:var(--md-text);
			font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial,"Noto Sans","Apple Color Emoji","Segoe UI Emoji";
			-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale
		}
		.container{min-height:100%;display:grid;place-items:center;padding:calc(var(--pad)*2) var(--pad)}
		.card{width:100%;max-width:var(--maxw);background:var(--md-surface);border:1px solid var(--md-border);
			border-radius:var(--radius);box-shadow:var(--md-shadow);overflow:clip}
		.header{display:flex;align-items:center;gap:var(--gap);padding:calc(var(--pad)*1.25);border-bottom:1px solid var(--md-border);
			background:linear-gradient(180deg,rgba(130,177,255,.06),transparent 70%)}
		.badge{flex:0 0 auto;padding:8px 12px;border-radius:999px;font-weight:700;letter-spacing:.2px;
			background:rgba(239,83,80,.12);color:var(--md-danger);border:1px solid rgba(239,83,80,.25)}
		.title{margin:0;font-size:clamp(20px,3.6vw,28px);line-height:1.2;font-weight:750;letter-spacing:.2px}
		.subtitle{margin:2px 0 0;color:var(--md-text-muted);font-size:14.5px}
		.body{padding:calc(var(--pad)*1.25);display:grid;gap:var(--gap)}
		.lead{margin:0;font-size:17px}
		.panel{border:1px dashed var(--md-border);border-radius:calc(var(--radius) - 4px);
			background:linear-gradient(180deg,rgba(0,0,0,.03),transparent 60%);padding:var(--pad);overflow:auto}
		pre,code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:13.5px}
		pre{margin:0;white-space:pre-wrap;word-wrap:break-word}
		.panel--terminal{background:#0f1115;color:#cfd6e4;border:1px solid #222838;border-radius:calc(var(--radius)-4px);
			box-shadow:inset 0 1px 0 rgba(255,255,255,.03);padding:calc(var(--pad) - 2px)}
		.panel--terminal pre{white-space:pre}
		.meta{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;margin:0}
		.meta dt{color:var(--md-text-muted)}.meta dd{margin:0}
		.footer{padding:14px calc(var(--pad)*1.25);border-top:1px solid var(--md-border);display:flex;justify-content:space-between;
			align-items:center;color:var(--md-text-muted);font-size:13px;background:linear-gradient(0deg,rgba(255,255,255,.02),transparent 65%)}
		.brand{font-weight:700;letter-spacing:.3px}
	</style>
</head>
<body>
	<div class="container">
		<main class="card" role="main" aria-labelledby="page-title">
			<header class="header">
				<span class="badge">ERROR</span>
				<div>
					<h1 class="title" id="page-title"><?= $e($title) ?> <span class="sr-only">(<?= $status ?> <?= $e($statusText) ?>)</span></h1>
					<p class="subtitle"><?= $e($status) ?> <?= $e($statusText) ?></p>
				</div>
			</header>

			<section class="body">
				<p class="lead"><?= $e($message) ?></p>

				<dl class="meta" aria-label="Error metadata">
					<dt><strong>Error ID:</strong></dt><dd><strong><?= $e($errorId) ?></strong></dd>
					<?php if ($requestId !== ''): ?>
						<dt>Request ID:</dt><dd><?= $e($requestId) ?></dd>
					<?php endif; ?>
				</dl>

				<?= $detailsBlock /* printed if provided; safe-escaped */ ?>

				<p>If the problem persists, please contact support and include the <strong>Error ID</strong> above.</p>
			</section>

			<footer class="footer">
				<small><span class="brand">CitOmni</span> — low overhead, high performance.</small>
				<small>&copy; <?= $e($year) ?> CitOmni</small>
			</footer>
		</main>
	</div>
</body>
</html>
