<?php declare(strict_types=1);

// Collects code coverage in the bin/lupa processes the tests start. Tester measures only the test process,
// while the tools run in workers; loading this file through auto_prepend_file adds them to the same report.

$file = getenv('NETTE_TESTER_COVERAGE');
$engine = getenv('NETTE_TESTER_COVERAGE_ENGINE');
if ($file && $engine && basename($_SERVER['argv'][0] ?? '') === 'lupa') {
	require_once __DIR__ . '/../vendor/nette/tester/src/CodeCoverage/Collector.php';
	Tester\CodeCoverage\Collector::start($file, $engine);
}
