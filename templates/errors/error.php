<?php
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


/** @var array{
 *   status:int,status_text:string,title?:string,message?:string,
 *   error_id?:string,request_id?:string,year?:int|string,
 *   details?:array<string,mixed>|null,type?:string
 * } $data
 */
$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$status		= (int)($data['status'] ?? 500);
// $statusText	= (string)($data['status_text'] ?? 'Error');
// $title		= (string)($data['title'] ?? 'Oops, something went wrong');
// $subtitle	= $status . ' ' . $statusText;
$errorId	= (string)($data['error_id'] ?? '');
$requestId	= (string)($data['request_id'] ?? '');
$details	= $data['details'] ?? null; // array|null
$is4xx		= ($status >= 400 && $status < 500);
$is5xx		= ($status >= 500);
$badgeClass	= $is5xx ? 'badge--danger' : ($is4xx ? 'badge--warning' : 'badge--neutral');


// $txt = (require __DIR__ . "/../../language/{$data['language']}/errors.php")[$status] ?? null;
$txt = (require __DIR__ . "/../../language/{$data['language']}/errors.php") ?? null;


$meta_title		= (string) $txt[$status]['meta_title'] ?? 'Error';
$badge_variant	= (string) $txt[$status]['badge_variant'] ?? 'badge--danger';
$title			= (string) $txt[$status]['title'] ?? 'Oops, something went wrong';
$subtitle		= (string) $txt[$status]['subtitle'] ?? 'We encountered an unexpected error.';
$lead_text		= (string) $txt[$status]['lead_text'] ?? 'Please try again later.';
$help_text		= (string) $txt[$status]['help_text'] ?? 'If the problem persists, please contact our support team and provide the error ID above. This will help us quickly find and fix the issue for the benefit of all users.';

$timestamp_txt			= (string) $txt['page_txt']['timestamp'] ?? 'Timestamp';
$error_id_txt			= (string) $txt['page_txt']['error_id'] ?? 'Error ID';
$message_txt			= (string) $txt['page_txt']['message'] ?? 'Message';
$primary_label_txt		= (string) $txt['page_txt']['primary_label'] ?? 'Home';
$secondary_label_txt	= (string) $txt['page_txt']['secondary_label'] ?? 'Go Back';
$tertiary_label_txt		= (string) $txt['page_txt']['tertiary_label'] ?? 'Contact CitOmni support';
$quaternary_label_txt	= (string) $txt['page_txt']['quaternary_label'] ?? 'Report issue';

// var_dump($is5xx);
// var_dump($txt);
// var_dump($data);

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?= $e($meta_title) ?> | CitOmni</title>
	<link rel="icon" type="image/png" sizes="16x16" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAALUSURBVHjaXJJbSBRxFMa/mZ3Lrrf1llqZSokpEmW2FumDxWZEvZhvihJFD4ESGRL01FPPERFGJfZi0IV80cSKLmTBdiHTggJTUSst87a6O7s7M30zu2p54LcD//3/z/nOOZ9gmiYEQYAVgpoAZ34FXAWVB5Scncfk9C27BNmZAdPQdf/0t9D3gU5txHcz8Ll3Mjz5Bfbb5QTOgkokHzyXrmSXtApqXA2MCMywBn7nTdOYFkQ5XlBcGRClWX3+x5mp1qPt2sQAJLu06IB7X1OqmuvpZjGPqS3C1PxdhrZwmWXeQnD4WUUkbiYqlVI2HnZXtYzx5RM7gZK9w+ISpXqoJqQHZk8ZS7NtMAwIksreTMC0SwUFWe0OTXx8GPjUEw80RBWw772OxIx6IxzATOf5Jvbdllh+nPkiWAm2KYgSFj90Yq7noqn7f/uBjmgCdVNJtVUpONjlW+i7cd06Y59we89SHgsZOkRZhTbyBn/uNePfiCbI9ZQZmh+Br88eOZI3mKLsQnDolX0hqbIRjqQsCDxjJqwN0f6JT83RF6bYrzKp5u2BklNqr1NfmkNkehSOhAxAVuAs9CK97hqk1Jz/FXDa49DDeaySaYYXeSDbK6Qf4CrywtRDHKJhzyHBUwvX1v2Yf351VUFo9J2P04WyvtgrpW0WHImZiNtejcTyE7whRR9bQc8YwXmIziSkHLmwqiA00f9Aza9oprTd8UlZDYIk35KziqKr4wDXhrUd+sSaUFQBp/uSPrkj0spyWt4VObOw2vIAtS+/UUgLOUnKyDpr+ysKtGEfIjNjpym9mLMoRiR0l+7sZM8d/HuYWCuoJ9sQ00UaSasYGyL8r9t/ikrcITjkpxDpbaCG3CfvSV/ssW0p0kvaVoZoe3SoD0ZocYxVq2i5Wn4f8/gXsey4RAYJV4R+UmeNbqWF5QnHwnpwO0ZyrN8AGSeWDV+QmeXLfwUYAJf/FN539o/lAAAAAElFTkSuQmCC" />
	
	<style>
		/* ------------------------------------------------------------------
		   CitOmni HTTP — Material-inspired minimal template (no dependencies)
		   Goals: fast paint, good a11y, nice defaults, zero JS required.
		   ------------------------------------------------------------------ */

		:root {
			/* Light theme */
			--md-bg: #f6f7f9;
			--md-surface: #ffffff;
			--md-text: #101114;
			--md-text-muted: #444851;
			--md-primary: #2962ff;        /* Material-ish blue 700 */
			--md-primary-ink: #ffffff;
			--md-accent: #00c853;         /* Green A700 */
			--md-danger: #d32f2f;         /* Red 700 */
			--md-border: #e3e6eb;
			--md-ring: rgba(41,98,255,.35);
			--md-shadow: 0 1px 2px rgba(16,17,20,.06), 0 8px 24px rgba(16,17,20,.06);

			/* Sizing / rhythm */
			--radius: 16px;
			--radius-chip: 999px;
			--pad: 20px;
			--gap: 16px;
			--maxw: 880px;
		}

		@media (prefers-color-scheme: dark) {
			:root {
				--md-bg: #0f1115;
				--md-surface: #151821;
				--md-text: #eef1f7;
				--md-text-muted: #b8bfcc;
				--md-primary: #82b1ff;
				--md-primary-ink: #0f1115;
				--md-accent: #69f0ae;
				--md-danger: #ef5350;
				--md-border: #222838;
				--md-ring: rgba(130,177,255,.35);
				--md-shadow: 0 1px 2px rgba(0,0,0,.45), 0 8px 24px rgba(0,0,0,.35);
			}
		}

		* { box-sizing: border-box; }
		html, body { height: 100%; }
		body {
			margin: 0;
			background: radial-gradient(1200px 800px at 80% -10%, rgba(41,98,255,0.08), transparent 60%),
			            radial-gradient(1000px 700px at -10% 100%, rgba(0,200,83,0.08), transparent 55%),
			            var(--md-bg);
			color: var(--md-text);
			font: 16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
			text-rendering: optimizeLegibility;
			-webkit-font-smoothing: antialiased;
			-moz-osx-font-smoothing: grayscale;
		}

		a { color: var(--md-primary); text-decoration: none; }
		a:hover { text-decoration: underline; }

		.container {
			min-height: 100%;
			display: grid;
			place-items: center;
			padding: calc(var(--pad) * 2) var(--pad);
		}

		.card {
			width: 100%;
			max-width: var(--maxw);
			background: var(--md-surface);
			border: 1px solid var(--md-border);
			border-radius: var(--radius);
			box-shadow: var(--md-shadow);
			overflow: clip;
		}

		.header {
			display: flex;
			align-items: center;
			gap: var(--gap);
			padding: calc(var(--pad) * 1.25) calc(var(--pad) * 1.25) var(--pad);
			border-bottom: 1px solid var(--md-border);
			background:
				linear-gradient(180deg, rgba(41,98,255,.06), transparent 70%);
		}
		
		.header .logo{
			/* RTL-venlig: brug logical property; fald tilbage til left */
			margin-inline-start: auto;  /* elegant */
			margin-left: auto;          /* fallback */
			flex: 0 0 auto;
			height: 40px;               /* juster efter dit logo */
			width: auto;
			object-fit: contain;
		}

		/*
		@media (max-width: 520px){
			.header .logo{ height: 28px; }
		}
		*/

		.badge {
			flex: 0 0 auto;
			padding: 8px 12px;
			border-radius: var(--radius-chip);
			font-weight: 600;
			letter-spacing: .3px;
			background: rgba(41,98,255,.12);
			color: var(--md-primary);
			border: 1px solid rgba(41,98,255,.25);
			white-space: nowrap;
		}
		
		.badge--success{
			background: rgba(0,200,83,.12);   /* grøn A700 bagtone */
			color: var(--md-accent);          /* matcher temaets grønne tekst */
			border-color: rgba(0,200,83,.25);
		}

		.badge--warning{
			background: rgba(255,171,0,.15);  /* amber */
			color: #ff8f00;                    /* amber 800-ish */
			border-color: rgba(255,171,0,.35);
		}
		
		.badge--danger {
			background: rgba(211,47,47,.12);
			color: var(--md-danger);
			border-color: rgba(211,47,47,.25);
		}
		
		.badge--neutral{
			background: rgba(68,72,81,.10);
			color: var(--md-text-muted);
			border-color: rgba(68,72,81,.25);
		}

		.title {
			margin: 0;
			font-size: clamp(20px, 3.6vw, 28px);
			line-height: 1.2;
			font-weight: 750;
			letter-spacing: .2px;
		}

		.subtitle {
			margin: 2px 0 0;
			color: var(--md-text-muted);
			font-size: 14.5px;
		}

		.body {
			padding: calc(var(--pad) * 1.25);
			display: grid;
			gap: var(--gap);
		}

		.lead {
			margin: 0;
			font-size: 17px;
		}

		.panel {
			border: 1px dashed var(--md-border);
			border-radius: calc(var(--radius) - 4px);
			background: linear-gradient(180deg, rgba(0,0,0,.03), transparent 60%);
			padding: var(--pad);
			overflow: auto;
		}
		pre, code, kbd, samp {
			font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
			font-size: 13.5px;
		}
		pre {
			margin: 0;
			white-space: pre-wrap;
			word-wrap: break-word;
		}


		/* Terminal-flavored panel: understated, readable, no gimmicks */
		.panel--terminal{
			/* Base surface tuned for both light/dark schemes */
			background: #0f1115;            /* dark neutral */
			color: #cfd6e4;                 /* high-contrast text */
			border: 1px solid #222838;
			border-radius: calc(var(--radius) - 4px);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
			font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
			padding: calc(var(--pad) - 2px);
		}
		.panel--terminal pre{
			margin: 0;
			white-space: pre;               /* keep exact formatting */
		}

		/* Optional subtle “terminal chrome” header */
		.panel--terminal::before{
			/* Accessible label; keep it minimal */
			content: "CitOmni Error Panel";
			display: block;
			margin: -6px -6px 10px;
			padding: 6px 8px;
			font-size: 12px;
			letter-spacing: .2px;
			color: #9aa6b2;
			border-bottom: 1px solid #1a2030;
			background: linear-gradient(180deg, rgba(255,255,255,.04), transparent 70%);
		}

		/* Respect user preference for light mode */
		@media (prefers-color-scheme: light){
			.panel--terminal{
				background: #0f1115;        /* keep dark for terminal contrast */
				color: #e6ebf2;
				border-color: #1e2636;
			}
			.panel--terminal::before{
				color: #b3becc;
				border-bottom-color: #1e2636;
			}
		}


		.panel--code{
			background: linear-gradient(180deg, rgba(41,98,255,.05), rgba(16,17,20,.02));
			color: var(--md-text);
			border: 1px solid var(--md-border);
			border-radius: calc(var(--radius) - 4px);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
		}
		.panel--code pre{ margin:0; white-space:pre; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size:13.5px; }

		@media (prefers-color-scheme: dark){
			.panel--code{
				background: linear-gradient(180deg, rgba(130,177,255,.06), rgba(255,255,255,.02));
				border-color: color-mix(in oklab, var(--md-border), white 6%);
			}
		}


		/* Hide if empty (works in modern browsers) */
		.panel:has(pre:empty){ display:none; }

		dl.meta {
			display: grid;
			grid-template-columns: max-content 1fr;
			gap: 8px 16px;
			margin: 0;
		}
		dl.meta dt {
			color: var(--md-text-muted);
		}
		dl.meta dd {
			margin: 0;
		}

		.actions {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			padding: 0 calc(var(--pad) * 1.25) calc(var(--pad) * 1.25);
		}

		.btn {
			appearance: none;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 10px;
			padding: 10px 16px;
			border-radius: 12px;
			border: 1px solid var(--md-border);
			background: var(--md-surface);
			color: var(--md-text);
			font-weight: 600;
			text-decoration: none;
			box-shadow: var(--md-shadow);
			transition: transform .06s ease, box-shadow .12s ease, border-color .12s ease;
			outline: none;
		}
		.btn:hover {
			transform: translateY(-1px);
			border-color: rgba(41,98,255,.35);
			text-decoration: none;
		}
		.btn:focus-visible {
			box-shadow: 0 0 0 3px var(--md-ring);
		}
		.btn--primary {
			background: var(--md-primary);
			background: linear-gradient(180deg, var(--md-primary), color-mix(in oklab, var(--md-primary), black 8%));
			color: var(--md-primary-ink);
			border-color: color-mix(in oklab, var(--md-primary), black 12%);
		}
		.btn--muted {
			background: linear-gradient(180deg, #eef1f7, #e5e9f3);
			border-color: #dee3ef;
		}
		@media (prefers-color-scheme: dark) {
			.btn--muted {
				background: linear-gradient(180deg, #1b2030, #171b27);
				border-color: #252b3c;
				color: #cfd6e4;
			}
		}

		.footer {
			padding: 14px calc(var(--pad) * 1.25);
			border-top: 1px solid var(--md-border);
			display: flex;
			justify-content: space-between;
			align-items: center;
			color: var(--md-text-muted);
			font-size: 13px;
			background: linear-gradient(0deg, rgba(0,0,0,.02), transparent 65%);
		}
		.footer small { opacity: .9; }
		.brand {
			font-weight: 700;
			letter-spacing: .3px;
		}

		/* Safe, subtle motion (users with motion sensitivity are respected) */
		@media (prefers-reduced-motion: reduce) {
			.btn { transition: none; }
			.btn:hover { transform: none; }
		}		
		
	</style>
</head>
<body>
	<div class="container">
		<main class="card" role="main" aria-labelledby="page-title">
			<header class="header">
				<span class="badge <?= $e($badge_variant) ?>"><?= $e($status) ?></span>
				<div>
					<h1 class="title" id="page-title"><?= $e($title) ?></h1>
					<p class="subtitle"><?= $e($subtitle) ?></p>
				</div>
				<img class="logo" alt="CitOmni - low overhead, high performance, ready for anything." title="CitOmni - low overhead, high performance, ready for anything." src="data:image/webp;base64,UklGRqQFAABXRUJQVlA4TJgFAAAvT8ATEAGGbdtGguLcW9/+Ayf3LxDR/wngwTEa6UJmp8YG5jkjCFTtBhhBRPGdrbTXtie2O0Bc/cXmr6zmXq5yZhlAZgKkFNQhgFFISaW1G5DixBeA+QBLNwA7JIkBmzaSJDnssPmjPA5v646AQCDJn3OAERi1jSTJM7P3LojljzDP/k8ALqNKwMXGh8dR1eG94ZPVZBpNKv4FNr8+XBMANLFFhDJYRxNEURjcs9FJQ7v9/zaS85sNl8OWMKVsKX72073Zj+rALuFKcAlXikpwjou9oEyxgK/DYOYcaeDCfzyDtW6d6Lhc3Bw8uMEu3EaS7Cr9BL72L/9oCOH/DwFcYekDUm1by5v9fUmZvk75SYiEX0XHv5RI+SXUQEDKG5aZEXKt7cmUZ2Zwd5d3jZfwlUAJKYES/hIoIVt2U0LowPcf7pDBPRMgatQ9ofoEOKFclFcvs57L83D0+twdP/Az98Hnce2qtrTbNqe3jSdGbdQr/+Vu+3058H3zibezjz0cX7V/fGj18qPdxZ3cPr5xP/sSNQgUA9XhJOBkFj8SEiFGxBCA6KJcuImUTEvmzATxWagtrYaDGZIwYWEFYpBL1pEfUAEpVBRTSqbMLFoNHFwGUa346o+TBS0nEZPIt7KxcWgpwrD66hIs6NSGpGzZmeXn6xWXyiDdp0ESR4TWq5eXGMTNtQIuLEPkUh93TnE1BAeEVnt0qX7BZetqEfnYuFP+oV0zDIhEYi3i7Uh1IqGICrRoJpJkMmrOiTFGQHQUiPmCJqImSg1JeElzS5JRwmlVIn2TiCDqwFmDuhLQYppJOl1KpygsiA1ImZS1YzQ3yepbupfeRAgrhWrthg3PcVOmCuuxiPsGRGlmiaYpl0kp464Mth+dsqV5CzS0DU4nQqMMNbGH9F2iogmuv/44rIUmmTBhDXIJfRcuRP0FvR8L4g5ddSSVS+j9vt7hrkKN00j/hUdqxoAXR0q9xljYnVzKA8soOqiOuaXhwYhvQ52YW9IXURqItpFCVbiTS/pSfxkpH6lI1Ilv5Xn2gChxVXHJB8wlEP8eQBjld1vhjDEm4fhwXgGS5DhejIwUyhbYwgevxFKRylyC8N99MAa+qgDDh3MqcwkciTcfwCXrlC3w7sETnhBeQ8Z4YlGxYMeYUz+YleUARC/UfDtxJPo9kcoW7ACekPSSU5Af5IH5vh7zQbLCWABbVdF3zCqIpg+saksFxrogog8oOQW2YG+vP4fbBnkhFY4ADp6IZx7IqGGFU15sdXAAex6QCLle/QOxl4CjUwDN9To/+kBLMgP29o09jQJvMxRyU3ng52SAPMCWVeQPkHVIIoiT3onWtyICvO0BdQC8YBDbvUM5IBkN4EJbA85V2FLBhv5tlLKIwB/nhzAC+FMqmKT1DuZrSs/PD2GrV6QSUaA9RcocEKWAsJK7/jkERg3n/EmHQ7RkaOHWSX013G2Qf+7+wU9sGUPUQm09FFsAZknBzdCCbiQ8At/BVsPdwl1YyoiRijRMwT5vJ50pDcoYSg0zEcK8Awp3YXK8XE4mI4VHF9sXe5pIw6+bNgwpTKeFciOZmaFoedMCgdLJs+V0+ujTASANv97KxqYJM9o0bJ1l5E0LGYqFuwpMJo+AUcMUzGZpTL4TyZvXCZtaydrARBxnyxtaTcAE7gKPpv9PeQRgNjODb//NoRBDoNWZtOaG1rdUAI4nTIAp5WZgEIkReNTCjO6fMTlmAjwqdJ5SJ1ZS04k0o9sBeAbHTIBHOGgzus6wmRjCZRvoPkODUwNOrlPZIwcG3FTmZlxbwcEZ7i5DCCvE4CWVxbQTkZJDwHK9ZgWIOvCollXFAdezoBgDTS0lLFXQy33TlFIqMczMngOsSyL+TAYRn36bfJNpJGZ4dXN31N0ROwezVK85qLUC">
			</header>

			<section class="body">
				<p class="lead"><?= $e($lead_text) ?></p>
				
				<?php
				$tsIso  = is_array($details) ? (string)($details['ts'] ?? '') : '';
				$tsDisp = $tsIso !== '' ? (new DateTimeImmutable($tsIso))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
				?>
				<dl class="meta">
					<dt><?= $e($timestamp_txt) ?>:</dt><dd><?= $e($tsDisp) ?></dd>

					<?php if ($errorId !== ''): ?>
						<dt><strong><?= $e($error_id_txt) ?>:</strong></dt><dd><strong><?= $e($errorId) ?></strong></dd>
					<?php endif; ?>

					<?php if (\defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev' && is_array($details)): ?>
						<?php if (!empty($details['type'])): ?>
							<dt>Type:</dt><dd><?= $e((string)$details['type']) ?></dd>
						<?php endif; ?>
						<?php if (!empty($data['message'])): ?>
							<dt><?= $e($message_txt) ?>:</dt><dd><?= $e((string)$data['message']) ?></dd>
						<?php endif; ?>
						<?php if (!empty($details['file'])): ?>
							<dt>File:</dt><dd><?= $e((string)$details['file']) ?></dd>
						<?php endif; ?>
						<?php if (isset($details['line'])): ?>
							<dt>Line:</dt><dd><?= $e((string)$details['line']) ?></dd>
						<?php endif; ?>
					<?php endif; ?>
				</dl>
				
				<?php if (\defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev' && is_array($details)): ?>
					<div class="panel panel--code" role="region" aria-label="Details">
						<pre><?=
							$e(json_encode(
								$details,
								JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR
							))
						?></pre>
					</div>
				<?php else: ?>
					<p><?= $e($help_text) ?></p>
				<?php endif; ?>

			</section>
			
			
			<?php
			// -------- origin + URL (only include URL if meaningful) --------
			$origin = (\defined('CITOMNI_PUBLIC_ROOT_URL') && \CITOMNI_PUBLIC_ROOT_URL !== '')
				? \rtrim(\CITOMNI_PUBLIC_ROOT_URL, '/')
				: (static function (): string {
					$proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
						? (($_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http')
						: (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443)) ? 'https' : 'http');
					$host = $_SERVER['HTTP_X_FORWARDED_HOST']
						?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
					return $proto . '://' . $host;
				})();

			$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
			$absUrl      = $origin . $requestUri;
			$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
			$isLocalHost = \in_array($host, ['localhost', '127.0.0.1', '::1'], true);
			$includeUrl  = (\defined('CITOMNI_PUBLIC_ROOT_URL') && \CITOMNI_PUBLIC_ROOT_URL !== '') || !$isLocalHost;

			// -------- fields from ErrorHandler payload --------
			$details = is_array($data['details'] ?? null) ? $data['details'] : null;

			$errId = (string)($data['error_id'] ?? 'unknown');           // correlation id
			$msg   = (string)($data['message']  ?? '(no message)');
			$file  = (string)($details['file']  ?? '(no file)');
			$line  = isset($details['line']) ? (string)$details['line'] : '(no line)';

			// ISO timestamp from handler (baseRecord['ts']); fallback to now for display
			$tsIso = (string)($details['ts'] ?? '');
			$ts    = $tsIso !== '' ? ((new \DateTimeImmutable($tsIso))->format('Y-m-d H:i:s')) : \date('Y-m-d H:i:s');

			$env   = \defined('CITOMNI_ENVIRONMENT') ? \CITOMNI_ENVIRONMENT : 'unknown';
			$ua    = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			$ip    = $_SERVER['REMOTE_ADDR']     ?? 'unknown';

			// Trace from handler (usually an array of frames for exceptions). Make a bounded string.
			$traceStr = '';
			if ($details !== null && isset($details['trace'])) {
				if (\is_array($details['trace'])) {
					$traceStr = \json_encode($details['trace'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_PARTIAL_OUTPUT_ON_ERROR);
				} else {
					$traceStr = (string)$details['trace'];
				}
			}

			// -------- GitHub issue (richer; includes Error ID) --------

			// Bounded trace for URL safety
			$issueTraceMax = 1500;
			$issueTrace = \strlen($traceStr) > $issueTraceMax
				? \substr($traceStr, 0, $issueTraceMax) . "\n... [trace truncated]"
				: $traceStr;

			// ASCII-only, scannable title
			$titleIssue = '[' . $env . '] ' . (($host ?? '') !== '' ? $host : 'unknown-host') . ' - Error ' . $errId;

			/**
			 * FULL prefill (fills all relevant fields in the issue form).
			 * Note: Dropdowns (sev, pkg) cannot be prefilled via URL.
			 */
			$paramsFull = [
				'template' => 'citomni_error.yml',
				'title'    => $titleIssue,

				// Problem first (short IDs in the template)
				'steps' => '',        // e.g. "1) Go to ...\n2) Click ...\n3) Observe ..."
				'exp'   => '',        // Expected behavior
				'act'   => '',        // Actual behavior

				// Context/system
				'env' => $env,
				'host'=> $host,
				'url' => $includeUrl ? $absUrl : '',
				'ts'  => $ts,

				// Error details
				'msg'  => $msg,
				'file' => $file,
				'line' => $line,
				'trace'=> $issueTrace,

				// Optional metadata
				'vers'  => '',        // "citomni/http x.y.z; kernel x.y.z; PHP 8.2.x"
				'notes' => '',        // extra context
			];

			// "Minimal" variant (shorter URL). Omits steps/exp/act/vers/notes.
			$paramsMinimal = [
				'template' => 'citomni_error.yml',
				'title'    => $titleIssue,
				'env'      => $env,
				'host'     => $host,
				'url'      => $includeUrl ? $absUrl : '',
				'ts'       => $ts,
				'msg'      => $msg,
				'file'     => $file,
				'line'     => $line,
				'trace'    => $issueTrace,
			];

			// Pick one:
			$issueUrl = 'https://github.com/citomni/http/issues/new?'
				. \http_build_query($paramsFull, '', '&', \PHP_QUERY_RFC3986);
			// or:
			// $issueUrl = 'https://github.com/citomni/http/issues/new?'
			// 	. \http_build_query($paramsMinimal, '', '&', \PHP_QUERY_RFC3986);



			// -------- Email (greeting + prompt, short trace, hard cap) --------
			const MAILTO_MAX_TOTAL = 1750; // conservative total-size guard for the whole mailto URI

			$greeting = "Hi CitOmni Support\r\n\r\n<DESCRIBE YOUR ISSUE HERE>\r\n\r\n\r\nDetails:\r\n";

			$detailsEmail = ($includeUrl ? "URL: {$absUrl}\r\n" : '')
				. "Err ID: {$errId}\r\n"
				. "Env: {$env}\r\n"
				. "Msg: {$msg}\r\n"
				. "File: {$file}:{$line}";

			// Take first N lines of the trace (frames). Safe for email body size.
			$traceLines    = \preg_split("/\r\n|\n|\r/", $traceStr);
			$maxTraceLines = 3;
			$mailTrace     = '';
			if ($traceLines && \is_array($traceLines) && \count($traceLines) > 0) {
				$mailTrace = \implode("\r\n", \array_slice($traceLines, 0, $maxTraceLines));
				if (\count($traceLines) > $maxTraceLines) {
					$mailTrace .= "\r\n[trace truncated]";
				}
			}

			$subject   = 'CitOmni support request';
			$bodyEmail = $greeting
				. $detailsEmail
				. "\r\n"
				. ($mailTrace !== '' ? "Trace (first {$maxTraceLines} frames):\r\n{$mailTrace}\r\n" : "Trace: [not available]\r\n");

			// Encode + cap total URI length
			$mailtoBase = 'mailto:support@citomni.com?subject=' . \rawurlencode($subject) . '&body=';
			$mailto     = $mailtoBase . \rawurlencode($bodyEmail);

			if (\strlen($mailto) > MAILTO_MAX_TOTAL) {
				// If still too long (e.g., very long URL/UA), drop the trace entirely and re-encode.
				$bodyEmailShort = $greeting . $detailsEmail . "\r\nTrace: [omitted due to email client limits]\r\n";
				$mailto = $mailtoBase . \rawurlencode($bodyEmailShort);
			}
			?>

			<nav class="actions" aria-label="Actions">
				<a class="btn btn--primary" href="<?= \defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL : '/' ?>"><?= $e($primary_label_txt) ?></a>
				<a class="btn btn--muted" href="javascript:history.back()"><?= $e($secondary_label_txt) ?></a>
				<?php if ($is5xx): ?>
				<a class="btn" href="<?= $e($mailto) ?>"><?= $e($tertiary_label_txt) ?></a>				
				<a class="btn" href="<?= $e($issueUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $e($quaternary_label_txt) ?></a>
				<?php endif; ?>
			</nav>

			<footer class="footer">
				<small>
					<span class="brand">CitOmni</span> - low overhead, high performance, ready for anything.
				</small>
				<small>&copy; <?php echo date('Y'); ?> CitOmni</small>
			</footer>
		</main>
	</div>

</body>
</html>
