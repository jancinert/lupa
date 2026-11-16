<?php declare(strict_types=1);

use Lupa\Database\Connection;
use Lupa\Database\MaskedQuery;
use Lupa\InspectorException;
use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking([], ['contacts' => ['note']]);
$schema = ['c' => ['id' => true, 'note' => true]];
$sources = ['c' => ['name' => 'contacts', 'parts' => ['contacts'], 'sql' => 'contacts']];
$query = new MaskedQuery($masking, 'SELECT c.id, c."note" FROM contacts c', Connection::Sqlite);
$projection = $query->checkFields($schema, false);
Assert::same([false, true], $projection->getMasks($sources, ['driver_label_one', 'driver_label_two']));
Assert::exception(
	fn() => $projection->getMasks($sources, ['only_one_column']),
	InspectorException::class,
	'The result columns do not match the validated SELECT.',
);

$publicSource = ['c' => ['name' => 'public_contacts', 'parts' => ['public_contacts'], 'sql' => 'public_contacts']];
Assert::same([false, false], $projection->getMasks($publicSource, ['id', 'note']));
$inheritedSource = ['c' => $publicSource['c'] + ['tables' => ['public_contacts', 'contacts']]];
Assert::same([false, true], $projection->getMasks($inheritedSource, ['id', 'note']));

$alias = new MaskedQuery($masking, 'SELECT c.id AS password FROM contacts c', Connection::Sqlite);
Assert::same([true], $alias->checkFields($schema, false)->getMasks($sources, ['password']));

$schema = [
	'l' => ['secret_l' => true, 'k2' => true, 'k1' => true, 'left_only' => true],
	'r' => ['right_only' => true, 'k1' => true, 'k2' => true, 'secret_r' => true],
];
$sources = [
	'l' => ['name' => 'left_table', 'parts' => ['left_table'], 'sql' => 'left_table'],
	'r' => ['name' => 'right_table', 'parts' => ['right_table'], 'sql' => 'right_table'],
];
foreach ([
	Connection::MySql => [false, false, true, false, false, true],
	Connection::PostgreSql => [false, false, true, false, false, true],
	Connection::Sqlite => [true, false, false, false, false, true],
] as $platform => $expected) {
	$query = new MaskedQuery(new Masking, 'SELECT * FROM left_table l JOIN right_table r USING(k1, k2)', $platform);
	$projection = $query->checkFields($schema, false);
	Assert::same($expected, $projection->getMasks($sources, array_fill(0, 6, 'driver_label')), $platform);

	$query = new MaskedQuery(new Masking, 'SELECT l.*, r.secret_r FROM left_table l JOIN right_table r USING(k1, k2)', $platform);
	$projection = $query->checkFields($schema, false);
	Assert::same([true, false, false, false, true], $projection->getMasks($sources, array_fill(0, 5, 'driver_label')), $platform);
}

$query = new MaskedQuery(new Masking, 'SELECT * FROM left_table l RIGHT OUTER JOIN right_table r USING(k1, k2)', Connection::MySql);
Assert::same(
	[false, false, false, true, true, false],
	$query->checkFields($schema, false)->getMasks($sources, array_fill(0, 6, 'driver_label')),
);

$schema['l']['k1'] = false;
$query = new MaskedQuery(new Masking, 'SELECT l.* FROM left_table l', Connection::MySql);
Assert::same([true, false, false], $query->checkFields($schema, false)->getMasks($sources, array_fill(0, 3, 'driver_label')));
// MySQL lists a joined invisible column in *, MariaDB does not, so the position of every column is uncertain
$query = new MaskedQuery(new Masking, 'SELECT * FROM left_table l JOIN right_table r USING(k1)', Connection::MySql);
Assert::exception(fn() => $query->checkFields($schema, false), InspectorException::class, 'Only direct SELECTs%a%');
$query = new MaskedQuery(new Masking, 'SELECT r.* FROM left_table l JOIN right_table r USING(k1)', Connection::MySql);
Assert::same([false, false, false, true], $query->checkFields($schema, false)->getMasks($sources, array_fill(0, 4, 'driver_label')));
