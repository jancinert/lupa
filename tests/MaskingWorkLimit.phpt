<?php declare(strict_types=1);

use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking([], ['private' => ['note']]);
// A balanced but invalid document must not restart a full scan from each nested opening bracket.
foreach ([4000, 32000] as $count) {
	Assert::same('***', $masking->maskText('p' . str_repeat('[', $count) . 'x' . str_repeat(']', $count)));
	Assert::same('***', $masking->maskText('p' . str_repeat('[ ', $count) . 'x' . str_repeat(' ]', $count)));
}

Assert::same('prefix [x,{"note":"***"}] suffix', $masking->maskText('prefix [x,{"note":"synthetic-secret"}] suffix'));
Assert::same(
	'prefix {"note":"***","name":"Public"} suffix',
	$masking->maskText('prefix {"note":"synthetic-secret","name":"Public"} suffix'),
);
foreach (['[[ link ]] wiki', '[2026-11-12 10:00] ERROR Import failed', 'SQLSTATE[42S22]: Column not found', '[1] foo [2] bar'] as $text) {
	Assert::same($text, $masking->maskText($text));
}
