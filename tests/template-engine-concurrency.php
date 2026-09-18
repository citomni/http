<?php
declare(strict_types=1);

/**
 * Exercise concurrent compilation with independent PHP worker processes.
 *
 * Typical usage:
 *   php tests/template-engine-concurrency.php
 */
require __DIR__ . '/bootstrap.php';
use CitOmni\Http\Tests\App;
use function CitOmni\Http\Tests\makeRoot;
use function CitOmni\Http\Tests\removeTree;
use function CitOmni\Http\Tests\writeTemplate;

if (($argv[1] ?? '') === '--worker') {
	[,,$root,$engineFile,$gate,$id,$cache] = $argv;
	define('CITOMNI_APP_PATH', $root);
	require $engineFile;
	$app = new App(['app'=>$root.'/templates','test/provider'=>$root.'/provider'], ['cache_enabled'=>$cache==='1']);
	$engine = new CitOmni\Http\Service\TemplateEngine($app);
	$deadline=microtime(true)+20;
	while (!is_file($gate)) {
		if (microtime(true)>$deadline) { fwrite(STDERR,"Gate timeout\n"); exit(2); }
		usleep(1000);
	}
	for ($i=0;$i<12;$i++) {
		$expected='<html><main>partial $1\\\\ [' . $id . '-' . $i . ']</main></html>';
		$actual=$engine->renderToString('parallel.html@app',['value'=>$id.'-'.$i]);
		if ($actual!==$expected) { fwrite(STDERR,'Output mismatch: '.var_export($actual,true)."\n"); exit(1); }
	}
	echo "OK\n";
	exit(0);
}

if (!function_exists('proc_open')) { fwrite(STDERR,"proc_open is required for this optional concurrency test.\n"); exit(2); }
$engineFile=realpath($argv[1] ?? __DIR__.'/../src/Service/TemplateEngine.php');
if ($engineFile===false) { fwrite(STDERR,"Engine not found.\n"); exit(2); }
$root=makeRoot();
try {
	writeTemplate($root,'parallel.html','{% extends "parallel-layout.html@test/provider" %}{% block body %}{% include "parallel-partial.html@test/provider" %} [{{ $value }}]{% endblock %}');
	writeTemplate($root,'parallel-layout.html','<html><main>{% yield body %}</main></html>','provider');
	writeTemplate($root,'parallel-partial.html','partial $1\\\\','provider');
	foreach ([['cold',16,true],['warm',32,true],['cache-disabled',8,false]] as [$label,$count,$cache]) {
		$workers=[]; $gate=$root.'/gate-'.$label;
		for ($id=0;$id<$count;$id++) {
			$command=[PHP_BINARY,'-d','opcache.enable_cli=1','-d','opcache.validate_timestamps=0','-d','opcache.file_update_protection=0',__FILE__,'--worker',$root,$engineFile,$gate,(string)$id,$cache?'1':'0'];
			$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
			if (!is_resource($process)) { throw new RuntimeException('Cannot start worker.'); }
			fclose($pipes[0]); $workers[]=[$process,$pipes];
		}
		file_put_contents($gate,'go');
		foreach ($workers as [$process,$pipes]) {
			$out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
			if ($exit!==0 || trim($out)!=='OK' || $error!=='') { throw new RuntimeException("Worker failed ($exit): $out $error"); }
		}
		echo "PASS $label: $count workers x 12 renders\n";
	}
	$php=glob($root.'/var/cache/tpl_v2_*.php');
	$meta=glob($root.'/var/cache/tpl_v2_*.meta.json');
	$locks=glob($root.'/var/cache/tpl_v2_*.lock');
	$tmp=glob($root.'/var/cache/*.tmp');
	if (count($php)!==1 || count($meta)!==1 || count($locks)!==1 || $tmp!==[]) { throw new RuntimeException('Unexpected cache artifacts after concurrent writes.'); }
	$manifest=json_decode(file_get_contents($meta[0]),true,512,JSON_THROW_ON_ERROR);
	if (!str_contains(basename($php[0]),$manifest['generation']) || count($manifest['dependencies'])!==3) { throw new RuntimeException('Manifest does not match its generation/dependencies.'); }
	echo 'PASS One complete generation, one valid manifest, one stable lock, no temporary files' . PHP_EOL;
	echo 'SUMMARY PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . ": 672 renders; 0 failures\n";
} finally { removeTree($root); }
