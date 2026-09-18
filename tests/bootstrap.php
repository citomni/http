<?php
declare(strict_types=1);

/* Standalone test doubles only. This file is not loaded by the framework. */
namespace CitOmni\Kernel\Service {
	abstract class BaseService {
		protected object $app;
		protected array $options;
		public function __construct(object $app, array $options = []) {
			$this->app = $app;
			$this->options = $options;
			$this->init();
		}
		protected function init(): void {}
	}
}

namespace CitOmni\Http\Tests {
	final class Cfg {
		public function __construct(private array $data) {}
		public function __isset(string $key): bool { return isset($this->data[$key]); }
		public function __get(string $key): mixed {
			if (!\array_key_exists($key, $this->data)) {
				throw new \OutOfBoundsException($key);
			}
			$value = $this->data[$key];
			return \is_array($value) && !\array_is_list($value) ? new self($value) : $value;
		}
		public function toArray(): array { return $this->data; }
	}

	final class Request {
		public string $path = '/admin/users.html';
		public array $query = [];
		public int $pathCalls = 0;
		public function get(string $key): mixed { return $this->query[$key] ?? null; }
		public function pathFromAppRoot(): string { $this->pathCalls++; return $this->path; }
	}

	final class App {
		public Cfg $cfg;
		public Request $request;
		public array $services = [];
		public array $packages = ['citomni/infrastructure' => true, 'citomni/authenticate' => true];
		public function __construct(array $layers, array $view = []) {
			$this->cfg = new Cfg([
				'identity' => ['app_name' => 'Test app'],
				'http' => ['base_url' => 'https://example.test/base/'],
				'locale' => ['language' => 'da', 'charset' => 'UTF-8'],
				'security' => ['csrf' => ['enabled' => true], 'honeypot_protection' => false, 'form_action_switching' => true, 'captcha_protection' => false],
				'view' => $view + ['template_layers' => $layers, 'cache_enabled' => true, 'asset_version' => 'v1', 'marketing_scripts' => '<tracking>'],
			]);
			$this->request = new Request();
		}
		public function hasService(string $id): bool { return isset($this->services[$id]); }
		public function hasPackage(string $slug): bool { return isset($this->packages[$slug]); }
		public function __get(string $id): mixed {
			return $this->services[$id] ?? throw new \RuntimeException('Missing service: ' . $id);
		}
	}

	final class CountingProvider {
		public static int $staticCalls = 0;
		public static int $constructions = 0;
		public int $calls = 0;
		public function __construct(?object $app = null) { self::$constructions++; }
		public static function value(object $app): int { return ++self::$staticCalls; }
		public function next(): int { return ++$this->calls; }
		public function instance(): int { return self::$constructions; }
	}

	function makeRoot(string $suffix = ''): string {
		$root = \sys_get_temp_dir() . '/citomni-template-tests-' . ($suffix !== '' ? $suffix : \bin2hex(\random_bytes(8)));
		foreach (['templates', 'provider', 'var/cache'] as $dir) {
			if (!\is_dir($root . '/' . $dir)) { \mkdir($root . '/' . $dir, 0775, true); }
		}
		return $root;
	}

	function removeTree(string $path): void {
		if (\is_link($path) || !\is_dir($path)) { if (\file_exists($path) || \is_link($path)) { \unlink($path); } return; }
		foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) { removeTree($item->getPathname()); }
		\rmdir($path);
	}

	function writeTemplate(string $root, string $name, string $content, string $layer = 'app'): string {
		$path = $root . ($layer === 'app' ? '/templates/' : '/provider/') . $name;
		if (!\is_dir(\dirname($path))) { \mkdir(\dirname($path), 0775, true); }
		\file_put_contents($path, $content);
		\clearstatcache(true, $path);
		return $path;
	}

	function callPrivate(object $object, string $method, mixed ...$arguments): mixed {
		return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
	}

	function loadBaseline(string $path, string $root): string {
		$source = \file_get_contents($path);
		if ($source === false || !\str_contains($source, 'final class TemplateEngine')) {
			throw new \RuntimeException('Invalid baseline file.');
		}
		$source = \str_replace('final class TemplateEngine', 'final class BaselineTemplateEngine', $source);
		$target = $root . '/BaselineTemplateEngine.php';
		\file_put_contents($target, $source);
		require $target;
		return 'CitOmni\\Http\\Service\\BaselineTemplateEngine';
	}
}
