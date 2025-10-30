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

/**
 * CitOmni - Maintenance Mode (failsafe, zero-deps).
 *
 * Intent:
 * - Minimal, robust template that renders even if optional vars are missing.
 * - Uses the new Material-inspired CitOmni look (simplified).
 * - No JS, no external assets; safe to serve during partial outages.
 *
 * Accepted (optional) variables (from Maintenance service):
 * - string|null $reason        Human-readable reason for maintenance.
 * - string|null $resume_at     ETA text for when the site is expected back (free form).
 * - int|string|null $retry_after Retry-After seconds; shown as seconds (int cast).
 * - string|null $contact_email Mailto link if present.
 *
 * Notes:
 * - All dynamic text is safely escaped via $e().
 * - Home button uses CITOMNI_PUBLIC_ROOT_URL when defined; otherwise "/".
 */

/** @var string|null $reason */
/** @var string|null $resume_at */
/** @var int|string|null $retry_after */
/** @var string|null $contact_email */

$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Inputs (tolerant defaults)
$reason      = isset($reason) ? (string)$reason : '';
$resumeAt    = isset($resume_at) ? (string)$resume_at : '';
$retryAfter  = isset($retry_after) && $retry_after !== '' ? (int)$retry_after : null;
$contactMail = isset($contact_email) ? (string)$contact_email : '';

// Home/primary link (absolute if constant is set)
$homeHref = \defined('CITOMNI_PUBLIC_ROOT_URL') && \CITOMNI_PUBLIC_ROOT_URL !== ''
	? \rtrim((string)\CITOMNI_PUBLIC_ROOT_URL, '/')
	: '/';

// Static copy (tiny, dependable)
$title    = 'We\u2019re down for maintenance';
$subtitle = 'Scheduled work in progress';
$lead     = 'We are performing maintenance to keep things fast, secure, and reliable. Please try again shortly.';
$help     = 'If you need assistance, feel free to contact us.';

http_response_code(503); // Make intentions clear to clients/caches
header('Content-Type: text/html; charset=UTF-8');
// Send Retry-After header if present (best-effort; safe even if already sent earlier in pipeline)
if ($retryAfter !== null) {
	@header('Retry-After: ' . max(0, $retryAfter));
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title>Maintenance | CitOmni</title>
	<link rel="icon" type="image/png" sizes="16x16" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAALUSURBVHjaXJJbSBRxFMa/mZ3Lrrf1llqZSokpEmW2FumDxWZEvZhvihJFD4ESGRL01FPPERFGJfZi0IV80cSKLmTBdiHTggJTUSst87a6O7s7M30zu2p54LcD//3/z/nOOZ9gmiYEQYAVgpoAZ34FXAWVB5Scncfk9C27BNmZAdPQdf/0t9D3gU5txHcz8Ll3Mjz5Bfbb5QTOgkokHzyXrmSXtApqXA2MCMywBn7nTdOYFkQ5XlBcGRClWX3+x5mp1qPt2sQAJLu06IB7X1OqmuvpZjGPqS3C1PxdhrZwmWXeQnD4WUUkbiYqlVI2HnZXtYzx5RM7gZK9w+ISpXqoJqQHZk8ZS7NtMAwIksreTMC0SwUFWe0OTXx8GPjUEw80RBWw772OxIx6IxzATOf5Jvbdllh+nPkiWAm2KYgSFj90Yq7noqn7f/uBjmgCdVNJtVUpONjlW+i7cd06Y59we89SHgsZOkRZhTbyBn/uNePfiCbI9ZQZmh+Br88eOZI3mKLsQnDolX0hqbIRjqQsCDxjJqwN0f6JT83RF6bYrzKp5u2BklNqr1NfmkNkehSOhAxAVuAs9CK97hqk1Jz/FXDa49DDeaySaYYXeSDbK6Qf4CrywtRDHKJhzyHBUwvX1v2Yf351VUFo9J2P04WyvtgrpW0WHImZiNtejcTyE7whRR9bQc8YwXmIziSkHLmwqiA00f9Aza9oprTd8UlZDYIk35KziqKr4wDXhrUd+sSaUFQBp/uSPrkj0spyWt4VObOw2vIAtS+/UUgLOUnKyDpr+ysKtGEfIjNjpym9mLMoRiR0l+7sZM8d/HuYWCuoJ9sQ00UaSasYGyL8r9t/ikrcITjkpxDpbaCG3CfvSV/ssW0p0kvaVoZoe3SoD0ZocYxVq2i5Wn4f8/gXsey4RAYJV4R+UmeNbqWF5QnHwnpwO0ZyrN8AGSeWDV+QmeXLfwUYAJf/FN539o/lAAAAAElFTkSuQmCC" />
	<style>
		/* ------------------------------------------------------------------
		   CitOmni - Minimal Material-inspired maintenance page
		   Goals: fast paint, good a11y, zero JS, dark-mode aware.
		   ------------------------------------------------------------------ */

		:root{
			/* Light theme */
			--md-bg: #f6f7f9;
			--md-surface: #ffffff;
			--md-text: #101114;
			--md-text-muted: #444851;
			--md-primary: #2962ff;        /* Blue 700-ish */
			--md-primary-ink: #ffffff;
			--md-amber: #ffb300;          /* Amber 600-ish */
			--md-border: #e3e6eb;
			--md-ring: rgba(41,98,255,.35);
			--md-shadow: 0 1px 2px rgba(16,17,20,.06), 0 8px 24px rgba(16,17,20,.06);

			/* Rhythm */
			--radius: 16px;
			--radius-chip: 999px;
			--pad: 20px;
			--gap: 16px;
			--maxw: 760px;
		}
		@media (prefers-color-scheme: dark){
			:root{
				--md-bg: #0f1115;
				--md-surface: #151821;
				--md-text: #eef1f7;
				--md-text-muted: #b8bfcc;
				--md-primary: #82b1ff;
				--md-primary-ink: #0f1115;
				--md-amber: #ffca28;
				--md-border: #222838;
				--md-ring: rgba(130,177,255,.35);
				--md-shadow: 0 1px 2px rgba(0,0,0,.45), 0 8px 24px rgba(0,0,0,.35);
			}
		}

		* { box-sizing: border-box; }
		html, body { height: 100%; }
		body{
			margin: 0;
			background:
				radial-gradient(1200px 800px at 80% -10%, rgba(41,98,255,0.08), transparent 60%),
				radial-gradient(900px 600px at -10% 100%, rgba(255,179,0,0.07), transparent 55%),
				var(--md-bg);
			color: var(--md-text);
			font: 16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
			text-rendering: optimizeLegibility;
			-webkit-font-smoothing: antialiased;
			-moz-osx-font-smoothing: grayscale;
		}
		a { color: var(--md-primary); text-decoration: none; }
		a:hover { text-decoration: underline; }

		.container{
			min-height: 100%;
			display: grid;
			place-items: center;
			padding: calc(var(--pad) * 2) var(--pad);
		}
		.card{
			width: 100%;
			max-width: var(--maxw);
			background: var(--md-surface);
			border: 1px solid var(--md-border);
			border-radius: var(--radius);
			box-shadow: var(--md-shadow);
			overflow: clip;
		}

		.header{
			display: flex;
			align-items: center;
			gap: var(--gap);
			padding: calc(var(--pad) * 1.25);
			border-bottom: 1px solid var(--md-border);
			background: linear-gradient(180deg, rgba(41,98,255,.06), transparent 70%);
		}
		.badge{
			flex: 0 0 auto;
			padding: 8px 12px;
			border-radius: var(--radius-chip);
			font-weight: 700;
			letter-spacing: .3px;
			background: rgba(255,171,0,.15); /* amber hint */
			color: var(--md-amber);
			border: 1px solid rgba(255,171,0,.35);
			white-space: nowrap;
		}
		.title{
			margin: 0;
			font-size: clamp(20px, 3.6vw, 28px);
			line-height: 1.2;
			font-weight: 750;
			letter-spacing: .2px;
		}
		.subtitle{
			margin: 2px 0 0;
			color: var(--md-text-muted);
			font-size: 14.5px;
		}
		.logo{
			margin-inline-start: auto; /* RTL-friendly; fallback next line */
			margin-left: auto;
			flex: 0 0 auto;
			height: 40px;
			width: auto;
			object-fit: contain;
		}

		.body{
			padding: calc(var(--pad) * 1.25);
			display: grid;
			gap: var(--gap);
		}
		.lead{ margin:0; font-size: 17px; }

		.meta{
			display: grid;
			grid-template-columns: max-content 1fr;
			gap: 8px 16px;
			margin: 0;
		}
		.meta dt{ color: var(--md-text-muted); }
		.meta dd{ margin: 0; }

		.reason{
			border: 1px dashed var(--md-border);
			border-radius: calc(var(--radius) - 4px);
			background: linear-gradient(180deg, rgba(0,0,0,.03), transparent 60%);
			padding: var(--pad);
			margin: 0;
		}

		.actions{
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			padding: 0 calc(var(--pad) * 1.25) calc(var(--pad) * 1.25);
		}
		.btn{
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
		.btn:hover{ transform: translateY(-1px); border-color: rgba(41,98,255,.35); text-decoration: none; }
		.btn:focus-visible{ box-shadow: 0 0 0 3px var(--md-ring); }
		.btn--primary{
			background: linear-gradient(180deg, var(--md-primary), color-mix(in oklab, var(--md-primary), black 8%));
			color: var(--md-primary-ink);
			border-color: color-mix(in oklab, var(--md-primary), black 12%);
		}
		.btn--muted{
			background: linear-gradient(180deg, #eef1f7, #e5e9f3);
			border-color: #dee3ef;
		}
		@media (prefers-color-scheme: dark){
			.btn--muted{
				background: linear-gradient(180deg, #1b2030, #171b27);
				border-color: #252b3c;
				color: #cfd6e4;
			}
		}

		.footer{
			padding: 14px calc(var(--pad) * 1.25);
			border-top: 1px solid var(--md-border);
			display: flex;
			justify-content: space-between;
			align-items: center;
			color: var(--md-text-muted);
			font-size: 13px;
			background: linear-gradient(0deg, rgba(0,0,0,.02), transparent 65%);
		}
		.footer small{ opacity:.9; }
		.brand{ font-weight: 700; letter-spacing: .3px; }

		/* Respect motion sensitivity */
		@media (prefers-reduced-motion: reduce){
			.btn{ transition: none; }
			.btn:hover{ transform: none; }
		}
	</style>
</head>
<body>
	<div class="container">
		<main class="card" role="main" aria-labelledby="page-title">
			<header class="header">
				<span class="badge">503</span>
				<div>
					<h1 class="title" id="page-title">We&rsquo;ll be right back</h1>
					<p class="subtitle"><?= $e($subtitle) ?></p>
				</div>
				<img class="logo" alt="CitOmni - low overhead, high performance, ready for anything." title="CitOmni - low overhead, high performance, ready for anything." src="data:image/webp;base64,UklGRqQFAABXRUJQVlA4TJgFAAAvT8ATEAGGbdtGguLcW9/+Ayf3LxDR/wngwTEa6UJmp8YG5jkjCFTtBhhBRPGdrbTXtie2O0Bc/cXmr6zmXq5yZhlAZgKkFNQhgFFISaW1G5DixBeA+QBLNwA7JIkBmzaSJDnssPmjPA5v646AQCDJn3OAERi1jSTJM7P3LojljzDP/k8ALqNKwMXGh8dR1eG94ZPVZBpNKv4FNr8+XBMANLFFhDJYRxNEURjcs9FJQ7v9/zaS85sNl8OWMKVsKX72073Zj+rALuFKcAlXikpwjou9oEyxgK/DYOYcaeDCfzyDtW6d6Lhc3Bw8uMEu3EaS7Cr9BL72L/9oCOH/DwFcYekDUm1by5v9fUmZvk75SYiEX0XHv5RI+SXUQEDKG5aZEXKt7cmUZ2Zwd5d3jZfwlUAJKYES/hIoIVt2U0LowPcf7pDBPRMgatQ9ofoEOKFclFcvs57L83D0+twdP/Az98Hnce2qtrTbNqe3jSdGbdQr/+Vu+3058H3zibezjz0cX7V/fGj18qPdxZ3cPr5xP/sSNQgUA9XhJOBkFj8SEiFGxBCA6KJcuImUTEvmzATxWagtrYaDGZIwYWEFYpBL1pEfUAEpVBRTSqbMLFoNHFwGUa346o+TBS0nEZPIt7KxcWgpwrD66hIs6NSGpGzZmeXn6xWXyiDdp0ESR4TWq5eXGMTNtQIuLEPkUh93TnE1BAeEVnt0qX7BZetqEfnYuFP+oV0zDIhEYi3i7Uh1IqGICrRoJpJkMmrOiTFGQHQUiPmCJqImSg1JeElzS5JRwmlVIn2TiCDqwFmDuhLQYppJOl1KpygsiA1ImZS1YzQ3yepbupfeRAgrhWrthg3PcVOmCuuxiPsGRGlmiaYpl0kp464Mth+dsqV5CzS0DU4nQqMMNbGH9F2iogmuv/44rIUmmTBhDXIJfRcuRP0FvR8L4g5ddSSVS+j9vt7hrkKN00j/hUdqxoAXR0q9xljYnVzKA8soOqiOuaXhwYhvQ52YW9IXURqItpFCVbiTS/pSfxkpH6lI1Ilv5Xn2gChxVXHJB8wlEP8eQBjld1vhjDEm4fhwXgGS5DhejIwUyhbYwgevxFKRylyC8N99MAa+qgDDh3MqcwkciTcfwCXrlC3w7sETnhBeQ8Z4YlGxYMeYUz+YleUARC/UfDtxJPo9kcoW7ACekPSSU5Af5IH5vh7zQbLCWABbVdF3zCqIpg+saksFxrogog8oOQW2YG+vP4fbBnkhFY4ADp6IZx7IqGGFU15sdXAAex6QCLle/QOxl4CjUwDN9To/+kBLMgP29o09jQJvMxRyU3ng52SAPMCWVeQPkHVIIoiT3onWtyICvO0BdQC8YBDbvUM5IBkN4EJbA85V2FLBhv5tlLKIwB/nhzAC+FMqmKT1DuZrSs/PD2GrV6QSUaA9RcocEKWAsJK7/jkERg3n/EmHQ7RkaOHWSX013G2Qf+7+wU9sGUPUQm09FFsAZknBzdCCbiQ8At/BVsPdwl1YyoiRijRMwT5vJ50pDcoYSg0zEcK8Awp3YXK8XE4mI4VHF9sXe5pIw6+bNgwpTKeFciOZmaFoedMCgdLJs+V0+ujTASANv97KxqYJM9o0bJ1l5E0LGYqFuwpMJo+AUcMUzGZpTL4TyZvXCZtaydrARBxnyxtaTcAE7gKPpv9PeQRgNjODb//NoRBDoNWZtOaG1rdUAI4nTIAp5WZgEIkReNTCjO6fMTlmAjwqdJ5SJ1ZS04k0o9sBeAbHTIBHOGgzus6wmRjCZRvoPkODUwNOrlPZIwcG3FTmZlxbwcEZ7i5DCCvE4CWVxbQTkZJDwHK9ZgWIOvCollXFAdezoBgDTS0lLFXQy33TlFIqMczMngOsSyL+TAYRn36bfJNpJGZ4dXN31N0ROwezVK85qLUC" />
			</header>

			<section class="body">
				<p class="lead"><?= $e($lead) ?></p>

				<?php if ($reason !== ''): ?>
					<p class="reason"><strong>Reason:</strong> <?= $e($reason) ?></p>
				<?php endif; ?>

				<dl class="meta" aria-label="Maintenance details">
					<?php if ($resumeAt !== ''): ?>
						<dt>Expected back:</dt><dd><?= $e($resumeAt) ?></dd>
					<?php endif; ?>
					<?php if ($retryAfter !== null): ?>
						<dt>Retry-After:</dt><dd><?= (int)$retryAfter; ?>s</dd>
					<?php endif; ?>
					<?php if ($contactMail !== ''): ?>
						<dt>Contact:</dt>
						<dd><a href="mailto:<?= $e($contactMail) ?>?subject=Maintenance%20window"><?= $e($contactMail) ?></a></dd>
					<?php endif; ?>
				</dl>

				<p><?= $e($help) ?></p>
			</section>

			<nav class="actions" aria-label="Actions">
				<a class="btn btn--primary" href="<?= $e($homeHref) ?>">Home</a>
				<a class="btn btn--muted" href="javascript:location.reload()">Try again</a>
			</nav>

			<footer class="footer">
				<small><span class="brand">CitOmni</span> - low overhead, high performance, ready for anything.</small>
				<small>&copy; <?= date('Y') ?> CitOmni</small>
			</footer>
		</main>
	</div>
</body>
</html>
