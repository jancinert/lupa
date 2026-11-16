<?php declare(strict_types=1);

use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking;
$encoders = [
	fn(string $value): string => base64_encode($value),
	fn(string $value): string => rawurlencode($value),
	fn(string $value): string => rawurlencode(base64_encode($value)),
	fn(string $value): string => base64_encode(rawurlencode($value)),
	fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
	fn(string $value): string => str_replace('"', '\\u0022', $value),
	fn(string $value): string => str_replace('"', '=22', $value),
];
foreach ([
	'["password","synthetic-secret"]',
	'{"name":"password","value":"synthetic-secret"}',
	'{"field":"apiKey","val":"synthetic-secret"}',
	'[{"name":"password","value":"synthetic-secret"},{"name":"username","value":"Public"}]',
] as $document) {
	foreach ($encoders as $encode) {
		$encoded = $encode($document);
		foreach ([$encoded, 'payload ' . $encoded, 'https://example.test/?q=' . $encoded] as $input) {
			$output = $masking->maskText($input);
			Assert::notSame($input, $output);
			Assert::notContains('synthetic-secret', $output);
			Assert::notContains($encoded, $output);
			Assert::same(['result' => $output], $masking->maskArray(['result' => $input]));
		}
	}
}

foreach ($encoders as $encode) {
	foreach (['["username","Public"]', '{"name":"username","value":"Public"}'] as $document) {
		$input = $encode($document);
		Assert::same($input, $masking->maskText($input));
	}
}
Assert::same('q=%5B%22password%22%2C%22%2A%2A%2A%22%5D', $masking->maskText('q=%5B%22password%22%2C%22synthetic-secret%22%5D'));
Assert::same('q=%5B%22username%22%2C%22Public%22%5D', $masking->maskText('q=%5B%22username%22%2C%22Public%22%5D'));

if (!class_exists(DOMDocument::class)) {
	Assert::same('***', $masking->maskHtml('<label>Password<input title="synthetic-secret"></label>'));
	return;
}

foreach ([
	'<label for="f1">Password</label><input id="f1" name="f1" value="%s" title="%s" data-copy="%s" aria-label="%s">',
	'<label>Password<input title="%s" data-copy="%s"></label>',
	'<table><tr><th>Password</th><td title="%s" data-copy="%s">%s</td></tr></table>',
	'<table><tr><th>Password</th><th>User</th></tr><tr><td title="%s">%s</td><td>Public</td></tr></table>',
	'<dl><dt>Token</dt><dd title="%s" data-copy="%s">%s</dd></dl>',
	'<p><strong>Token</strong><a href="%s" title="%s">%s</a></p>',
] as $template) {
	$input = str_replace('%s', 'synthetic-secret', $template);
	$expected = str_replace('%s', '***', $template);
	$output = $masking->maskHtml($input);
	Assert::same($expected, $output);
	Assert::same(['html' => $expected], $masking->maskArray(['html' => $output]));
}
$public = '<label for="f1">Username</label><input id="f1" name="f1" value="Public" title="Public" data-copy="Public">';
Assert::same($public, $masking->maskHtml($public));
Assert::same(
	'<table><tr><th>Password</th><td colspan="2" title="***">***</td></tr></table>',
	$masking->maskHtml('<table><tr><th>Password</th><td colspan="2" title="synthetic-secret">synthetic-secret</td></tr></table>'),
);
