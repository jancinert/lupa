<?php declare(strict_types=1);

use Lupa\Database\Connection;
use Lupa\Database\QueryValidator;
use Lupa\InspectorException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$validator = new QueryValidator;

$allowed = [
	'SELECT * FROM orders WHERE id = 1;' => 'SELECT * FROM orders WHERE id = 1',
	"select 'DELETE FROM x; DROP TABLE y' AS text" => null,
	'SELECT `update`, "insert" FROM t' => null,
	'SELECT "pg_read_file" FROM t' => null,
	"SELECT 'pg_read_file()' AS description" => null,
	"SELECT * FROM t -- DELETE\nWHERE a = 1" => null,
	'SELECT /*+ MAX_EXECUTION_TIME(100) */ 1' => null,
	"SELECT 'it''s', 'a\\\\b' FROM t" => null,
	"SELECT 1 -- a comment; with a semicolon\nFROM t" => null,
];
foreach ($allowed as $sql => $expected) {
	Assert::same($expected ?? $sql, $validator->validate($sql), $sql);
}

$refused = [
	'DELETE FROM orders' => QueryValidator::SupportedQuery,
	'WITH x AS (SELECT 1) SELECT * FROM x' => QueryValidator::SupportedQuery,
	'(SELECT 1) UNION (SELECT 2)' => QueryValidator::SupportedQuery,
	'SHOW INDEX FROM orders' => QueryValidator::SupportedQuery,
	'DESCRIBE orders' => QueryValidator::SupportedQuery,
	'EXPLAIN SELECT 1' => QueryValidator::SupportedQuery,
	'VALUES (1)' => QueryValidator::SupportedQuery,
	'TABLE orders' => QueryValidator::SupportedQuery,
	'SELECT 1; DELETE FROM orders' => 'Only a single statement%A?%',
	'SELECT * INTO OUTFILE "/tmp/x" FROM t' => '%A?%INTO%A?%',
	'SELECT * FROM t INTO @a' => '%A?%INTO%A?%',
	'WITH x AS (SELECT 1) DELETE FROM t' => QueryValidator::SupportedQuery,
	'SELECT SLEEP(10)' => '%A?%SLEEP()%A?%',
	'SELECT BENCHMARK(1000000, MD5(1))' => '%A?%BENCHMARK()%A?%',
	'SELECT LOAD_FILE("/etc/passwd")' => '%A?%LOAD%A?%',
	'SELECT * FROM t FOR UPDATE' => '%A?%UPDATE%A?%',
	'SELECT * FROM t LOCK IN SHARE MODE' => '%A?%LOCK%A?%',
	'SELECT @a := 1' => "':=' is not allowed in a read-only query.",
	'SELECT 1 /*! ; DROP TABLE t */' => '%A?%Executable comments%A?%',
	"SELECT 'unterminated" => '%A?%unterminated%A?%',
	'CREATE TABLE x (a int)' => QueryValidator::SupportedQuery,
	'SET sql_mode = ""' => QueryValidator::SupportedQuery,
	'ANALYZE TABLE orders' => QueryValidator::SupportedQuery,
	'EXPLAIN ANALYZE SELECT 1' => QueryValidator::SupportedQuery,
	// texts that one server reads as a comment or a string and another as SQL
	'SELECT 1 --1; DROP TABLE t' => '%A?%known database platform%A?%',
	'SELECT 1 # x; DROP TABLE t' => '%A?%comments starting with #%A?%',
	"SELECT 'a\\', 1; DROP TABLE t -- '" => 'Only a single statement%A?%',
	'SELECT [x; DROP TABLE t]' => 'Only a single statement%A?%',
	"SELECT 'it\\'s'" => '%A?%unterminated%A?%',
	"SELECT set_config('transaction_read_only', 'off', false)" => '%A?%set_config()%A?%',
	'SELECT pg_catalog."pg_read_file" /* comment */ (NULL)' => '%A?%pg_read_file()%A?%',
	"SELECT pg_catalog.\"pg_read_file\" -- comment\r(NULL)" => '%A?%pg_read_file()%A?%',
	'SELECT pg_catalog."pg_read_file" /* outer /* inner */ still a comment */ (NULL)' => '%A?%Nested SQL comments%A?%',
	'SELECT [load_extension](NULL)' => '%A?%load_extension()%A?%',
	'SELECT pg_catalog.U&"pg\005fread_file"(NULL)' => '%A?%Unicode escape%A?%',
];
foreach ($refused as $sql => $message) {
	Assert::exception(fn() => $validator->validate($sql), InspectorException::class, $message);
}

Assert::exception(
	fn() => $validator->validate("SELECT pg_catalog.\"pg_read_file\" --comment\n(NULL)", Connection::PostgreSql),
	InspectorException::class,
	'%A?%pg_read_file()%A?%',
);
Assert::same('SELECT 1--1', $validator->validate('SELECT 1--1', Connection::MySql));
Assert::same('SELECT 1 # 3', $validator->validate('SELECT 1 # 3', Connection::PostgreSql));

// PostgreSQL strings that a validator unaware of them would read as code or code as a string
foreach ([
	"SELECT E'\\'', '\\'; COMMIT; DROP TABLE t; SELECT '\\', E'\\''" => 'Only a single statement%A?%',
	'SELECT $$\'$$; COMMIT; DROP TABLE t; SELECT $$\'$$' => '%A?%Dollar-quoted%A?%',
	'SELECT $x$\'$x$, pg_read_file(NULL), $x$\'$x$' => '%A?%Dollar-quoted%A?%',
] as $sql => $message) {
	Assert::exception(fn() => $validator->validate($sql, Connection::PostgreSql), InspectorException::class, $message);
}

Assert::same(
	"SELECT E'it''s', E'a\\'b', \$1 FROM t",
	$validator->validate("SELECT E'it''s', E'a\\'b', \$1 FROM t", Connection::PostgreSql),
);
Assert::same('SELECT a$b$c FROM t', $validator->validate('SELECT a$b$c FROM t', Connection::MySql));

// placeholders are replaced only outside literals, comments and quoted names
Assert::same(
	"SELECT 'a?', `b?`, 1 /* ? */ FROM t WHERE x = 'v' -- ?",
	$validator->bind("SELECT 'a?', `b?`, ? /* ? */ FROM t WHERE x = ? -- ?", ['1', "'v'"], Connection::MySql),
);
Assert::same('SELECT "a?" FROM t WHERE x = 1', $validator->bind('SELECT "a?" FROM t WHERE x = ?', ['1'], Connection::PostgreSql));
Assert::exception(
	fn() => $validator->bind('SELECT ? FROM t', [], Connection::MySql),
	InspectorException::class,
	'The query has 1 placeholder(s) but 0 parameter(s) were given.',
);
// a placeholder that is inside a string under one reading of the backslash is refused
Assert::exception(
	fn() => $validator->bind("SELECT 'a\\' ?, 'b' FROM t", ['1'], Connection::MySql),
	InspectorException::class,
	'The query must have the same structure with and without backslash escapes.',
);
