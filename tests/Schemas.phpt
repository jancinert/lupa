<?php declare(strict_types=1);

/**
 * Every tool says what its result looks like; the toolkit tests compare the results with these schemas.
 */

use Lupa\Inspector;
use Lupa\Tools\Schema;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// the notation of results
Assert::same(['type' => 'string'], Schema::result('string'));
Assert::same(['type' => ['integer', 'null']], Schema::result('?integer'));
Assert::same(['type' => ['object', 'array']], Schema::result('object|array'));
Assert::same(['type' => 'array', 'items' => ['type' => 'string']], Schema::result('string[]'));
Assert::same(['type' => ['string', 'number', 'boolean', 'object', 'array', 'null']], Schema::result('mixed'));
Assert::same(
	[
		'type' => 'object',
		'properties' => [
			'total' => ['type' => 'integer'],
			'place' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string']]],
			'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => ['string', 'null']]]]],
		],
	],
	Schema::result(['total' => 'integer', 'place' => ['file' => 'string'], 'items' => [['name' => '?string']]]),
);

// every tool of Lupa has a schema, the active ones too
$missing = [];
foreach (Inspector::createContainer(FixtureDir . '/lupa-render.neon')->getByType(ToolCatalog::class)->getTools() as $tool) {
	if ($tool->outputSchema === null && strncmp($tool->name, 'test_', 5) !== 0) {
		$missing[] = $tool->name;
	}
}

Assert::same([], $missing);

// the comparison used by the toolkit tests notices a wrong type
Assert::same([], findSchemaErrors(Schema::result(['rows' => [['id' => 'integer']]]), ['rows' => [['id' => 1, 'extra' => 'x']]], 'tool'));
Assert::same(
	['tool.rows[1].id is string, the schema says integer'],
	findSchemaErrors(Schema::result(['rows' => [['id' => 'integer']]]), ['rows' => [['id' => 1], ['id' => 'x']]], 'tool'),
);
