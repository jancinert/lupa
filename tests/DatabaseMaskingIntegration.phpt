<?php declare(strict_types=1);

use Lupa\Guard\DatabaseTarget;
use Lupa\InspectorException;
use Lupa\Nette\NetteApplication;
use Lupa\Tools\AppContext;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


$platform = getenv('LUPA_TEST_DB_PLATFORM');
if ($platform === false) {
	Environment::skip('Set LUPA_TEST_DB_PLATFORM, LUPA_TEST_DB_PORT and LUPA_TEST_DB_PASSWORD for a local lupa_fixture database.');
}

Assert::contains($platform, ['mysql', 'pgsql']);
$port = (string) getenv('LUPA_TEST_DB_PORT');
Assert::true(Lupa\Scalar::isDigits($port) && (int) $port > 0 && (int) $port <= 65535);
$user = getenv('LUPA_TEST_DB_USER') ?: 'lupa';
$password = (string) getenv('LUPA_TEST_DB_PASSWORD');
$dsn = "$platform:host=127.0.0.1;port=$port;dbname=lupa_fixture";

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dibi = new Dibi\Connection([
	'driver' => $platform === 'mysql' ? 'mysqli' : 'postgre',
	'host' => '127.0.0.1',
	'port' => (int) $port,
	'database' => 'lupa_fixture',
	'username' => $user,
	'password' => $password,
]);

// Temporary tables are scoped to these connections, even if tests run concurrently.
$table = 'lupa_masking_' . bin2hex(random_bytes(5));
$create = "CREATE TEMPORARY TABLE $table (id INT, public_name VARCHAR(40), note VARCHAR(40), password VARCHAR(40))";
$insert = "INSERT INTO $table VALUES (1, 'Example', 'private-note', 'test-password')";
$times = $table . '_times';
foreach ([$create, $insert, "CREATE TEMPORARY TABLE $times (opened TIME)", "INSERT INTO $times VALUES ('08:30:00')"] as $sql) {
	$pdo->exec($sql);
	$dibi->nativeQuery($sql);
}

$documents = $table . '_documents';
$left = $table . '_left';
$right = $table . '_right';
$third = $table . '_third';
foreach ([new Lupa\Database\PdoConnection($pdo), new Lupa\Database\DibiConnection($dibi)] as $connection) {
	$connection->execute("CREATE TEMPORARY TABLE $documents (id INT, payload TEXT)");
	foreach (['', " \t\r\n"] as $id => $prefix) {
		$document = $prefix . '{"note":"synthetic-note","pa\u0073sword":"synthetic-password","items":[{"note":"nested-note"}]}';
		$connection->execute("INSERT INTO $documents VALUES ($id, " . $connection->quote($document) . ')');
	}
	foreach (require __DIR__ . '/fixtures/masked-documents.php' as $id => [$document, $expected]) {
		$connection->execute("INSERT INTO $documents VALUES (" . (20 + $id) . ', ' . $connection->quote($document) . ')');
	}

	foreach ([
		"CREATE TEMPORARY TABLE $left (memo_l TEXT, k2 INT, k1 INT, left_only TEXT)",
		"CREATE TEMPORARY TABLE $right (right_only TEXT, k1 INT, k2 INT, memo_r TEXT)",
		"CREATE TEMPORARY TABLE $third (third_only TEXT, k1 INT, k2 INT, memo_t TEXT)",
		"INSERT INTO $left VALUES ('hidden-left', 2, 1, 'left')",
		"INSERT INTO $right VALUES ('right', 1, 2, 'hidden-right')",
		"INSERT INTO $third VALUES ('third', 1, 2, 'public-third')",
	] as $sql) {
		$connection->execute($sql);
	}
}

$container = new Nette\DI\Container;
$container->addService('pdo', $pdo);
$container->addService('dibi', $dibi);
$targets = [];
foreach (['pdo' => $pdo, 'dibi' => $dibi] as $name => $connection) {
	$target = new DatabaseTarget($name, get_class($connection), DatabaseTarget::SourceObject);
	$target->applyDsn($dsn);
	$targets[] = $target;
}

$toolkit = new Lupa\Toolkits\DatabaseToolkit(new Lupa\Masking([], [$table => ['note']]), new Lupa\Database\Connections);
$shortCells = new Lupa\Toolkits\DatabaseToolkit(new Lupa\Masking, new Lupa\Database\Connections, 100, 50, 2);
$joins = new Lupa\Toolkits\DatabaseToolkit(
	new Lupa\Masking([], [$left => ['memo_l'], $right => ['memo_r']]),
	new Lupa\Database\Connections,
);
$context = new AppContext(
	new NetteApplication($container),
	createFixtureProject(),
	new ToolCatalog([$toolkit]),
	$targets,
);

// a server that cannot limit the time of a statement is refused: MySQL before 5.7.8, MariaDB before 10.1
if (
	$platform === 'mysql'
	&& !$pdo->query("SHOW VARIABLES WHERE Variable_name IN ('max_statement_time', 'max_execution_time')")->fetchAll()
) {
	Assert::exception(
		fn() => $toolkit->call('db_tables', ['connection' => 'pdo'], $context),
		InspectorException::class,
		'The database server %a% cannot limit the time of a statement, which the database tools need.%a%',
	);
	Environment::skip('The server cannot limit the time of a statement; only its refusal was tested.');
}

$queries = [
	"WITH sample (a, b, memo, exposed) AS (TABLE $table) SELECT a, memo, exposed FROM sample",
	"SELECT a, memo, exposed FROM (SELECT * FROM $table) AS c(a, b, memo, exposed)",
	"SELECT public_name FROM $table WHERE SUBSTR(password, 1, 1) = 't'",
	"SELECT public_name FROM $table WHERE note IS NOT NULL",
	"SELECT id, password FROM $table ORDER BY 2",
	"SELECT DISTINCT password FROM $table",
	"EXPLAIN SELECT password FROM $table",
];
if ($platform === 'pgsql') {
	$single = $table . '_single';
	$createSingle = "CREATE TEMPORARY TABLE $single AS SELECT password FROM $table";
	$zoned = $table . '_zoned';
	foreach ([$createSingle, "CREATE TEMPORARY TABLE $zoned (opened TIMETZ)", "INSERT INTO $zoned VALUES ('08:30:00+02')"] as $sql) {
		$pdo->exec($sql);
		$dibi->nativeQuery($sql);
	}

	array_push(
		$queries,
		"SELECT c AS snapshot FROM $table c",
		"SELECT row_to_json(c) AS snapshot FROM $table c",
		"SELECT c.public_name FROM $table c WHERE c IS NOT NULL",
		"SELECT CAST(password AS integer) FROM $table",
		"SELECT (ARRAY[(TABLE $single)])[1] AS exposed",
		"SELECT a, memo, exposed FROM $table AS c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM $table c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM ONLY ($table) c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM ONLY $table c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM ONLY\"$table\" c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM $table * c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM \"$table\"c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM $table using\$alias(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM $table partition(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM $table between(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM lupa_fixture.pg_temp.$table c(a, b, memo, exposed)",
		"SELECT a, memo, exposed FROM ($table c(a, b, memo, exposed) CROSS JOIN (VALUES (1)) v)",
		"SELECT x.a, x.memo, x.exposed FROM $table c, unnest(ARRAY[c]) WITH ORDINALITY x(a, b, memo, exposed)",
	);
}

foreach (['pdo', 'dibi'] as $connection) {
	$call = fn(string $sql, string $tool = 'db_query') => $toolkit->call(
		$tool,
		['connection' => $connection, 'sql' => $sql],
		$context,
	)->data;
	foreach ($queries as $sql) {
		Assert::exception(fn() => $call($sql), InspectorException::class, 'Only direct SELECTs%a%');
	}

	$invalid = $platform === 'pgsql' ? "id = 'test-error-literal'" : 'id IN ()';
	$error = Assert::exception(
		fn() => $call("SELECT public_name FROM $table WHERE $invalid"),
		InspectorException::class,
		'Database operation failed.',
	);
	Assert::null($error->getPrevious());
	Assert::exception(fn() => $call("SELECT id, password FROM $table", 'db_explain'), InspectorException::class, 'Only direct SELECTs%a%');
	Assert::hasKey('plan', $call("SELECT id FROM $table WHERE id = 1", 'db_explain'));

	$result = $call("SELECT public_name, note, password FROM $table");
	Assert::same([['public_name' => 'Example', 'note' => '***', 'password' => '***']], $result['rows']);
	$result = $call("SELECT c.* FROM $table AS c");
	Assert::same(['public_name' => 'Example', 'note' => '***', 'password' => '***'], array_diff_key($result['rows'][0], ['id' => true]));

	$result = $call("SELECT c.public_name FROM $table c WHERE (c.id IN (1)) AND (c.id = 1)");
	Assert::same([['public_name' => 'Example']], $result['rows']);

	$result = $call("SELECT public_name FROM $table WHERE (id) BETWEEN (id) AND (id)");
	Assert::same([['public_name' => 'Example']], $result['rows']);

	$result = $shortCells->call('db_query', ['connection' => $connection, 'sql' => "SELECT 'ě漢X' AS label"], $context)->data;
	Assert::same([['label' => 'ě漢… (3 characters)']], $result['rows']);

	// parameters are quoted by the driver and cannot change the query
	$bound = fn(string $sql, array $params, string $tool = 'db_query') => $toolkit->call(
		$tool,
		['connection' => $connection, 'sql' => $sql, 'params' => $params],
		$context,
	)->data;
	$sql = "SELECT public_name FROM $table WHERE id = ? AND public_name = ?";
	Assert::same([['public_name' => 'Example']], $bound($sql, [1, 'Example'])['rows']);
	Assert::same([], $bound($sql, [1, "x\\' OR 1 = 1 -- "])['rows']);
	Assert::same([], $bound($sql, [1, "Example' OR '1' = '1"])['rows']);
	// a negative number after a minus must not start a comment that uncovers SQL hidden from the check
	Assert::same(
		[['public_name' => 'Example']],
		$bound("SELECT public_name FROM $table WHERE 2 = 1 -? /*\nUNION SELECT note FROM $table\n-- */", [-1])['rows'],
	);
	Assert::hasKey('plan', $bound($sql, [1, 'Example'], 'db_explain'));

	foreach ([0, 1] as $id) {
		$row = $bound("SELECT payload FROM $documents WHERE id = ?", [$id])['rows'][0];
		Assert::same(
			['note' => '***', 'password' => '***', 'items' => [['note' => '***']]],
			json_decode($row['payload'], true),
			$connection,
		);
	}
	foreach (require __DIR__ . '/fixtures/masked-documents.php' as $id => [$document, $expected]) {
		$row = $bound("SELECT payload FROM $documents WHERE id = ?", [20 + $id])['rows'][0];
		Assert::same($expected, $row['payload'], $connection . ' document ' . $id);
	}

	// a time of day stays a time with every driver
	Assert::same([['opened' => '08:30:00']], $call("SELECT opened FROM $times")['rows']);
	if ($platform === 'pgsql') { // and a time with a zone keeps the zone
		Assert::same([['opened' => '08:30:00+02']], $call("SELECT opened FROM $zoned")['rows']);
	}

	$readJoin = fn(string $sql) => $joins->call('db_query', ['connection' => $connection, 'sql' => $sql], $context)->data['rows'][0];
	foreach (['JOIN', 'LEFT JOIN', 'RIGHT OUTER JOIN'] as $join) {
		$row = array_map('strval', $readJoin("SELECT * FROM $left l $join $right r USING(k1, k2)"));
		$keys = $platform === 'mysql' && $join !== 'RIGHT OUTER JOIN' ? ['k2' => '2', 'k1' => '1'] : ['k1' => '1', 'k2' => '2'];
		$values = $platform === 'mysql' && $join === 'RIGHT OUTER JOIN'
			? ['right_only' => 'right', 'memo_r' => '***', 'memo_l' => '***', 'left_only' => 'left']
			: ['memo_l' => '***', 'left_only' => 'left', 'right_only' => 'right', 'memo_r' => '***'];
		Assert::same($keys + $values, $row, $connection);
	}

	Assert::same(
		['memo_l' => '***', 'k2' => '2', 'k1' => '1', 'left_only' => 'left', 'memo_r' => '***'],
		array_map('strval', $readJoin("SELECT l.*, r.memo_r FROM $left l JOIN $right r USING(k1, k2)")),
		$connection,
	);
}

// Commas start a separate joined table in MySQL and PostgreSQL. PDO retains duplicate column slots.
$row = $joins->call(
	'db_query',
	['connection' => 'pdo', 'sql' => "SELECT * FROM $left l, $right r JOIN $third t USING(k1, k2)"],
	$context,
)->data['rows'][0];
Assert::same(['***', '***', 'public-third'], [$row['memo_l'], $row['memo_r'], $row['memo_t']]);

$invisibleColumns = false;
if ($platform === 'mysql') {
	try {
		$pdo->exec("CREATE TEMPORARY TABLE {$table}_probe (a INT, b INT INVISIBLE)");
		$invisibleColumns = true;
	} catch (PDOException $e) {
		// invisible columns came in MySQL 8.0.23 and MariaDB 10.3.3
	}
}

if ($invisibleColumns) {
	$invisible = $table . '_invisible';
	$maskedInvisible = new Lupa\Toolkits\DatabaseToolkit(
		new Lupa\Masking([], [$invisible => ['memo_i'], $right => ['memo_r']]),
		new Lupa\Database\Connections,
	);
	foreach (['pdo' => new Lupa\Database\PdoConnection($pdo), 'dibi' => new Lupa\Database\DibiConnection($dibi)] as $name => $connection) {
		$connection->execute("CREATE TEMPORARY TABLE $invisible (memo_i TEXT, k1 INT INVISIBLE, tail_i TEXT)");
		$connection->execute("INSERT INTO $invisible (memo_i, k1, tail_i) VALUES ('hidden-invisible', 1, 'tail')");
		$read = fn(string $sql) => $maskedInvisible->call('db_query', ['connection' => $name, 'sql' => $sql], $context)->data['rows'][0];
		Assert::same(['memo_i' => '***', 'tail_i' => 'tail'], $read("SELECT i.* FROM $invisible i"));
		Assert::same(['k1' => '1'], array_map('strval', $read("SELECT i.k1 FROM $invisible i")));
		// MySQL lists the joined invisible column in *, MariaDB does not
		Assert::exception(
			fn() => $read("SELECT * FROM $invisible i JOIN $right r USING(k1)"),
			InspectorException::class,
			'Only direct SELECTs%a%',
		);
		Assert::same(
			['memo_i' => '***', 'tail_i' => 'tail', 'k2' => '2'],
			array_map('strval', $read("SELECT i.*, r.k2 FROM $invisible i JOIN $right r USING(k1)")),
			$name,
		);
	}
}

$schemaComments = $table . '_comments';
$pdo->exec("CREATE TABLE $schemaComments (label TEXT)");
try {
	$comments = [
		['ordinary comment', 'ordinary comment'],
		['contact review.person@example.test Bearer synthetic-review-token', 'contact r***@***.test Bearer ***'],
		['{"note":"synthetic-note","pa\u0073sword":"synthetic-password"}', '{"note":"***","password":"***"}'],
		['{"note":"synthetic-note",', '***'],
	];
	foreach ($comments as [$comment, $expected]) {
		$quoted = $pdo->quote($comment);
		if ($platform === 'mysql') {
			$pdo->exec("ALTER TABLE $schemaComments COMMENT = $quoted");
			$pdo->exec("ALTER TABLE $schemaComments MODIFY label TEXT COMMENT $quoted");
		} else {
			$pdo->exec("COMMENT ON COLUMN $schemaComments.label IS $quoted");
		}
		foreach (['pdo', 'dibi'] as $connection) {
			$detail = $toolkit->call('db_table', ['connection' => $connection, 'table' => $schemaComments], $context)->data;
			Assert::same('label', $detail['columns'][0]['name']);
			Assert::same($expected, $detail['columns'][0]['comment'], $connection);
			$tables = $toolkit->call('db_tables', ['connection' => $connection, 'name' => $schemaComments], $context)->data;
			Assert::same([$schemaComments], array_column($tables['tables'], 'name'));
			if ($platform === 'mysql') {
				Assert::same($expected, $tables['tables'][0]['comment'], $connection);
			}
		}
	}
} finally {
	$pdo->exec("DROP TABLE $schemaComments");
}

if ($platform === 'mysql') {
	$schemaTable = $table . '_defaults';
	$pdo->exec("CREATE TABLE $schemaTable (id INT DEFAULT 3, password VARCHAR(40) DEFAULT 'fixture-default', label VARCHAR(40),"
		. " state ENUM('new', 'done') DEFAULT 'new', created DATETIME DEFAULT CURRENT_TIMESTAMP, note VARCHAR(40) DEFAULT 'fixture-note',"
		. " typed VARCHAR(40) DEFAULT '0::fixture', code VARCHAR(20) DEFAULT '1234567890123456')");
	try {
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
		foreach (['pdo', 'dibi'] as $connection) {
			$detail = $toolkit->call('db_table', ['connection' => $connection, 'table' => $schemaTable], $context)->data;
			$defaults = array_column($detail['columns'], 'default');
			Assert::same(['3', '***'], array_map('strval', array_slice($defaults, 0, 2)));
			Assert::null($defaults[2]); // MariaDB reports it as the text NULL
			// MariaDB writes the quotes and parentheses MySQL leaves out
			Assert::contains($defaults[3], ['new', "'new'"]);
			Assert::contains($defaults[4], ['CURRENT_TIMESTAMP', 'current_timestamp()']);
			Assert::same(['***', '***', '***'], array_slice($defaults, 5)); // MySQL returns text without quotes
		}

		// the reflection of the database library, used for platforms without queries of the inspector's own
		$described = Lupa\Database\LibrarySchema::describeTable((new Lupa\Database\DibiConnection($dibi))->getReflector(), $schemaTable);
		Assert::same(
			['id', 'password', 'label', 'state', 'created', 'note', 'typed', 'code'],
			array_column($described['columns'], 'name'),
		);
	} finally {
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
		$pdo->exec("DROP TABLE $schemaTable");
	}
}

if ($platform === 'pgsql') {
	$parent = $table . '_parent';
	$child = $table . '_child';
	$pdo->exec(
		"CREATE TABLE $parent (id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, label VARCHAR(40) DEFAULT 'fixture-label',"
		. " password VARCHAR(40) DEFAULT 'fixture-default', opened TIME NOT NULL DEFAULT now())",
	);
	try {
		$pdo->exec("CREATE INDEX {$parent}_private ON $parent ((lower('person@example.test'::text)))");
		$pdo->exec("COMMENT ON COLUMN $parent.label IS 'fixture comment'");
		$pdo->exec("CREATE TABLE $child (id INT, parent_id INT REFERENCES $parent (id))");
		$pdo->exec("CREATE INDEX {$child}_pair ON $child (parent_id, lower(id::text))");
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
		$key = [
			'name' => "{$child}_parent_id_fkey",
			'table' => $child,
			'column' => 'parent_id',
			'referencedTable' => $parent,
			'referencedColumn' => 'id',
		];
		foreach (['pdo', 'dibi'] as $connection) {
			$detail = $toolkit->call('db_table', ['connection' => $connection, 'table' => $parent], $context)->data;
			Assert::same([
				[
					'name' => 'id',
					'type' => 'integer',
					'nullable' => false,
					'default' => null,
					'key' => 'PRI',
					'extra' => 'GENERATED ALWAYS AS IDENTITY',
					'comment' => '',
				],
				[
					'name' => 'label',
					'type' => 'character varying(40)',
					'nullable' => true,
					'default' => '***',
					'key' => '',
					'extra' => '',
					'comment' => 'fixture comment',
				],
				[
					'name' => 'password',
					'type' => 'character varying(40)',
					'nullable' => true,
					'default' => '***',
					'key' => '',
					'extra' => '',
					'comment' => '',
				],
				[
					'name' => 'opened',
					'type' => 'time without time zone',
					'nullable' => false,
					'default' => 'now()',
					'key' => '',
					'extra' => '',
					'comment' => '',
				],
			], $detail['columns']);
			Assert::same([
				['name' => "{$parent}_pkey", 'unique' => true, 'columns' => ['id']],
				['name' => "{$parent}_private", 'unique' => false, 'columns' => ['***']],
			], $detail['indexes']);
			Assert::same([], $detail['foreignKeys']);
			Assert::same([$key], $detail['referencedBy']);

			$detail = $toolkit->call('db_table', ['connection' => $connection, 'table' => $child], $context)->data;
			Assert::same(
				[['name' => "{$child}_pair", 'unique' => false, 'columns' => ['parent_id', 'lower(id::text)']]],
				$detail['indexes'],
			);
			Assert::same([$key], $detail['foreignKeys']);
			Assert::same([], $detail['referencedBy']);
			Assert::same(
				[$key],
				$toolkit->call('db_relations', ['connection' => $connection, 'table' => $child], $context)->data['relations'],
			);
		}
	} finally {
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
		$pdo->exec("DROP TABLE IF EXISTS $child");
		$pdo->exec("DROP TABLE $parent");
	}
}

if ($platform === 'pgsql') {
	$ancestor = $table . '_ancestor';
	$descendant = $table . '_descendant';
	$leaf = $table . '_leaf';
	$partitioned = $table . '_partitioned';
	$partition = $table . '_partition';
	$partitionLeaf = $table . '_partition_leaf';
	$unrelated = $table . '_unrelated';
	$inherited = new Lupa\Toolkits\DatabaseToolkit(
		new Lupa\Masking([], [$leaf => ['note'], $partitionLeaf => ['note']]),
		new Lupa\Database\Connections,
	);
	$parentMasking = new Lupa\Toolkits\DatabaseToolkit(
		new Lupa\Masking([], [$ancestor => ['note'], $partitioned => ['note']]),
		new Lupa\Database\Connections,
	);
	foreach (['pdo' => new Lupa\Database\PdoConnection($pdo), 'dibi' => new Lupa\Database\DibiConnection($dibi)] as $name => $connection) {
		foreach ([
			"CREATE TEMP TABLE $ancestor (id INT, public_name TEXT, note TEXT)",
			"CREATE TEMP TABLE $descendant () INHERITS ($ancestor)",
			"CREATE TEMP TABLE $leaf () INHERITS ($descendant)",
			"CREATE TEMP TABLE $partitioned (id INT, public_name TEXT, note TEXT) PARTITION BY RANGE (id)",
			"CREATE TEMP TABLE $partition PARTITION OF $partitioned FOR VALUES FROM (0) TO (10) PARTITION BY RANGE (id)",
			"CREATE TEMP TABLE $partitionLeaf PARTITION OF $partition FOR VALUES FROM (0) TO (10)",
			"CREATE TEMP TABLE $unrelated (note TEXT)",
			"INSERT INTO $leaf VALUES (1, 'Example', 'synthetic-inherited-note')",
			"INSERT INTO $partitioned VALUES (1, 'Example', 'synthetic-partitioned-note')",
			"INSERT INTO $unrelated VALUES ('public-note')",
		] as $sql) {
			$connection->execute($sql);
		}

		$read = fn(string $sql, string $tool = 'db_query') => $inherited->call(
			$tool,
			['connection' => $name, 'sql' => $sql],
			$context,
		)->data;
		foreach ([$ancestor, $descendant, $leaf, $partitioned, $partition, $partitionLeaf] as $source) {
			Assert::same([['note' => '***']], $read("SELECT note FROM $source")['rows'], $name);
			Assert::same([['note' => '***']], $read("SELECT s.note FROM $source AS s")['rows'], $name);
			$row = $read("SELECT s.* FROM $source AS s")['rows'][0];
			Assert::same(['Example', '***'], [$row['public_name'], $row['note']], $name);
			Assert::same([['public_name' => 'Example']], $read("SELECT public_name FROM $source")['rows'], $name);
			Assert::hasKey('plan', $read("SELECT public_name FROM $source", 'db_explain'));
			Assert::exception(fn() => $read("SELECT note FROM $source", 'db_explain'), InspectorException::class, 'Only direct SELECTs%a%');
		}

		Assert::same([['note' => 'public-note']], $read("SELECT note FROM $unrelated")['rows'], $name);
		foreach ([$descendant, $leaf, $partition, $partitionLeaf] as $source) {
			$row = $parentMasking->call('db_query', ['connection' => $name, 'sql' => "SELECT note FROM $source"], $context)->data;
			Assert::same([['note' => '***']], $row['rows'], $name);
		}
	}
}

// only base tables with plain columns can be queried: what a view or a generated column computes cannot be checked
$view = $table . '_view';
$computed = $table . '_computed';
$pdo->exec("CREATE TABLE {$view}_base (name VARCHAR(40))"); // not temporary, MySQL has no views of temporary tables
$pdo->exec("CREATE VIEW $view AS SELECT name FROM {$view}_base");
$pdo->exec("CREATE TEMPORARY TABLE $computed (name VARCHAR(40), shout VARCHAR(40) "
	. ($platform === 'mysql' ? 'AS (UPPER(name)))' : 'GENERATED ALWAYS AS (UPPER(name)) STORED)'));
if ($platform === 'mysql') { // MariaDB before 10.2 names a stored column only PERSISTENT
	$stored = stripos((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false ? 'PERSISTENT' : 'STORED';
	$pdo->exec("CREATE TEMPORARY TABLE {$computed}_stored (name VARCHAR(40), shout VARCHAR(40) AS (UPPER(name)) $stored)");
}

try {
	foreach ([
		"SELECT name FROM $view",
		"SELECT name FROM $computed",
		$platform === 'mysql' ? "SELECT name FROM {$computed}_stored" : "SELECT name FROM $computed",
		$platform === 'mysql' ? 'SELECT user FROM mysql.user' : 'SELECT rolname FROM pg_catalog.pg_roles',
	] as $sql) {
		Assert::exception(
			fn() => $toolkit->call('db_query', ['connection' => 'pdo', 'sql' => $sql], $context),
			InspectorException::class,
			'Query sources must be verifiable base tables without generated columns.',
		);
	}
} finally {
	$pdo->exec("DROP VIEW $view");
	$pdo->exec("DROP TABLE {$view}_base");
}

// a locked session also limits statements of the application on the server, by the time limit of a tool call (10 s in lupa.neon)
$connections = Lupa\Inspector::createContainer(FixtureDir . '/lupa.neon')->getByType(Lupa\Database\Connections::class);
Assert::same(['readOnly' => ['pdo', 'dibi'], 'unreachable' => []], $connections->lockSessions($context));
$mariaDb = $platform === 'mysql' && stripos((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
$read = $platform === 'pgsql'
	? 'SHOW statement_timeout'
	: ($mariaDb ? 'SELECT @@max_statement_time * 1000' : 'SELECT @@max_execution_time');
foreach ([$pdo->query($read)->fetchColumn(), $dibi->nativeQuery($read)->fetchSingle()] as $limit) {
	Assert::same($platform === 'pgsql' ? '10s' : 10000.0, $platform === 'pgsql' ? $limit : (float) $limit);
}
