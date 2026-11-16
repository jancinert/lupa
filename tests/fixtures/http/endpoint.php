<?php declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$root = (string) getenv('LUPA_HTTP_TEST_PROJECT');
$hash = (string) getenv('LUPA_HTTP_TEST_HASH');
if ($root === '' || $hash === '') {
	http_response_code(500);
	exit;
}

// The isolated container exercises HTTP framing; production requires HTTPS reported by the web server.
$_SERVER['HTTPS'] = 'on';
$project = new Lupa\Project($root, dirname(__DIR__, 3) . '/vendor/autoload.php', $root . '/lupa.neon');
(new Lupa\Http\Endpoint($project, $hash, ['https://example.test']))->serve();
