<?php

return [
	'package' => 'citomni/http',
	'version' => 1,
	'files' => [
		[
			'target' => 'config/citomni_http_cfg.php',
			'source' => 'install/scaffold/config/citomni_http_cfg.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_cfg.dev.php',
			'source' => 'install/scaffold/config/citomni_http_cfg.dev.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_cfg.stage.php',
			'source' => 'install/scaffold/config/citomni_http_cfg.stage.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_cfg.prod.php',
			'source' => 'install/scaffold/config/citomni_http_cfg.prod.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/services_http.php',
			'source' => 'install/scaffold/config/services_http.php.stub',
			'type' => 'service-map',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.php',
			'source' => 'install/scaffold/config/citomni_http_routes.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.dev.php',
			'source' => 'install/scaffold/config/citomni_http_routes.dev.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.stage.php',
			'source' => 'install/scaffold/config/citomni_http_routes.stage.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_http_routes.prod.php',
			'source' => 'install/scaffold/config/citomni_http_routes.prod.php.stub',
			'type' => 'routes',
			'policy' => 'create-only',
		],

		[
			'target' => 'public/.htaccess',
			'source' => 'install/scaffold/public/.htaccess.stub',
			'type' => 'server-config',
			'policy' => 'managed',
		],
		[
			'target' => 'public/index.php',
			'source' => 'install/scaffold/public/index.php.stub',
			'type' => 'entrypoint',
			'policy' => 'managed',
		],
		[
			'target' => 'public/site.webmanifest.tpl',
			'source' => 'install/scaffold/public/site.webmanifest.tpl.stub',
			'type' => 'public-template',
			'policy' => 'create-only',
		],
		[
			'target' => 'public/assets/.gitkeep',
			'source' => 'install/scaffold/public/assets/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],
		[
			'target' => 'public/uploads/.htaccess',
			'source' => 'install/scaffold/public/uploads/.htaccess.stub',
			'type' => 'server-config',
			'policy' => 'managed',
		],
		[
			'target' => 'public/uploads/u/.gitkeep',
			'source' => 'install/scaffold/public/uploads/u/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],

		[
			'target' => 'src/Http/.gitkeep',
			'source' => 'install/scaffold/src/Http/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],
		[
			'target' => 'src/Http/Controller/AppController.php',
			'source' => 'install/scaffold/src/Http/Controller/AppController.php.stub',
			'type' => 'starter-code',
			'policy' => 'create-only',
		],
		[
			'target' => 'src/Http/Exception/.gitkeep',
			'source' => 'install/scaffold/src/Http/Exception/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],

		[
			'target' => 'templates/.htaccess',
			'source' => 'install/scaffold/templates/.htaccess.stub',
			'type' => 'server-config',
			'policy' => 'managed',
		],
		[
			'target' => 'templates/public/helloworld.html',
			'source' => 'install/scaffold/templates/public/helloworld.html.stub',
			'type' => 'starter-template',
			'policy' => 'create-only',
		],
		[
			'target' => 'templates/public/maintenance.php.tpl',
			'source' => 'install/scaffold/templates/public/maintenance.php.tpl.stub',
			'type' => 'starter-template',
			'policy' => 'create-only',
		],
		[
			'target' => 'templates/member/.gitkeep',
			'source' => 'install/scaffold/templates/member/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],
		[
			'target' => 'templates/admin/.gitkeep',
			'source' => 'install/scaffold/templates/admin/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],

		[
			'target' => 'var/flags/maintenance.php',
			'source' => 'install/scaffold/var/flags/maintenance.php.stub',
			'type' => 'runtime-state-template',
			'policy' => 'create-only',
		],
		[
			'target' => 'var/secrets/webhooks.secret.php.tpl',
			'source' => 'install/scaffold/var/secrets/webhooks.secret.php.tpl.stub',
			'type' => 'secret-template',
			'policy' => 'create-only',
		],
	],
];
