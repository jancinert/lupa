<?php declare(strict_types=1);

use Lupa\Database\Connections;
use Lupa\Database\DibiConnection;
use Lupa\Database\PdoConnection;
use Lupa\Guard\DatabaseTarget;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Toolkits\DatabaseToolkit;
use Lupa\Tools\AppContext;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$dsn = 'sqlite::memory:';
$pdo = new PDO($dsn, null, null, [PDO::ATTR_CASE => PDO::CASE_UPPER]);
$pdo->exec('PRAGMA short_column_names = OFF');
$pdo->exec('PRAGMA full_column_names = OFF');
$pdo->exec("CREATE TABLE contacts (name TEXT, note TEXT DEFAULT 'default-note', password TEXT DEFAULT 'default-password')");
$pdo->exec("INSERT INTO contacts (name, note, password) VALUES ('Example', 'private-note', NULL)");

$container = new Nette\DI\Container;
$container->addService('pdo', $pdo);
$target = new DatabaseTarget('pdo', PDO::class, DatabaseTarget::SourceObject);
$target->applyDsn($dsn);
$toolkit = new DatabaseToolkit(new Masking([], ['contacts' => ['note']]), new Connections);
$context = new AppContext(new NetteApplication($container), createFixtureProject(), new ToolCatalog([$toolkit]), [$target]);

foreach ([
	'SELECT u.name, u . note, u.password FROM contacts u',
	'SELECT u.name, u./* comment */note, u.password FROM contacts u',
	'SELECT u.name, u."note", u.password FROM contacts u',
	'SELECT name, "note", password FROM contacts u',
	'SELECT u.* FROM contacts u',
	'SELECT "a strange alias".name, "a strange alias" . "note", "a strange alias".password FROM contacts AS "a strange alias"',
] as $sql) {
	$result = $toolkit->call('db_query', ['sql' => $sql], $context)->data;
	Assert::same(['Example', '***', '***'], array_values($result['rows'][0]), $sql);
}

$detail = $toolkit->call('db_table', ['table' => 'contacts'], $context)->data;
Assert::same([null, '***', '***'], array_column($detail['columns'], 'default'));
// a short number, an empty text, a date or an expression stays;
// other text may be a secret written into the schema, also when it imitates a cast
$pdo->exec("CREATE TABLE shapes (a INT DEFAULT 0, b TEXT DEFAULT '', c TEXT DEFAULT CURRENT_TIMESTAMP, d TEXT DEFAULT '2020-01-01',"
	. " e TEXT DEFAULT 'pending', f TEXT DEFAULT (lower('Secret')), g TEXT DEFAULT '0::hunter2', h TEXT DEFAULT 1234567890123456)");
$detail = $toolkit->call('db_table', ['table' => 'shapes'], $context)->data;
Assert::same(
	['0', "''", 'CURRENT_TIMESTAMP', "'2020-01-01'", '***', '***', '***', '***'],
	array_column($detail['columns'], 'default'),
);

// a JSON document is masked by its structure, a number by its shape
$pdo->exec('CREATE TABLE profiles (id INTEGER, phone INTEGER, meta TEXT)');
$pdo->exec('INSERT INTO profiles VALUES (1, 777123456, \'{"name":"Example","api":{"password":"made-up-value","retries":3},"tags":["a"]}\')');
$row = $toolkit->call('db_query', ['sql' => 'SELECT id, phone, meta FROM profiles'], $context)->data['rows'][0];
Assert::same(['1', '******456', '{"name":"Example","api":{"password":"***","retries":3},"tags":["a"]}'], array_map('strval', array_values($row))); // PDO returns numbers as strings before PHP 8.1

// a key named like a masked column of any table is hidden inside a document too
$pdo->exec('INSERT INTO profiles VALUES (2, NULL, \'{"note":"made-up-note","items":[{"note":"made-up-item","count":2}]}\')');
$row = $toolkit->call('db_query', ['sql' => 'SELECT meta FROM profiles WHERE id = 2'], $context)->data['rows'][0];
Assert::same(['{"note":"***","items":[{"note":"***","count":2}]}'], array_values($row));

// an optimizer hint is refused on every platform
Assert::exception(
	fn() => $toolkit->call('db_query', ['sql' => 'SELECT /*+ made_up_hint */ name FROM contacts'], $context),
	Lupa\InspectorException::class,
	'Optimizer hints are not supported in masked queries.',
);

// limits are validated
foreach ([0, -1, 1001] as $limit) {
	Assert::exception(
		fn() => $toolkit->call('db_usage', ['table' => 'contacts', 'limit' => $limit], $context),
		Lupa\InspectorException::class,
		"Argument 'limit' must be between 1 and 1000.",
	);
}

// parameters go to ? placeholders outside literals and cannot change the query
$query = fn(string $sql, array $params) => array_map(
	'array_values',
	$toolkit->call('db_query', ['sql' => $sql, 'params' => $params], $context)->data['rows'],
);
Assert::same([['Example']], $query('SELECT name FROM contacts WHERE name = ?', ['Example']));
Assert::same([['?', 'Example']], $query("SELECT '?' AS mark, name FROM contacts WHERE name = ? LIMIT ?", ['Example', 1]));
Assert::same([], $query('SELECT name FROM contacts WHERE name = ?', ["x\\' OR 1 = 1 -- "]));
Assert::same([], $query('SELECT name FROM contacts WHERE name = ? OR name = ?', [false, null]));
// a negative number after a minus must not start a comment that uncovers SQL hidden from the check
Assert::same([['Example']], $query("SELECT name FROM contacts WHERE 2 = 1 -? /*\nUNION SELECT note FROM contacts\n-- */", [-1]));
Assert::exception(
	fn() => $query('SELECT name FROM contacts WHERE name = ?', []),
	Lupa\InspectorException::class,
	'The query has 1 placeholder(s) but 0 parameter(s) were given.',
);
Assert::exception(
	fn() => $query('SELECT name FROM contacts WHERE name = ?', [['nested']]),
	Lupa\InspectorException::class,
	"Argument 'params' may contain only strings, finite numbers, booleans and null.",
);
Assert::exception(
	fn() => $query('SELECT name FROM contacts WHERE note = ?', ['private-note']),
	Lupa\InspectorException::class,
	'Only direct SELECTs%a%',
);


// the reflection of the database library, used for platforms without queries of the inspector's own
$file = TempDir . '/library.sqlite';
(new PDO("sqlite:$file"))->exec(
	'CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL);'
	. 'CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER REFERENCES authors (id), title TEXT DEFAULT \'untitled\');'
	. 'CREATE UNIQUE INDEX books_title ON books (title);'
	. 'CREATE VIEW titles AS SELECT title FROM books',
);
$nette = new Nette\Database\Connection("sqlite:$file");
$driver = class_exists(Dibi\Drivers\SqliteDriver::class) ? 'sqlite' : 'sqlite3'; // Dibi 4.0 has only the latter
$reflectors = [
	'dibi' => (new Lupa\Database\DibiConnection(new Dibi\Connection(['driver' => $driver, 'database' => $file])))->getReflector(),
	'nette' => (new Lupa\Database\PdoConnection($nette->getPdo(), $nette->getSupplementalDriver()))->getReflector(),
];
foreach ($reflectors as $library => $reflector) {
	Assert::same(
		[['authors', 'BASE TABLE'], ['books', 'BASE TABLE'], ['titles', 'VIEW']],
		array_map(fn(array $table) => [$table['name'], $table['type']], Lupa\Database\LibrarySchema::listTables($reflector)),
		$library,
	);
	$table = Lupa\Database\LibrarySchema::describeTable($reflector, 'books');
	Assert::same(['id', 'author_id', 'title'], array_column($table['columns'], 'name'), $library);
	Assert::false(Lupa\Database\LibrarySchema::describeTable($reflector, 'authors')['columns'][1]['nullable'], $library);
	Assert::contains(['name' => 'books_title', 'unique' => true, 'columns' => ['title']], $table['indexes'], $library);
	$keys = Lupa\Database\LibrarySchema::listForeignKeys($reflector, 'books');
	Assert::same(
		[['books', 'author_id', 'authors', 'id']],
		array_map(fn(array $key) => [$key['table'], $key['column'], $key['referencedTable'], $key['referencedColumn']], $keys),
		$library,
	);
}

Assert::null((new Lupa\Database\PdoConnection(new PDO('sqlite::memory:')))->getReflector());


// only base tables with plain columns can be queried: what a view or a generated column computes cannot be checked
$pdo->exec('CREATE VIEW contact_notes AS SELECT note AS name FROM contacts');
$pdo->exec('CREATE TABLE computed (name TEXT, shout TEXT GENERATED ALWAYS AS (upper(name)) VIRTUAL)');
foreach ([
	'SELECT name FROM contact_notes',
	'SELECT name FROM computed',
	'SELECT name FROM sqlite_master',
	'SELECT name FROM missing_table',
	'SELECT name FROM other.main.contacts',
] as $sql) {
	Assert::exception(
		fn() => $toolkit->call('db_query', ['sql' => $sql], $context),
		Lupa\InspectorException::class,
		'Query sources must be verifiable base tables without generated columns.',
	);
}

Assert::same([['Example']], $query('SELECT name FROM main.contacts', []));


// Parameters stay literals and JSON documents are masked independently of their formatting.
$services = [
	'pdo' => new PDO('sqlite::memory:'),
	// Dibi 4.0's native sqlite3 metadata emits a notice for an empty result; its PDO driver handles it.
	'dibi' => new Dibi\Connection(['driver' => 'pdo', 'dsn' => 'sqlite::memory:']),
];
foreach ($services as $name => $service) {
	$connection = $service instanceof PDO ? new PdoConnection($service) : new DibiConnection($service);
	$connection->execute('CREATE TABLE flags (public_name TEXT, "true" TEXT, "false" TEXT)');
	$connection->execute("INSERT INTO flags VALUES ('Example', 'synthetic-true', 'synthetic-false')");
	$connection->execute('CREATE TABLE documents (id INT, meta TEXT)');

	$container = new Nette\DI\Container;
	$container->addService($name, $service);
	$target = new DatabaseTarget($name, get_class($service), DatabaseTarget::SourceObject);
	$target->applyDsn('sqlite::memory:');
	$toolkit = new DatabaseToolkit(new Masking([], ['flags' => ['true', 'false'], 'documents' => ['note']]), new Connections);
	$context = new AppContext(new NetteApplication($container), createFixtureProject(), new ToolCatalog([$toolkit]), [$target]);
	$query = fn(string $sql, array $params = []) => $toolkit->call('db_query', ['sql' => $sql, 'params' => $params], $context)->data;

	Assert::same([['true' => '***', 'false' => '***']], $query('SELECT "true", "false" FROM flags')['rows'], $name);
	foreach ([true, false] as $value) {
		$row = $query('SELECT ? AS flag FROM flags', [$value])['rows'][0];
		Assert::same($value ? '1' : '0', (string) $row['flag'], $name);
		Assert::same(
			[],
			$query('SELECT public_name FROM flags WHERE ? = ?', [$value, $value ? 'synthetic-true' : 'synthetic-false'])['rows'],
			$name,
		);
		Assert::same(
			[['public_name' => 'Example']],
			$query('SELECT public_name FROM flags WHERE ? = ?', [$value, $value ? 1 : 0])['rows'],
			$name,
		);
		Assert::hasKey(
			'plan',
			$toolkit->call('db_explain', ['sql' => 'SELECT ? AS flag FROM flags', 'params' => [$value]], $context)->data,
		);
	}

	$document = '{"note":"synthetic-note","pa\u0073sword":"synthetic-password","items":[{"note":"nested-note","count":2}]}';
	foreach (['', ' ', "\t", "\r", "\n", " \r\n\t"] as $id => $prefix) {
		$connection->execute('INSERT INTO documents VALUES (' . $id . ', ' . $connection->quote($prefix . $document) . ')');
		$row = $query('SELECT meta FROM documents WHERE id = ?', [$id])['rows'][0];
		Assert::same(
			['note' => '***', 'password' => '***', 'items' => [['note' => '***', 'count' => 2]]],
			json_decode($row['meta'], true),
			$name,
		);
	}

	$connection->execute('INSERT INTO documents VALUES (10, ' . $connection->quote("\n [{\"note\":\"synthetic-note\"}]") . ')');
	$row = $query('SELECT meta FROM documents WHERE id = 10')['rows'][0];
	Assert::same([['note' => '***']], json_decode($row['meta'], true), $name);
	Assert::same([['label' => ' leading space']], $query('SELECT ? AS label', [' leading space'])['rows'], $name);
	foreach (require __DIR__ . '/fixtures/masked-documents.php' as $id => [$document, $expected]) {
		$connection->execute('INSERT INTO documents VALUES (' . (20 + $id) . ', ' . $connection->quote($document) . ')');
		$row = $query('SELECT meta FROM documents WHERE id = ?', [20 + $id])['rows'][0];
		Assert::same($expected, $row['meta'], $name . ' document ' . $id);
	}

	$connection->execute('ALTER TABLE flags ADD COLUMN id INT DEFAULT 1');
	$row = $query('SELECT * FROM flags f JOIN documents d USING(id)')['rows'][0];
	Assert::same(['public_name', 'true', 'false', 'id', 'meta'], array_keys($row), $name);
	Assert::same(['Example', '***', '***'], [$row['public_name'], $row['true'], $row['false']], $name);
}
