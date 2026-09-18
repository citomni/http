<?php
declare(strict_types=1);

/**
 * Compare the supplied baseline and replacement using identical filesystem fixtures.
 *
 * Typical usage:
 *   php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 tests/template-engine-benchmark.php /path/to/old.php
 *
 * Notes:
 * - Results are local microbenchmarks, not HTTP throughput or production guarantees.
 * - Fresh-service cases include construction and globals/provider preparation, but not PHP startup.
 * - The baseline should be the supplied original or the earlier literal-replacement patch.
 */
require __DIR__ . '/bootstrap.php';

use CitOmni\Http\Tests\App;
use function CitOmni\Http\Tests\makeRoot;
use function CitOmni\Http\Tests\removeTree;
use function CitOmni\Http\Tests\writeTemplate;
use function CitOmni\Http\Tests\loadBaseline;

$baselineFile = $argv[1] ?? __DIR__ . '/fixtures/TemplateEngine.literal-fixed.php.txt';
if (!is_file($baselineFile)) {
	fwrite(STDERR, "Usage: php tests/template-engine-benchmark.php /path/to/original-TemplateEngine.php [replacement.php]\n");
	exit(2);
}
$root = makeRoot();
define('CITOMNI_APP_PATH', $root);
define('CITOMNI_PUBLIC_ROOT_URL', 'https://example.test');
require $argv[2] ?? __DIR__ . '/../src/Service/TemplateEngine.php';
$baseline = loadBaseline($baselineFile, $root);
$replacement = CitOmni\Http\Service\TemplateEngine::class;
$layers = ['app'=>$root . '/templates','test/provider'=>$root . '/provider'];

function timeCase(callable $run, int $iterations): array {
	$samples = [];
	for ($sample = 0; $sample < 7; $sample++) {
		$start = hrtime(true);
		for ($i=0; $i<$iterations; $i++) { $run(); }
		$samples[] = (hrtime(true) - $start) / $iterations / 1000;
	}
	sort($samples);
	return ['median_us'=>$samples[3], 'min_us'=>$samples[0], 'max_us'=>$samples[6], 'samples'=>$samples, 'iterations_per_sample'=>$iterations];
}

try {
	writeTemplate($root, 'small.html', '<p>{{ $value }}</p>');
	$includes=''; $sourceBytes=0;
	for ($i=0; $i<12; $i++) {
		$body=str_repeat("<li class=\"item\">Repeated static markup and UTF-8 æøå</li>\n", 100);
		$body="{# Fixture comment ignored by the engine. #}\n" . $body . '{{ $value }}';
		writeTemplate($root, "part-$i.html", $body, 'provider');
		$sourceBytes += strlen($body);
		$includes .= '{% include "part-' . $i . '.html@test/provider" %}';
	}
	writeTemplate($root, 'large.html', '{% extends "layout.html@test/provider" %}{% block body %}' . $includes . '{% endblock %}');
	writeTemplate($root, 'layout.html', '<html><main>{% yield body %}</main></html>', 'provider');

	$medium = '';
	$mediumSourceBytes = 0;
	for ($i=0; $i<4; $i++) {
		$body = str_repeat('<span>Text without template comments</span>', 25);
		$mediumSourceBytes += strlen($body);
		writeTemplate($root, "medium-$i.html", $body, 'provider');
		$medium .= '{% include "medium-' . $i . '.html@test/provider" %}';
	}
	writeTemplate($root, 'medium.html', '{% extends "layout.html@test/provider" %}{% block body %}' . $medium . '{{ $value }}{% endblock %}');
	$results=[];
	foreach (['small'=>'small.html@app','medium_no_comments'=>'medium.html@app','large'=>'large.html@app'] as $size=>$ref) {
		foreach (['warm_same_service','warm_fresh_service','cache_disabled'] as $mode) {
			$cache=$mode !== 'cache_disabled';
			$oldApp=new App($layers,['cache_enabled'=>$cache]); $newApp=new App($layers,['cache_enabled'=>$cache]);
			$old=new $baseline($oldApp); $new=new $replacement($newApp);
			$data=['value'=>'fixture'];
			$expected=$old->renderToString($ref,$data);
			if ($new->renderToString($ref,$data) !== $expected) { throw new RuntimeException('Output mismatch; benchmark invalid.'); }
			$runOld=$mode==='warm_fresh_service' ? static fn()=>(new $baseline($oldApp))->renderToString($ref,$data) : static fn()=>$old->renderToString($ref,$data);
			$runNew=$mode==='warm_fresh_service' ? static fn()=>(new $replacement($newApp))->renderToString($ref,$data) : static fn()=>$new->renderToString($ref,$data);
			$iterations=$mode==='cache_disabled' ? 30 : ($size==='small' ? 350 : 100);
			// Alternate which implementation runs first across scenarios to reduce fixed-order bias.
			if (count($results)%2===0) { $a=timeCase($runOld,$iterations); $b=timeCase($runNew,$iterations); }
			else { $b=timeCase($runNew,$iterations); $a=timeCase($runOld,$iterations); }
			$ratio=$a['median_us']/$b['median_us'];
			$results[$size . '_' . $mode]=['baseline'=>$a,'replacement'=>$b,'baseline_over_replacement'=>$ratio,'output_bytes'=>strlen($expected)];
		}
	}
	echo json_encode([
		'php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'sapi'=>PHP_SAPI,
		'opcache_enable_cli'=>ini_get('opcache.enable_cli'),'opcache_validate_timestamps'=>ini_get('opcache.validate_timestamps'),
		'opcache_file_update_protection'=>ini_get('opcache.file_update_protection'),
		'medium_dependency_files'=>6,'medium_partial_source_bytes'=>$mediumSourceBytes,
		'large_dependency_files'=>14,'large_partial_source_bytes'=>$sourceBytes,
		'baseline_sha256'=>hash_file('sha256',$baselineFile),
		'replacement_sha256'=>hash_file('sha256',$argv[2] ?? __DIR__ . '/../src/Service/TemplateEngine.php'),
		'results'=>$results,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally { removeTree($root); }
