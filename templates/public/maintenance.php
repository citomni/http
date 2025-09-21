<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Maintenance | CitOmni</title>
	<meta name="viewport" content="width=400, initial-scale=1.0">
	<style>
		body {
			background: #f7f7fa;
			font-family: system-ui, sans-serif;
			margin: 0;
		}
		.maintenance-card {
			margin: 5em auto 0 auto;
			background: #fff;
			box-shadow: 0 2px 16px #0001;
			border-radius: 14px;
			max-width: 520px;
			width: 90vw;
			padding: 2.2em 2.2em 2.2em 2.2em;
		}
		.maintenance-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 2em;
		}
		.maintenance-title {
			font-size: 1.68em;
			font-weight: bold;
			color: #1a1a1a;
		}
		.maintenance-logo {
			height: 57px;
		}
		.maintenance-message {
			font-size: 1.25em;
			color: #444;
			margin-top: 0;
			margin-bottom: 1.2em;
		}
		.maintenance-meta {
			font-size: 1.02em;
			color: #555;
			margin: 0 0 1.4em 0;
		}
		.maintenance-reason {
			font-size: 1.02em;
			color: #333;
			background: #f6f6fb;
			border-radius: 8px;
			padding: .8em 1em;
			margin: 0 0 1.4em 0;
		}
		.btn {
			color: #fff;
			background-color: #0d47a1;
			border: 1px solid #0d47a1;
			box-shadow:
				0 2px 2px 0 rgba(13,71,161,.14),
				0 3px 1px -2px rgba(13,71,161,.2),
				0 1px 5px 0 rgba(13,71,161,.12);
			display: inline-block;
			padding: 0.5em 1.4em;
			font-size: 1.04em;
			font-weight: 600;
			line-height: 1.5;
			border-radius: .5rem;
			text-align: center;
			text-decoration: none;
			transition: background 0.15s, box-shadow 0.15s;
			cursor: pointer;
		}
		.btn:hover,
		.btn:focus {
			background-color: #0c4193;
			border-color: #082e68;
			text-decoration: none;
			box-shadow:
				0 6px 10px 0 rgba(13,71,161,.16),
				0 8px 5px -2px rgba(13,71,161,.22),
				0 4px 10px 0 rgba(13,71,161,.15);
		}
		.btn:active { background-color: #003c8f; border-color: #003c8f; }
		@media (max-width: 600px) {
			.maintenance-card { max-width: 78vw; width: 78vw; }
			.maintenance-title { font-size: 1.35em; }
			.btn { width: 80%; padding: 0.8em 0; font-size: 1.12em; }
		}
	</style>
</head>
<body>
	<div class="maintenance-card" role="status" aria-live="polite">
		<div class="maintenance-header">
			<span class="maintenance-title">We&rsquo;re down for maintenance</span>
			<img class="maintenance-logo" alt="CitOmni - We will be right back!" title="CitOmni - We will be right back!" src="data:image/png;base64,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">
		</div>

		<p class="maintenance-message">
			We&rsquo;re performing scheduled work right now. Please try again in a little while.
		</p>

		<?php if (!empty($reason)): ?>
			<p class="maintenance-reason">
				<strong>Reason:</strong> <?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>
			</p>
		<?php endif; ?>

		<p class="maintenance-meta">
			<?php if (!empty($resume_at)): ?>
				<strong>Expected back:</strong> <?= htmlspecialchars($resume_at, ENT_QUOTES, 'UTF-8'); ?><br>
			<?php endif; ?>
			<?php if (isset($retry_after)): ?>
				<strong>Retry-After:</strong> <?= (int)$retry_after; ?>s
			<?php endif; ?>
		</p>
		
		<p class="maintenance-meta">
			<?php if (!empty($contact_email)): ?>
				If you need assistance, please
				<a href="mailto:<?= htmlspecialchars($contact_email, ENT_QUOTES, 'UTF-8'); ?>?subject=Maintenance%20window">contact support</a>.
			<?php endif; ?>
		</p>		
		
	</div>
</body>
</html>
