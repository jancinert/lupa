<?php declare(strict_types=1);

$root = (string) getenv('LUPA_HTTP_TEST_PROJECT');
if ($root === '' || is_dir($root)) {
	throw new RuntimeException('The HTTP fixture needs a new isolated project directory.');
}

umask(0077);
mkdir($root, 0700, true);
mkdir($root . '/runtime', 0700);
$db = new PDO('sqlite:' . $root . '/app.sqlite');
$db->exec("CREATE TABLE users (id INTEGER, password_hash TEXT DEFAULT 'synthetic-http-secret')");
$db->exec("CREATE TABLE products (id INTEGER, name TEXT DEFAULT 'Public product')");
$db->exec("INSERT INTO products VALUES (1, 'Public product')");
file_put_contents($root . '/bootstrap.php', '<?php return ' . var_export([
	'parameters' => ['appDir' => $root],
	'databases' => ['main' => ['dsn' => 'sqlite:' . $root . '/app.sqlite']],
], true) . ';');
file_put_contents($root . '/lupa.neon', "inspector:\n\tframework: none\n\tbootstrap: bootstrap.php\n\taudit: runtime/audit.jsonl\n");
