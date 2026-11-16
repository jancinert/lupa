<?php declare(strict_types=1);

use Lupa\Guard\DatabaseTarget;
use Lupa\Guard\EnvironmentGuard;
use Lupa\GuardException;
use Lupa\Nette\DatabaseTargetFinder;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

skipOnNette2();


// host parsing
$target = new DatabaseTarget('db', 'Dibi\Connection', DatabaseTarget::SourceObject);
$target->setHost('p:MySQL:3307');
Assert::same(['mysql', 3307], [$target->host, $target->port]);
$target->setHost('[::1]:3306');
Assert::same('::1', $target->host);
$target->setHost('::1');
Assert::same('::1', $target->host);


// DSN parsing
$target = new DatabaseTarget('db', 'Nette\Database\Connection', DatabaseTarget::SourceObject);
$target->applyDsn('mysql:host=db.example.com;port=3310;dbname=shop');
Assert::same(['mysql', 'db.example.com', 3310, 'shop', false], [$target->driver, $target->host, $target->port, $target->database, $target->local]);

$target = new DatabaseTarget('db', 'Nette\Database\Connection', DatabaseTarget::SourceObject);
$target->applyDsn('sqlite:/var/app.db');
Assert::same([true, '/var/app.db', null], [$target->local, $target->database, $target->host]);

$target = new DatabaseTarget('db', 'Nette\Database\Connection', DatabaseTarget::SourceObject);
$target->applyDsn('mysql:unix_socket=/run/mysqld.sock;dbname=shop');
Assert::true($target->local);


// guard decisions
$guard = new EnvironmentGuard(['localhost', 'mysql', '*.local'], ['*_prod', 'shared_test']);
$create = function (?string $host, ?string $database, bool $resolved = true): DatabaseTarget {
	$target = new DatabaseTarget('database', 'Dibi\Connection', DatabaseTarget::SourceCompiled);
	$target->host = $host;
	$target->database = $database;
	$target->resolved = $resolved;
	return $target;
};

Assert::null($guard->findViolation($create('mysql', 'app_dev')));
Assert::null($guard->findViolation($create('db.local', 'app_dev')));
Assert::match("%a%host 'db.example.com', which is not in guard.allowedHosts", $guard->findViolation($create('db.example.com', 'app')));
Assert::match("%a%database 'shop_prod', denied by guard.deniedDatabases pattern '*_prod'", $guard->findViolation($create('mysql', 'shop_prod')));
Assert::match("%a%database 'Shared_Test'%a%", $guard->findViolation($create('mysql', 'Shared_Test')));
Assert::match('cannot determine where %a%', $guard->findViolation($create('mysql', 'app', false)));
Assert::match('%a%not in guard.allowedHosts', $guard->findViolation($create(null, 'app')));

$local = $create(null, 'shop_prod');
$local->local = true;
Assert::match('%a%denied%a%', $guard->findViolation($local));

$guard->check([$create('mysql', 'app_dev')]);
foreach ([
	'mysql:host=localhost;host=remote.invalid;dbname=app',
	'mysql:host=remote.invalid;unix_socket=/run/mysqld.sock;dbname=app',
	'mysql:host=remote.invalid;hostaddr=localhost;dbname=app',
	'mysql:host=remote.invalid;password=example;;host=localhost;dbname=app',
	'mysql:host=localhost;dbname=app;;dbname=app_prod;dbname=app',
	'pgsql:host=localhost host=remote.invalid;dbname=app',
] as $dsn) {
	$target = new DatabaseTarget('database', 'PDO', DatabaseTarget::SourceCompiled);
	$target->applyDsn($dsn);
	Assert::notNull($guard->findViolation($target), $dsn);
}

$target = new DatabaseTarget('database', 'PDO', DatabaseTarget::SourceCompiled);
$target->applyDsn('mysql:host=localhost;password=example;;host=remote.invalid;dbname=app');
Assert::null($guard->findViolation($target));

Assert::exception(
	fn() => $guard->check([$create('mysql', 'app_dev'), $create('remote', 'app')]),
	GuardException::class,
	"The application is not connected to a development environment:\n- service 'database' (Dibi\\Connection) connects to host 'remote'%A%",
);


// targets read from the compiled container, without creating the services
$container = compileContainer(FixtureDir . '/config.neon');
$targets = (new DatabaseTargetFinder)->find($container);
Assert::count(1, $targets);
Assert::same(
	['service' => 'database', 'type' => 'Dibi\Connection', 'driver' => 'mysqli', 'host' => 'db.local', 'port' => 3307, 'database' => 'app_dev', 'local' => false, 'resolved' => true, 'source' => 'compiled'],
	$targets[0]->toArray(),
);
Assert::false($container->isCreated('database'));

// A service remains registered even when the container does not export its type wiring.
$class = (new Nette\DI\ContainerLoader(TempDir . '/without-types', false))->load(function (Nette\DI\Compiler $compiler): void {
	$compiler->addExtension('di', new Nette\DI\Extensions\DIExtension);
	$compiler->addConfig([
		'di' => ['export' => ['types' => false]],
		'services' => [
			'remote' => new Nette\DI\Definitions\Statement(PDO::class, ['mysql:host=remote.invalid;dbname=app']),
			'postgres' => new Nette\DI\Definitions\Statement(Dibi\Connection::class, [[
				'driver' => 'postgre',
				'host' => 'localhost',
				'hostaddr' => '203.0.113.7',
				'dbname' => 'app',
			]]),
		],
	]);
}, 'without-types');
$hiddenContainer = new $class;
Assert::same([], $hiddenContainer->findByType(PDO::class));
$hiddenTargets = (new DatabaseTargetFinder)->find($hiddenContainer);
Assert::same(['postgres', 'remote'], array_column($hiddenTargets, 'service'));
Assert::same(['203.0.113.7', 'remote.invalid'], array_column($hiddenTargets, 'host'));
Assert::exception(fn() => $guard->check($hiddenTargets), GuardException::class, '%A?%203.0.113.7%A?%remote.invalid%A?%');
Assert::false($hiddenContainer->isCreated('postgres'));
Assert::false($hiddenContainer->isCreated('remote'));


// a created service is read from the object
$container->getService('database');
$targets = (new DatabaseTargetFinder)->find($container);
Assert::same(['object', 'db.local', 'app_dev'], [$targets[0]->source, $targets[0]->host, $targets[0]->database]);


// computed values stay unresolved, a missing host is the driver default, SQLite is local
$targets = (new DatabaseTargetFinder)->find(compileContainer(FixtureDir . '/config-dynamic.neon'));
$byName = array_combine(array_map(fn($target) => $target->service, $targets), $targets);
Assert::false($byName['database']->resolved);
Assert::same((ini_get('mysqli.default_host') ?: 'localhost'), $byName['defaultHost']->host);
Assert::true($byName['sqlite']->local);


// Redis clients of Kdyby
require __DIR__ . '/fixtures/stubs/RedisClient.php';
$targets = (new DatabaseTargetFinder)->find(compileContainer(FixtureDir . '/config-redis.neon'));
$byName = array_combine(array_map(fn($target) => $target->service, $targets), $targets);
Assert::same(['redis', 'redis', 6379, '2', true], [$byName['cache']->driver, $byName['cache']->host, $byName['cache']->port, $byName['cache']->database, $byName['cache']->resolved]);
Assert::same('127.0.0.1', $byName['defaults']->host);
Assert::true($byName['socket']->local);
Assert::false($byName['computed']->resolved);
Assert::false(isset($byName['forwarded'])); // checked as the service it returns
Assert::match("%a%connects to host 'redis', which is not in guard.allowedHosts", (new EnvironmentGuard(['localhost'], []))->findViolation($byName['cache']));


// values concatenated from a literal and a dynamic parameter stay unresolved; PDO services are checked too
$loader = new Nette\DI\ContainerLoader(TempDir . '/concat', false);
$class = $loader->load(function (Nette\DI\Compiler $compiler): void {
	$compiler->setDynamicParameterNames(['dbName', 'dbHost']);
	$compiler->loadConfig(FixtureDir . '/config-concat.neon');
}, 'concat');
$targets = (new DatabaseTargetFinder)->find(new $class(['dbName' => 'shop', 'dbHost' => 'db']));
$byName = array_combine(array_map(fn($target) => $target->service, $targets), $targets);
Assert::false($byName['concat']->resolved);
Assert::false($byName['concatDsn']->resolved);
Assert::false($byName['pdoDynamic']->resolved);
Assert::same(['db.example.com', 'shop'], [$byName['pdo']->host, $byName['pdo']->database]);
Assert::same('db.example.com', $byName['dsnIgnored']->host); // mysqli ignores the dsn key


// libpq connects to hostaddr
$target = new DatabaseTarget('db', 'PDO', DatabaseTarget::SourceCompiled);
$target->applyDsn('pgsql:host=localhost;hostaddr=10.0.0.5;dbname=app');
Assert::same('10.0.0.5', $target->host);

$target = new DatabaseTarget('db', 'PDO', DatabaseTarget::SourceCompiled);
$target->applyDsn('pgsql:host=localhost dbname=app\_prod');
Assert::same('app_prod', $target->database);
Assert::match("%a%database 'app_prod'%a%", $guard->findViolation($target));

// a plain number is a literal, not the start of an expression
Assert::same([true, 'db.example.com', 3306], [$byName['withPort']->resolved, $byName['withPort']->host, $byName['withPort']->port]);


// digits are recognized without the ctype extension, as ctype_digit() reads text
foreach (['0' => true, '3306' => true, '' => false, '-1' => false, '33 06' => false, "3306\n" => false, '１２' => false, '1e3' => false] as $value => $expected) {
	Assert::same($expected, Lupa\Scalar::isDigits((string) $value), var_export($value, true));
}
Assert::true(Lupa\Scalar::isDigits(3306));
Assert::false(Lupa\Scalar::isDigits(-1));
Assert::false(Lupa\Scalar::isDigits(null));
