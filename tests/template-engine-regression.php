<?php
declare(strict_types=1);

/**
 * Standalone regression suite using the real supplied engine and filesystem fixtures.
 *
 * Typical usage:
 *   php tests/template-engine-regression.php
 *   php tests/template-engine-regression.php /path/to/TemplateEngine.php --baseline=/path/to/old.php
 */
require __DIR__ . '/bootstrap.php';

use CitOmni\Http\Tests\App;
use CitOmni\Http\Tests\CountingProvider;
use function CitOmni\Http\Tests\makeRoot;
use function CitOmni\Http\Tests\removeTree;
use function CitOmni\Http\Tests\writeTemplate;
use function CitOmni\Http\Tests\callPrivate;
use function CitOmni\Http\Tests\loadBaseline;

$engineFile = $argv[1] ?? __DIR__ . '/../src/Service/TemplateEngine.php';
if (str_starts_with($engineFile, '--')) { $engineFile = __DIR__ . '/../src/Service/TemplateEngine.php'; }
$root = makeRoot();
define('CITOMNI_APP_PATH', $root);
define('CITOMNI_PUBLIC_ROOT_URL', 'https://public.example.test');
define('CITOMNI_ENVIRONMENT', 'dev');
require $engineFile;
$class = CitOmni\Http\Service\TemplateEngine::class;
$baselinePath = __DIR__ . '/fixtures/TemplateEngine.literal-fixed.php.txt';
foreach ($argv as $argument) {
	if (str_starts_with($argument, '--baseline=')) { $baselinePath = substr($argument, 11); }
}
$baseline = is_file($baselinePath) ? loadBaseline($baselinePath, $root) : null;
$layers = ['app' => $root . '/templates', 'test/provider' => $root . '/provider'];
$passed = 0;
$failed = 0;
$skipped = 0;
$sequence = 0;

function same(mixed $expected, mixed $actual): void {
	if ($expected !== $actual) {
		throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
	}
}
function raises(callable $call, string $contains, string $class = RuntimeException::class): void {
	try { $call(); } catch (Throwable $error) {
		if (!$error instanceof $class || !str_contains($error->getMessage(), $contains)) { throw $error; }
		return;
	}
	throw new RuntimeException('Expected exception containing ' . $contains);
}
function test(string $name, callable $call): void {
	global $passed, $failed;
	try { $call(); $passed++; echo 'PASS ' . $name . PHP_EOL; }
	catch (Throwable $error) { $failed++; echo 'FAIL ' . $name . ': ' . $error::class . ': ' . $error->getMessage() . PHP_EOL; }
}
function engine(array $view = [], array $options = [], ?App $app = null): object {
	global $class, $layers;
	return new $class($app ?? new App($layers, $view), $options);
}
function renderSource(string $source, array $data = [], array $view = []): string {
	global $root, $sequence;
	$name = 'case-' . ++$sequence . '.html';
	writeTemplate($root, $name, $source);
	return engine($view)->renderToString($name . '@app', $data);
}
function differential(string $source, array $data = [], array $view = []): void {
	global $root, $sequence, $baseline, $layers;
	$name = 'diff-' . ++$sequence . '.html';
	writeTemplate($root, $name, $source);
	$new = engine($view)->renderToString($name . '@app', $data);
	$old = (new $baseline(new App($layers, $view)))->renderToString($name . '@app', $data);
	same($old, $new);
}

try {
	echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . ' / OPcache CLI ' . ini_get('opcache.enable_cli') . PHP_EOL;
	test('Public render method signatures unchanged', function () use ($class) {
		foreach (['render' => 'void', 'renderToString' => 'string'] as $method => $type) {
			$reflection = new ReflectionMethod($class, $method);
			same(true, $reflection->isPublic()); same($type, (string)$reflection->getReturnType());
			$parameters = $reflection->getParameters();
			same(['ref', 'data'], array_map(fn($p) => $p->getName(), $parameters));
			same(['string', 'array'], array_map(fn($p) => (string)$p->getType(), $parameters));
			same([], $parameters[1]->getDefaultValue());
		}
	});

	$syntaxCases = [
		'Plain UTF-8 and trailing whitespace' => ['<p>æøå €</p> \n', [], '<p>æøå €</p> \n'],
		'Escaped echo' => ['{{ $value }}', ['value' => '<a "x">&'], '&lt;a &quot;x&quot;&gt;&amp;'],
		'Raw echo' => ['{{{ $value }}}', ['value' => '<b>raw</b>'], '<b>raw</b>'],
		'Missing echo fallback' => ['{{ $missing }}', [], ''],
		'Assignment' => ['{% set $a = 7 %}{{ $a }}', [], '7'],
		'If branch' => ['{% if $a === 1 %}one{% elseif $a === 2 %}two{% else %}other{% endif %}', ['a' => 1], 'one'],
		'Elseif branch' => ['{% if $a === 1 %}one{% elseif $a === 2 %}two{% else %}other{% endif %}', ['a' => 2], 'two'],
		'Else branch' => ['{% if $a === 1 %}one{% elseif $a === 2 %}two{% else %}other{% endif %}', ['a' => 3], 'other'],
		'Foreach with continue and break' => ['{% foreach ($rows as $i) %}{% if $i === 1 %}{% continue %}{% endif %}{% if $i === 4 %}{% break; %}{% endif %}{{ $i }}{% endforeach %}', ['rows' => [1,2,3,4,5]], '23'],
		'Nested continue level' => ['{% foreach ([1,2] as $i) %}{% foreach ([1,2] as $j) %}{{ $i }}{% continue 2; %}{% endforeach %}{% endforeach %}', [], '12'],
		'Nested break level' => ['{% foreach ([1,2] as $i) %}{% foreach ([1,2] as $j) %}{{ $i }}{% break 2 %}{% endforeach %}{% endforeach %}', [], '1'],
		'Inline PHP echo' => ['{?= "literal" ?}', [], 'literal'],
		'Inline PHP block' => ['{? $v = 5; echo $v; ?}', [], '5'],
		'Native PHP remains supported' => ['<?php echo "native"; ?>', [], 'native'],
		'Native short echo' => ['<?= "native" ?>', [], 'native'],
		'Unknown directive remains literal' => ['{% unknown example %}', [], '{% unknown example %}'],
		'Standalone block wrappers remain optional' => ['{% block plain %}body{% endblock %}', [], 'body'],
		'Standalone unresolved yield stripped as before' => ['a{% yield missing %}b', [], 'ab'],
		'Unclosed template comment discards tail' => ['a{# incomplete', [], 'a'],
		'Nested template comments' => ['a{# outer {# nested #} tail #}b', [], 'ab'],
		'Stray comment close stays literal' => ['a#}b{# c #}d', [], 'a#}bd'],
		'Controller override of charset' => ['{{ $value }}', ['value' => 'æøå', 'charset' => 'UTF-8'], 'æøå'],
	];
	foreach ($syntaxCases as $name => [$source, $data, $expected]) {
		test($name, fn() => same($expected, renderSource($source, $data)));
		if ($baseline !== null) { test('Differential ' . $name, fn() => differential($source, $data)); }
	}
	test('Inline PHP disabled does not remove ordinary echoes', fn() => same('visible', renderSource('{? echo "hidden"; ?}{?= "hidden" ?}{{ "visible" }}', [], ['allow_php_tags' => false])));
	test('Inline PHP flag is not a sandbox for native PHP', fn() => same('native', renderSource('<?php echo "native"; ?>', [], ['allow_php_tags' => false])));

	writeTemplate($root, 'layout.html', 'BEGIN{% yield content %}END', 'provider');
	$literalCases = [
		'JavaScript path normalization' => <<<'TPL'
path = path.replace(/\\/g, '/').replace(/\/+$/, '');
TPL,
		'Dollar captures' => '$0 $1 $2 $99 ${1}',
		'Backslash captures' => '\\0 \\1 \\2 \\99',
		'Windows and UNC paths' => <<<'TPL'
const p = 'C:\\dev\\www'; const unc = '\\server\share'; C:\1\file.txt
TPL,
		'Empty block' => '',
		'Whitespace block' => " \t\r\n ",
		'Binary and Unicode block' => "æøå\0$1\r\n",
	];
	foreach ($literalCases as $name => $content) {
		test('Literal ' . $name, fn() => same('BEGIN' . $content . 'END', renderSource('{% extends "layout.html@test/provider" %}{% block content %}' . $content . '{% endblock %}')));
	}
	test('Repeated and compact yields', function () use ($root) {
		writeTemplate($root, 'repeat.html', 'A{%yieldcontent%}B{% yield content %}C{% yield  content%}D');
		same('A$1B$1C$1D', renderSource('{% extends "repeat.html@app" %}{% block content %}$1{% endblock %}'));
	});
	test('Hyphenated and numeric block names', function () use ($root) {
		writeTemplate($root, 'block-names.html', '{% yield page-scripts %}{% yield 123 %}');
		same('AB', renderSource('{% extends "block-names.html@app" %}{% block page-scripts %}A{% endblock %}{% block 123 %}B{% endblock %}'));
	});
	test('Ordered yield cascade remains compatible', function () use ($root) {
		writeTemplate($root, 'cascade.html', '[{% yield first %}]');
		same('[AB$1]', renderSource('{% extends "cascade.html@app" %}{% block first %}A{% yield second %}{% endblock %}{% block second %}B$1{% endblock %}'));
	});
	test('Multi-level cross-layer inheritance', function () use ($root) {
		writeTemplate($root, 'base.html', '<html>{% yield body %}</html>', 'provider');
		writeTemplate($root, 'middle.html', '{% extends "base.html@test/provider" %}{% block body %}<main>{% yield inner %}</main>{% endblock %}');
		same('<html><main>$1\\\\</main></html>', renderSource('{% extends "middle.html@app" %}{% block inner %}$1\\\\{% endblock %}'));
	});
	test('Duplicate block rejected', fn() => raises(fn() => renderSource('{% extends "layout.html@test/provider" %}{% block content %}A{% endblock %}{% block content %}B{% endblock %}'), 'Duplicate block'));
	test('Orphan block rejected', fn() => raises(fn() => renderSource('{% extends "layout.html@test/provider" %}{% block orphan %}A{% endblock %}'), 'not yielded'));
	test('Missing block rejected', fn() => raises(fn() => renderSource('{% extends "layout.html@test/provider" %}'), 'Missing child blocks'));
	test('Circular inheritance rejected', function () use ($root) {
		writeTemplate($root, 'cycle-a.html', '{% extends "cycle-b.html@app" %}');
		writeTemplate($root, 'cycle-b.html', '{% extends "cycle-a.html@app" %}');
		raises(fn() => engine()->renderToString('cycle-a.html@app'), 'Circular inheritance');
	});
	test('Inheritance limit enforced', function () use ($root) {
		for ($i=0; $i<66; $i++) { writeTemplate($root, "depth-$i.html", $i === 65 ? 'end' : '{% extends "depth-' . ($i+1) . '.html@app" %}'); }
		raises(fn() => engine()->renderToString('depth-0.html@app'), 'Inheritance depth exceeded');
	});
	test('Include source inserted literally with shared scope', function () use ($root) {
		writeTemplate($root, 'partial.html', '$1\\\\ {{ $v }}', 'provider');
		same('[$1\\\\ 7]', renderSource('[{% include "partial.html@test/provider" %}]', ['v' => 7]));
	});
	test('Nested and repeated includes', function () use ($root) {
		writeTemplate($root, 'leaf.html', 'L');
		writeTemplate($root, 'branch.html', '{% include "leaf.html@app" %}{% include "leaf.html@app" %}');
		same('LLLL', renderSource('{% include "branch.html@app" %}{% include "branch.html@app" %}'));
	});
	test('Commented missing references never loaded', fn() => same('ok', renderSource('{# {% extends "missing@app" %} {# {% include "missing@app" %} #} #}ok')));
	test('Unused child source is not a phantom dependency', function () use ($root) {
		writeTemplate($root, 'unused-child.html', '{% extends "layout.html@test/provider" %}{% include "missing@app" %}{% block content %}ok{% endblock %}');
		$e = engine(); same('BEGINokEND', $e->renderToString('unused-child.html@app')); same('BEGINokEND', $e->renderToString('unused-child.html@app'));
	});
	test('Whitespace variants of extends and include', function () use ($root) {
		writeTemplate($root, 'white-leaf.html', 'L');
		same('BEGINLEND', renderSource("{%\textends \"layout.html@test/provider\" %}{% block content %}{%\nINCLUDE \"white-leaf.html@app\" %}{% endblock %}"));
	});
	test('Circular include rejected', function () use ($root) {
		writeTemplate($root, 'include-a.html', '{% include "include-b.html@app" %}');
		writeTemplate($root, 'include-b.html', '{% include "include-a.html@app" %}');
		raises(fn() => engine()->renderToString('include-a.html@app'), 'Circular include');
	});
	test('Include depth boundary', function () use ($root) {
		for ($i=0; $i<18; $i++) { writeTemplate($root, "include-depth-$i.html", $i === 17 ? 'leaf' : '{% include "include-depth-' . ($i+1) . '.html@app" %}'); }
		raises(fn() => engine()->renderToString('include-depth-0.html@app'), 'Include depth exceeded');
		same('leaf', engine()->renderToString('include-depth-1.html@app'));
	});

	$badRefs = [
		'Missing layer separator' => ['file.html', 'must contain', InvalidArgumentException::class],
		'Empty path' => ['@app', 'Invalid template ref', InvalidArgumentException::class],
		'Empty layer' => ['file@', 'Invalid template ref', InvalidArgumentException::class],
		'Unknown layer' => ['file@unknown', 'Unknown layer', RuntimeException::class],
		'Missing source' => ['absent@app', 'not found', RuntimeException::class],
		'NUL in reference' => ["bad\0name@app", 'Invalid template ref', InvalidArgumentException::class],
	];
	foreach ($badRefs as $name => [$ref, $message, $exception]) { test($name, fn() => raises(fn() => engine()->renderToString($ref), $message, $exception)); }
	test('Directory traversal rejected', function () use ($root) {
		file_put_contents($root . '/outside.html', 'private');
		raises(fn() => engine()->renderToString('../outside.html@app'), 'Illegal path escape');
	});
	test('Non-regular template rejected', function () use ($root) { mkdir($root . '/templates/directory'); raises(fn() => engine()->renderToString('directory@app'), 'not a regular file'); });
	test('Last at-sign and leading slash normalization', function () use ($root) { writeTemplate($root, 'a@b.html', 'ok'); same('ok', engine()->renderToString('/a@b.html@app')); });

	// Stat-preserving edits of equal length are intentionally not claimed to be detectable.
	test('Warm cache checks backwards mtime changes', function () use ($root) {
		$path = writeTemplate($root, 'fresh.html', 'before'); touch($path, time()-60);
		$e = engine(); same('before', $e->renderToString('fresh.html@app'));
		file_put_contents($path, 'after!'); touch($path, time()-120);
		same('after!', $e->renderToString('fresh.html@app'));
		same('after!', engine()->renderToString('fresh.html@app'));
	});
	test('Warm cache detects size change with same mtime', function () use ($root) {
		$path = writeTemplate($root, 'size.html', 'one'); $mtime = filemtime($path);
		$e = engine(); same('one', $e->renderToString('size.html@app'));
		file_put_contents($path, 'two longer'); touch($path, $mtime);
		same('two longer', $e->renderToString('size.html@app'));
	});
	test('Nested dependency changes invalidate root', function () use ($root) {
		$path = writeTemplate($root, 'nested-change.html', 'one');
		writeTemplate($root, 'nested-parent.html', '{% include "nested-change.html@app" %}');
		$e = engine(); same('one', $e->renderToString('nested-parent.html@app'));
		file_put_contents($path, 'two updated'); same('two updated', $e->renderToString('nested-parent.html@app'));
	});
	test('Parent layout changes invalidate child', function () use ($root) {
		$path = writeTemplate($root, 'mutable-layout.html', 'A{% yield body %}');
		writeTemplate($root, 'mutable-child.html', '{% extends "mutable-layout.html@app" %}{% block body %}B{% endblock %}');
		$e = engine(); same('AB', $e->renderToString('mutable-child.html@app'));
		file_put_contents($path, 'NEW{% yield body %}'); same('NEWB', $e->renderToString('mutable-child.html@app'));
	});
	test('Deleted source never silently serves cache', function () use ($root) {
		$path = writeTemplate($root, 'deleted.html', 'one'); $e = engine(); $e->renderToString('deleted.html@app'); unlink($path);
		raises(fn() => $e->renderToString('deleted.html@app'), 'not found');
	});
	test('Changed compile options get distinct cache identities', function () use ($root) {
		writeTemplate($root, 'options.html', '{? echo "php"; ?}<!-- hide -->A  B');
		same('php<!-- hide -->A  B', engine()->renderToString('options.html@app'));
		same('A B', engine(['allow_php_tags' => false, 'remove_html_comments' => true, 'trim_whitespace' => true])->renderToString('options.html@app'));
		same('php<!-- hide -->A  B', engine()->renderToString('options.html@app'));
	});
	test('Service options override cfg options', fn() => same('', (function () use ($root) { writeTemplate($root, 'override.html', '{? echo "php"; ?}'); return engine(['allow_php_tags' => true], ['allow_php_tags' => false])->renderToString('override.html@app'); })()));
	test('Layer directory changes get distinct identities', function () use ($root, $class) {
		writeTemplate($root, 'same.html', 'APP'); writeTemplate($root, 'same.html', 'PROVIDER', 'provider');
		same('APP', (new $class(new App(['app' => $root . '/templates'])))->renderToString('same.html@app'));
		same('PROVIDER', (new $class(new App(['app' => $root . '/provider'])))->renderToString('same.html@app'));
	});
	test('Corrupt manifest rebuilt without evaluating its content', function () use ($root) {
		writeTemplate($root, 'manifest.html', 'ok'); $e = engine(); $e->renderToString('manifest.html@app');
		$base = callPrivate($e, 'cacheFileName', 'manifest.html', 'app');
		file_put_contents($base . '.meta.json', '<?php throw new Exception("executed");');
		same('ok', engine()->renderToString('manifest.html@app'));
		same(true, is_array(json_decode(file_get_contents($base . '.meta.json'), true)));
	});
	test('Manifest traversal generation rejected', function () use ($root) {
		writeTemplate($root, 'manifest-path.html', 'ok'); $e = engine(); $e->renderToString('manifest-path.html@app');
		$base = callPrivate($e, 'cacheFileName', 'manifest-path.html', 'app');
		$manifest = json_decode(file_get_contents($base . '.meta.json'), true); $manifest['generation'] = '../../outside';
		file_put_contents($base . '.meta.json', json_encode($manifest));
		same('ok', engine()->renderToString('manifest-path.html@app'));
	});
	test('Missing compiled generation rebuilt', function () use ($root) {
		writeTemplate($root, 'artifact.html', 'ok'); $e = engine(); $file = callPrivate($e, 'compile', 'artifact.html@app'); unlink($file);
		same('ok', $e->renderToString('artifact.html@app'));
	});
	test('Warm renders do not rewrite artifacts', function () use ($root) {
		writeTemplate($root, 'stable.html', 'ok'); $e = engine(); $file = callPrivate($e, 'compile', 'stable.html@app');
		$base = callPrivate($e, 'cacheFileName', 'stable.html', 'app'); $manifest = $base . '.meta.json';
		touch($file, 1000000000); touch($manifest, 1000000000); clearstatcache();
		same('ok', $e->renderToString('stable.html@app')); same('ok', engine()->renderToString('stable.html@app'));
		clearstatcache(); same(1000000000, filemtime($file)); same(1000000000, filemtime($manifest));
	});
	test('Content-addressed generations change on output changes', function () use ($root) {
		$path = writeTemplate($root, 'generations.html', 'before'); $e = engine(); $first = callPrivate($e, 'compile', 'generations.html@app');
		file_put_contents($path, 'after longer'); $second = callPrivate($e, 'compile', 'generations.html@app');
		same(false, $first === $second); same(true, is_file($first)); same(true, is_file($second));
		same('after longer', $e->renderToString('generations.html@app'));
	});
	test('Disabled cache recompiles equal-metadata source edits', function () use ($root) {
		$path = writeTemplate($root, 'disabled.html', 'A'); $mtime = filemtime($path); $e = engine(['cache_enabled' => false]);
		same('A', $e->renderToString('disabled.html@app')); file_put_contents($path, 'B'); touch($path, $mtime);
		same('B', $e->renderToString('disabled.html@app'));
	});
	test('Cache directory created lazily', function () use ($root) {
		$e = engine(); $reflection = new ReflectionProperty($e, 'cacheDir'); $reflection->setValue($e, $root . '/new/cache');
		writeTemplate($root, 'new-cache.html', 'ok'); same('ok', $e->renderToString('new-cache.html@app'));
		same(true, is_dir($root . '/new/cache'));
	});

	$symlink = $root . '/templates/link.html';
	if (function_exists('symlink') && @symlink($root . '/outside.html', $symlink)) {
		test('Symlink escape rejected', fn() => raises(fn() => engine()->renderToString('link.html@app'), 'Illegal path escape'));
		test('Warm symlink retarget detected', function () use ($root, $symlink) {
			unlink($symlink); writeTemplate($root, 'link-a.html', 'A'); writeTemplate($root, 'link-b.html', 'B');
			symlink($root . '/templates/link-a.html', $symlink); $e = engine(); same('A', $e->renderToString('link.html@app'));
			unlink($symlink); symlink($root . '/templates/link-b.html', $symlink); same('B', $e->renderToString('link.html@app'));
			unlink($symlink); symlink($root . '/outside.html', $symlink);
			raises(fn() => $e->renderToString('link.html@app'), 'Illegal path escape');
		});
	} else { $skipped += 2; echo "SKIP Symlink tests unavailable on this host\n"; }

	// Optional transformations must not edit embedded programming languages or attributes.
	$opt = ['trim_whitespace' => true, 'remove_html_comments' => true];
	test('Whitespace in PHP strings survives', fn() => same('A  B', renderSource('{{ "A  B" }}', [], $opt)));
	test('Whitespace in native PHP line comments survives', fn() => same('OK', renderSource("<?php // keep newline\necho 'OK'; ?>", [], $opt)));
	test('Heredoc and nowdoc whitespace survives', function () use ($opt) {
		$source = <<<'TPL'
<?php $text = <<<'TEXT'
A  B
  C
TEXT;
echo $text; ?>
TPL;
		same("A  B\n  C", renderSource($source, [], $opt));
	});
	test('HTML comments inside PHP strings survive', fn() => same('<!-- keep -->', renderSource('{? echo "<!-- keep -->"; ?}', [], $opt)));
	test('Quoted attributes preserve meaningful spaces and greater-than signs', fn() => same('<div title="A  >  B" data-v="x  y">a b</div>', renderSource('<div title="A  >  B" data-v="x  y">a  b</div>', [], $opt)));
	foreach (['pre', 'code', 'textarea', 'script', 'style'] as $tag) {
		test('Sensitive element preserved ' . $tag, fn() => same("<$tag>A  B\n<!-- keep -->\n</$tag>", renderSource("<$tag>A  B\n<!-- keep -->\n</$tag>", [], $opt)));
	}
	test('Sensitive tag spanning PHP survives', fn() => same('<script>A  X  B</script>', renderSource('<script>A  {{ "X" }}  B</script>', [], $opt)));
	test('Unclosed sensitive tag is preserved to EOF', fn() => same('<textarea>A  B', renderSource('<textarea>A  B', [], $opt)));
	test('Ordinary comment removed and conditional preserved', fn() => same('A<!--[if IE]>keep<![endif]-->B', renderSource('A<!-- ordinary --><!--[if IE]>keep<![endif]-->B', [], $opt)));
	test('Unclosed ordinary comment is preserved conservatively', fn() => same('A<!-- open  text', renderSource('A<!-- open  text', [], $opt)));

	test('Data precedence and dynamic providers rerun for every render', function () use ($root, $layers) {
		CountingProvider::$staticCalls = 0;
		$app = new App($layers, ['vars' => [
			'app_name' => ['type' => 'static', 'source' => 'Scoped app'],
			'counter' => ['type' => 'dynamic', 'source' => CountingProvider::class . '::value'],
		]]);
		writeTemplate($root, 'vars.html', '{{ $app_name }}:{{ $counter }}'); $e = engine(app: $app);
		same('Scoped app:1', $e->renderToString('vars.html@app'));
		same('Controller:2', $e->renderToString('vars.html@app', ['app_name' => 'Controller']));
		same('Scoped app:99', $e->renderToString('vars.html@app', ['counter' => 99]));
		same(3, CountingProvider::$staticCalls);
	});
	test('Instance providers are not silently memoized', function () use ($root, $layers) {
		CountingProvider::$constructions = 0; $app = new App($layers, ['vars' => ['v' => ['type' => 'dynamic', 'source' => ['class' => CountingProvider::class, 'method' => 'instance']]]]);
		writeTemplate($root, 'instance.html', '{{ $v }}'); $e = engine(app: $app);
		same('1', $e->renderToString('instance.html@app')); same('2', $e->renderToString('instance.html@app'));
	});
	test('Service providers use the current registered instance', function () use ($root, $layers) {
		$app = new App($layers, ['vars' => ['v' => ['type' => 'dynamic', 'source' => ['service' => 'counter', 'method' => 'next']]]]); $app->services['counter'] = new CountingProvider();
		writeTemplate($root, 'service.html', '{{ $v }}'); $e = engine(app: $app);
		same('1', $e->renderToString('service.html@app')); same('2', $e->renderToString('service.html@app'));
	});
	test('Path-scoped include and exclude rules stay dynamic', function () use ($root, $layers) {
		$app = new App($layers, ['vars' => ['v' => ['type' => 'static', 'source' => 'YES', 'include' => ['/admin/*'], 'exclude' => ['/admin/private']]]]);
		writeTemplate($root, 'scoped.html', '{{ $v ?? "NO" }}'); $e = engine(app: $app);
		same('YES', $e->renderToString('scoped.html@app')); $app->request->path='/admin/private'; same('NO', $e->renderToString('scoped.html@app'));
		$app->request->path='/public'; same('NO', $e->renderToString('scoped.html@app'));
	});
	test('Existing helper names and URL output preserved', function () use ($layers) {
		$e = engine(app: new App($layers)); $globals = callPrivate($e, 'buildGlobals');
		$keys = ['app_name','base_url','public_root_url','language','charset','marketing_scripts','csrf_protection','honeypot_protection','form_action_switching','captcha_protection','env','txt','dt','dtNow','dtMonth','dtWeekday','url','asset','hasService','hasPackage','csrfField','currentPath','icon','hasIcon','auth','role'];
		same($keys, array_keys($globals)); same('https://example.test/base/search?q=a+b', $globals['url']('/search', ['q'=>'a b']));
		same('https://example.test/base/assets/a.css?v=v1', $globals['asset']('/assets/a.css'));
		same('https://example.test/base/a.css?a=1&v=v2', $globals['asset']('a.css?a=1','v2'));
		same('https://cdn.test/a.css', $globals['asset']('https://cdn.test/a.css'));
		same('', $globals['csrfField']()); same(false, $globals['hasIcon']('missing'));
		raises(fn()=> $globals['icon']('missing'), 'Icon service not available');
		raises(fn()=> $globals['txt']('key','file'), 'Text service not available');
		raises(fn()=> $globals['auth']('check'), 'Auth service not available');
		raises(fn()=> $globals['role']('rank'), 'Role service not available');
	});
	test('Legacy local variable scope retained', fn() => same('yes', renderSource('{{ isset($this, $ref, $data, $vars, $file) ? "yes" : "no" }}')));
	test('render() output equals renderToString()', function () use ($root) {
		writeTemplate($root, 'output.html', '<p>{{ $value }}</p>'); $e = engine(); ob_start(); $e->render('output.html@app', ['value'=>'x']); $output = ob_get_clean();
		same($e->renderToString('output.html@app', ['value'=>'x']), $output);
	});
	test('String rendering cleans output buffer on template exception', function () use ($root) {
		writeTemplate($root, 'exception.html', '<?php echo "partial"; throw new RuntimeException("test error"); ?>'); $level=ob_get_level();
		raises(fn()=>engine()->renderToString('exception.html@app'), 'test error'); same($level, ob_get_level());
	});
	test('Debug comment cannot be closed by variable payload', function () use ($root, $layers) {
		$app = new App($layers); $app->request->query['_viewvars']='1'; writeTemplate($root, 'debug.html', 'body'); $e = engine(app:$app);
		ob_start(); $e->render('debug.html@app',['payload'=>'--><script>alert(1)</script><!-- --!><script>alert(2)</script>']); $out=ob_get_clean();
		same(1, substr_count($out, '<!--')); same(1, substr_count($out, '-->')); same(false, str_contains($out, '--!>')); same(false, str_contains($out, '<script>'));  same(true, str_ends_with($out, 'body'));
		same('body', $e->renderToString('debug.html@app'));
	});

	test('Debug normalization handles repeated and cyclic objects', function () use ($root, $layers) {
		$app = new App($layers); $app->request->query['_viewvars']='1'; writeTemplate($root, 'debug-object.html', 'body');
		$object = new stdClass(); $object->self=$object; $e=engine(app:$app);
		ob_start(); $e->render('debug-object.html@app',['a'=>$object,'b'=>$object]); $out=ob_get_clean();
		same(true, str_contains($out,'(seen)')); same(true, str_ends_with($out,'body'));
	});
	test('Filesystem root is a valid configured layer root', function () use ($root, $class) {
		$path = writeTemplate($root, 'filesystem-root.html', 'root-ok');
		$absolute = realpath($path);
		if (DIRECTORY_SEPARATOR === '\\') {
			$volume = substr($absolute, 0, 3); $ref = substr($absolute, 3);
		} else { $volume = '/'; $ref = ltrim($absolute, '/'); }
		same('root-ok', (new $class(new App(['app'=>$volume])))->renderToString($ref . '@app'));
	});
	test('Failed publication preserves destination and removes temporary file', function () use ($root) {
		$destination = $root . '/publication-target'; mkdir($destination); file_put_contents($destination . '/previous', 'keep');
		raises(fn()=>callPrivate(engine(), 'writeCacheFile', $destination, 'new'), 'Cannot publish cache file');
		same('keep', file_get_contents($destination . '/previous'));
		same([], glob($destination . '.*.tmp'));
	});
	test('One snapshot per distinct logical dependency', function () use ($root) {
		writeTemplate($root, 'dedup-leaf.html', 'L');
		writeTemplate($root, 'dedup.html', str_repeat('{% include "dedup-leaf.html@app" %}', 8));
		$e = engine(); same('LLLLLLLL', $e->renderToString('dedup.html@app'));
		$base=callPrivate($e,'cacheFileName','dedup.html','app');
		$manifest=json_decode(file_get_contents($base . '.meta.json'), true);
		same(2, count($manifest['dependencies']));
	});
	test('Unchanged compiled bytes reuse the content-addressed generation', function () use ($root) {
		$path=writeTemplate($root,'same-bytes.html','{# first #}body'); $e=engine(); $first=callPrivate($e,'compile','same-bytes.html@app');
		file_put_contents($path,'{# a longer comment, with unchanged emitted output #}body');
		$second=callPrivate($e,'compile','same-bytes.html@app'); same($first,$second); same('body',$e->renderToString('same-bytes.html@app'));
	});

	if ($baseline !== null) {
		test('Differential nested comment fuzz, 2000 deterministic inputs', function () use ($baseline, $layers) {
			$a=engine(); $b=new $baseline(new App($layers)); mt_srand(845);
			for ($i=0; $i<2000; $i++) { $s=''; for ($j=0;$j<mt_rand(1,80);$j++) { $s .= ['{#','#}','x','{','}','#',"\n"][mt_rand(0,6)]; } same(callPrivate($b,'removeTemplateComments',$s), callPrivate($a,'removeTemplateComments',$s)); }
		});
		test('Differential syntax compilation, complete grammar sample', function () use ($baseline, $layers) {
			$source = <<<'TPL'
{?= '$1' ?}{? $a = '\\'; ?}{{{ '$2' }}}{{ $a ?? '$0' }}{% set $b = '\1' %}
{% if ($b) %}x{% elseif !$a %}y{% else %}z{% endif %}
{% foreach ([1,2] as $i) %}{% continue 2; %}{% break; %}{% endforeach %}
{% block x %}body{% endblock %}{% yield x %}{% extends "base@app" %}
TPL;
			foreach ([true,false] as $allow) { same(callPrivate(new $baseline(new App($layers,['allow_php_tags'=>$allow])),'compileSyntax',$source),callPrivate(engine(['allow_php_tags'=>$allow]),'compileSyntax',$source)); }
		});
	}

	echo "SUMMARY $passed passed; $failed failed; $skipped skipped\n";
} finally {
	removeTree($root);
}
exit($failed === 0 ? 0 : 1);
