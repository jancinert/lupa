<?php declare(strict_types=1);

use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking;
Assert::same([
	'mail a***@***.test [2]' => 'occupied',
	'mail a***@***.test' => null,
	'mail a***@***.test [3]' => 'next',
	'mail a***@***.test [4]' => 'literal',
	'mail a***@***.test [5]' => 'last',
], $masking->maskData([
	'mail aaaa@example.test [2]' => 'occupied',
	'mail abbb@example.test' => null,
	'mail accc@example.test' => 'next',
	'mail addd@example.test [4]' => 'literal',
	'mail aeee@example.test' => 'last',
]));
Assert::equal((object) [
	'token=***' => '***',
	'token=*** [2]' => null,
	'token=*** [2] [2]' => '***',
	'token=*** [3]' => '***',
], $masking->maskData((object) [
	'token=first' => 'synthetic-secret',
	'token=second [2]' => null,
	'token=third [2]' => 'synthetic-secret',
	'token=fourth' => 'synthetic-secret',
]));
Assert::same([0 => 'Public', 4 => null, 5 => true], $masking->maskData([0 => 'Public', 4 => null, 5 => true]));

$arguments = $expected = [];
for ($i = 0; $i < 4000; $i++) {
	$arguments['token=' . $i] = 'synthetic-secret';
	$expected['token=***' . ($i ? ' [' . ($i + 1) . ']' : '')] = '***';
}
Assert::same($expected, $masking->maskData($arguments));

// Compare scaling within one process, with headroom for shared CI machines.
$measure = static function (int $count) use ($masking): float {
	$values = [];
	for ($i = 0; $i < $count; $i++) {
		$values['token=' . $i] = 0;
	}
	$times = [];
	for ($run = 0; $run < 3; $run++) {
		$start = microtime(true);
		$masking->maskData($values);
		$times[] = microtime(true) - $start;
	}
	sort($times);
	return $times[1];
};
$small = $measure(1000);
$large = $measure(4000);
Assert::true($large < max(0.5, 8 * $small), "1000 keys: $small s; 4000 keys: $large s");
