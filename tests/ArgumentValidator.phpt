<?php declare(strict_types=1);

use Lupa\Mcp\ArgumentValidator;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$schema = [
	'type' => 'object',
	'properties' => [
		'name' => ['type' => 'string'],
		'limit' => ['type' => 'integer'],
		'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
		'nullable' => ['type' => ['string', 'null']],
	],
	'required' => ['name'],
	'additionalProperties' => false,
];
$validator = new ArgumentValidator;

Assert::same([], $validator->validate($schema, ['name' => 'x', 'limit' => 5, 'mode' => 'a', 'nullable' => null]));
Assert::same(["Missing required argument 'name'."], $validator->validate($schema, []));
Assert::same(["Argument 'limit' must be of type integer."], $validator->validate($schema, ['name' => 'x', 'limit' => '5']));
Assert::same(["Argument 'mode' must be one of: a, b."], $validator->validate($schema, ['name' => 'x', 'mode' => 'c']));
Assert::same(["Argument 'nullable' must be of type string or null."], $validator->validate($schema, ['name' => 'x', 'nullable' => 1]));
Assert::same(["Unknown argument 'other'."], $validator->validate($schema, ['name' => 'x', 'other' => 1]));
Assert::same(['Arguments must be an object.'], $validator->validate($schema, ['x', 'y']));
