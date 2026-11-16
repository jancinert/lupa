<?php declare(strict_types=1);

/**
 * Refusals the tools rely on: paths outside the project, database targets that cannot be read, unknown connections.
 */

use Lupa\Database\Connections;
use Lupa\Guard\DatabaseTarget;
use Lupa\Guard\EnvironmentGuard;
use Lupa\GuardException;
use Lupa\InspectorException;
use Lupa\Plain\PlainApplication;
use Lupa\Project;
use Lupa\Tools\AppContext;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// a path given by the model stays inside the project
$root = TempDir . '/project';
mkdir($root . '/src', 0777, true);
file_put_contents(TempDir . '/outside.txt', 'x');
$project = new Project($root, $root . '/vendor/autoload.php', $root . '/lupa.neon');

Assert::same(realpath($root . '/src'), $project->resolveInside('src'));
Assert::same(realpath($root), $project->resolveInside('.'));
Assert::exception(
	fn() => $project->resolveInside('../outside.txt'),
	InspectorException::class,
	"Path '../outside.txt' is outside the project.",
);
Assert::exception(
	fn() => $project->resolveInside(TempDir . '/outside.txt'),
	InspectorException::class,
	"Path '%a%' is outside the project.",
);
Assert::exception(fn() => $project->resolveInside('src/../..'), InspectorException::class, "Path 'src/../..' is outside the project.");
Assert::exception(fn() => $project->resolveInside('missing'), InspectorException::class, "Path 'missing' not found.");

// the project is found from a directory inside it
file_put_contents($root . '/lupa.neon', '');
Assert::same(realpath($root), realpath(Project::locate([], null, $root . '/src')->root));


// a target the guard cannot read is refused, whatever the allowed hosts are
$guard = new EnvironmentGuard(['*'], []);
$read = function (string $dsn): DatabaseTarget {
	$target = new DatabaseTarget('db', PDO::class, DatabaseTarget::SourceObject);
	$target->applyDsn($dsn);
	return $target;
};

foreach ([
	'oci:dbname=//db.example.com/app', // a driver the guard does not know
	"mysql:host=localhost\0;dbname=app",
	'mysql:host=localhost;host=db.example.com', // an option given twice
	'mysql:nonsense',
	'mysql:host=p:db.example.com', // the persistent prefix of mysqli is no PDO syntax
	'pgsql:service=production',
	'pgsql:host=localhost,db.example.com',
	"pgsql:host='unterminated",
] as $dsn) {
	Assert::false($read($dsn)->resolved, $dsn);
	Assert::exception(fn() => $guard->check([$read($dsn)]), GuardException::class, '%A%cannot determine where%A%');
}

Assert::true($read('mysql:host=localhost;dbname=app')->resolved);
Assert::true($read('mysql:unix_socket=/tmp/mysql.sock;dbname=app')->local);
Assert::same('db', $read('pgsql:host=localhost hostaddr=db dbname=app')->host); // the address wins over the name

// the configuration of a Dibi connection
$target = new DatabaseTarget('db', 'Dibi\Connection', DatabaseTarget::SourceObject);
$target->applyDibiConfig(['driver' => 'odbc', 'dsn' => 'made-up']);
Assert::false($target->resolved);
$target = new DatabaseTarget('db', 'Dibi\Connection', DatabaseTarget::SourceObject);
$target->applyDibiConfig(['driver' => 'mysqli', 'host' => 'db.example.com', 'database' => 'app', 'port' => 3307]);
Assert::same(['db.example.com', 3307, 'app', true], [$target->host, $target->port, $target->database, $target->resolved]);

$strict = new EnvironmentGuard(['localhost'], ['*_prod']);
Assert::match("%a%connects to host 'db.example.com', which is not in guard.allowedHosts", (string) $strict->findViolation($target));
Assert::match(
	"%a%uses database 'shop_prod', denied by guard.deniedDatabases pattern '*_prod'",
	(string) $strict->findViolation($read('mysql:host=localhost;dbname=shop_prod')),
);
Assert::null($strict->findViolation($read('mysql:host=LOCALHOST;dbname=shop')));


// a connection the application does not have
$application = new PlainApplication(['databases' => ['main' => ['dsn' => 'sqlite::memory:']]]);
$context = new AppContext($application, $project, new ToolCatalog([]), $application->getDatabaseTargets());
Assert::exception(
	fn() => (new Connections)->open($context, 'other'),
	InspectorException::class,
	"Connection 'other' not found; the application has: main.",
);
Assert::exception(
	fn() => (new Connections)->open(new AppContext(new PlainApplication([]), $project, new ToolCatalog([]), [])),
	InspectorException::class,
	'The application has no database connection.',
);
Assert::exception(
	fn() => new PlainApplication(['databases' => ['main' => ['user' => 'app']]]),
	InspectorException::class,
	"Database 'main' of the bootstrap file needs a 'dsn' for PDO or a 'driver' for Dibi.",
);
