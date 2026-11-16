<?php declare(strict_types=1);

/**
 * Inspection tools through worker processes against the full fixture application:
 * SQLite database over Dibi, a presenter with templates, a real Tracy report from a failing Latte template.
 */

use Lupa\Database\DibiConnection;
use Lupa\Database\ReadOnlyRunner;
use Lupa\Worker\WorkerClient;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

skipOnNette2();


$runtimeDir = TempDir . '/runtime';
@mkdir($runtimeDir . '/log', 0777, true); // @ may exist
@mkdir($runtimeDir . '/temp/cache/latte', 0777, true); // @ may exist
putenv('FIXTURE_RUNTIME_DIR=' . $runtimeDir);


// database
$db = new SQLite3($runtimeDir . '/app.sqlite');
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, note TEXT DEFAULT 'fixture-note', password TEXT DEFAULT 'fixture-default')");
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(id), total INTEGER)');
$db->exec('CREATE INDEX orders_customer ON orders (customer_id)');
for ($i = 1; $i <= 5; $i++) {
	$db->exec("INSERT INTO customers VALUES ($i, 'Customer $i', 'customer$i@example.com', 'private note $i', 'hash$i')");
	$db->exec("INSERT INTO orders VALUES ($i, $i, " . ($i * 100) . ')');
}

$db->close();


// a Tracy report of an exception thrown in a compiled Latte template
final class FailingOrder
{
	public int $id = 7;


	public function getCustomer(): object
	{
		throw new RuntimeException('Customer of order 7 (jan@example.com) not found.');
	}
}

$latte = new Latte\Engine;
$latte->setTempDirectory($runtimeDir . '/temp/cache/latte');
try {
	$latte->renderToString(FixtureDir . '/src/Presenters/templates/Order/detail.latte', ['order' => new FailingOrder]);
	Assert::fail('The template should fail.');
} catch (RuntimeException $e) {
	$report = 'exception--2026-11-09--10-00--0123456789.html';
	(new Tracy\BlueScreen)->renderToFile(new LogicException('Rendering the order failed.', 0, $e), $runtimeDir . '/log/' . $report);
	file_put_contents(
		$runtimeDir . '/log/exception.log',
		"[2026-11-09 09-00-00] Older problem  @  CLI  @@  exception--old.html\n"
		. '[2026-11-09 10-00-00] RuntimeException: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
		. "  @  http://localhost/order/detail/7  @@  $report\n",
	);
}


$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-full.neon'), 20);
$call = function (string $tool, array $arguments = []) use ($client): array {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	assertResult($tool, $response->data);
	return $response->data;
};


// DI
$data = $call('di_services', ['type' => 'App\Model\OrderFacade']);
Assert::same(['orders'], array_column($data['services'], 'name'));
Assert::same('preferred', $data['services'][0]['autowired']);
Assert::false($data['services'][0]['created']);

$data = $call('di_service', ['name' => 'orders']);
Assert::same('App\Model\OrderFacade', $data['type']);
Assert::same('src/Model/OrderFacade.php', $data['classFile']);
Assert::same([['service' => 'database', 'via' => 'constructor', 'member' => null, 'type' => 'Dibi\Connection']], $data['dependencies']);
Assert::same('config-full.neon', $data['definedIn'][0]['file']);

$data = $call('di_service', ['name' => 'mailer']);
Assert::notContains('topsecret', json_encode($data));

$data = $call('di_dependents', ['name' => 'orders']);
Assert::same([['service' => 'mailer', 'type' => 'App\Model\Mailer', 'via' => 'method', 'member' => 'injectOrders']], $data['dependents']);
Assert::same(['src/Model/Mailer.php', 'src/Presenters/OrderPresenter.php'], array_column($data['references'], 'file'));

$data = $call('di_parameters', ['path' => 'mail']);
Assert::same(['from' => 'i***@***.com', 'password' => '***'], $data['value']);


// presenters
$data = $call('presenters');
Assert::same([['name' => 'Order', 'class' => 'App\Presenters\OrderPresenter', 'file' => 'src/Presenters/OrderPresenter.php', 'service' => 'orderPresenter']], $data['presenters']);

$data = $call('presenter', ['name' => 'Order']);
Assert::same(['default'], array_column($data['actions'], 'name'));
Assert::same(['detail'], array_column($data['renders'], 'name'));
Assert::same(['delete'], array_column($data['signals'], 'name'));
Assert::same(['grid'], array_column($data['components'], 'name'));
Assert::same(['filter' => 'open', 'token' => '***'], array_column($data['persistent'], 'default', 'name'));
Assert::notContains('fixture-presenter-literal', json_encode($data));
Assert::same(['orders'], array_column($data['injectedProperties'], 'name'));
Assert::contains("isAllowed('orders')", $data['checks'][0]['code']);
$templates = array_column($data['templates'], null, 'view');
ksort($templates);
Assert::same(['default', 'detail'], array_keys($templates));
Assert::same(['items'], $templates['default']['snippets']);

// without a directory of templates, nette/application 3.2 keeps them next to the presenter
$layout = TempDir . '/Presentation/Home';
mkdir($layout, 0777, true);
file_put_contents("$layout/HomePresenter.php", "<?php\nnamespace NewLayout;\nclass HomePresenter {}\n");
file_put_contents("$layout/default.latte", '{block content}{/block}');
file_put_contents("$layout/@layout.latte", '');
require "$layout/HomePresenter.php";
$method = new ReflectionMethod(Lupa\Nette\Toolkits\PresenterToolkit::class, 'findTemplates');
if (PHP_VERSION_ID < 80100) {
	$method->setAccessible(true);
}
Assert::same(
	[['file' => 'Presentation/Home/default.latte', 'view' => 'default', 'blocks' => ['content'], 'snippets' => []]],
	$method->invoke(
		(new ReflectionClass(Lupa\Nette\Toolkits\PresenterToolkit::class))->newInstanceWithoutConstructor(),
		new ReflectionClass(NewLayout\HomePresenter::class),
		new Lupa\Project(TempDir, VendorAutoload, TempDir . '/lupa.neon'),
	),
);


// Tracy and Latte
$data = $call('tracy_errors', ['since' => '2026-11-09 09:30']);
Assert::same(1, $data['total']);
Assert::same('exception', $data['entries'][0]['level']);
Assert::contains('j***@***.com', $data['entries'][0]['message']);
Assert::same($report, $data['entries'][0]['report']);
Assert::same($report, $data['reports'][0]['file']);

$data = $call('tracy_report', ['file' => $report]);
Assert::same(['LogicException', 'RuntimeException'], array_column($data['exceptions'], 'class'));
Assert::same('Rendering the order failed.', $data['exceptions'][0]['message']);
Assert::true(count($data['exceptions'][0]['source']) > 0, 'source of the outer exception');
$exception = $data['exceptions'][1];
Assert::same('Customer of order 7 (j***@***.com) not found.', $exception['message']);
$templateFrames = array_values(array_filter($exception['stack'], fn(array $frame) => $frame['template'] !== null));
Assert::true(count($templateFrames) > 0, 'a stack frame in the compiled template');
Assert::same('src/Presenters/templates/Order/detail.latte', $templateFrames[0]['template']['source']);
Assert::same(3, $templateFrames[0]['template']['line']);

Assert::null($data['agentReport']);

// the markdown report of Tracy 2.12+, written here by hand for older versions
file_put_contents(
	$runtimeDir . '/log/' . substr($report, 0, -5) . '.md',
	"# LogicException: Rendering the order failed.\n\n1. pay(\$order = array (1)\n   'email' => 'jan@example.com',\n\$password = 'made-up-value')\n",
);
$markdown = $call('tracy_report', ['file' => $report, 'agentReport' => true])['agentReport'];
Assert::contains('# LogicException: Rendering the order failed.', $markdown);
Assert::notContains('jan@example.com', $markdown);
Assert::notContains('made-up-value', $markdown);
$data = $call('tracy_report', ['file' => $report, 'agentReport' => true, 'agentReportLength' => 20]);
Assert::same('# LogicException: Re…', $data['agentReport']);

$compiled = $templateFrames[0]['file'];
$data = $call('latte_map_line', ['file' => basename($compiled), 'line' => $templateFrames[0]['line']]);
Assert::same(3, $data['line']);
Assert::contains('>    3:', $data['excerpt']);

$data = $call('latte_check', ['path' => 'src/Presenters/templates']);
Assert::same(7, $data['checked']);
// the fixture has no TemplateFactory, so the plain engine does not know {snippet} either
Assert::contains('src/Presenters/templates/Broken/syntax.latte', array_column($data['errors'], 'file'));
Assert::notContains('src/Presenters/templates/Order/detail.latte', array_column($data['errors'], 'file'));
// a filter of a whole block is checked by its name; Latte 2.10 lowercases filter names
Assert::same(['nosuchblockfilter', 'nosuchfilter'], array_map('strtolower', array_column($data['warnings'], 'filter')));


// database
$data = $call('db_tables');
Assert::same(['customers', 'orders'], array_column($data['tables'], 'name'));

$data = $call('db_table', ['table' => 'orders']);
Assert::same(['id', 'customer_id', 'total'], array_column($data['columns'], 'name'));
Assert::same([['orders_customer']], [array_column($data['indexes'], 'name')]);
Assert::same('customers', $data['foreignKeys'][0]['referencedTable']);
$data = $call('db_table', ['table' => 'customers']);
Assert::same('orders', $data['referencedBy'][0]['table']);
$relations = $call('db_relations');
Assert::same($relations['count'], count($relations['relations']));
Assert::contains(['orders', 'customers'], array_map(fn(array $key) => [$key['table'], $key['referencedTable']], $relations['relations']));
Assert::same($relations, $call('db_relations', ['table' => 'customers']));
Assert::same([], $call('db_relations', ['table' => 'nothing'])['relations']);
Assert::same(['***', '***'], array_slice(array_column($data['columns'], 'default'), -2));

$data = $call('db_query', ['sql' => 'SELECT * FROM customers ORDER BY id']);
Assert::same(3, $data['rowCount']);
Assert::true($data['truncated']);
Assert::same(
	['id' => 1, 'name' => 'Customer 1', 'email' => 'c***@***.com', 'note' => '***', 'password' => '***'],
	$data['rows'][0],
);

$data = $call('db_query', ['sql' => 'SELECT id, email, note FROM customers ORDER BY id DESC', 'connection' => 'netteDatabase']);
$rows = array_map(fn(array $row) => ['id' => (int) $row['id']] + $row, $data['rows']); // PDO SQLite returns strings before PHP 8.1
Assert::same([['id' => 5, 'email' => 'c***@***.com', 'note' => '***'], ['id' => 4, 'email' => 'c***@***.com', 'note' => '***'], ['id' => 3, 'email' => 'c***@***.com', 'note' => '***']], $rows);
Assert::same(['customers', 'orders'], array_column($call('db_tables', ['connection' => 'netteDatabase'])['tables'], 'name'));

foreach (['database', 'netteDatabase'] as $connection) {
	foreach ([
		'SELECT id, name, password AS p FROM customers ORDER BY id',
		'SELECT "password" AS exposed FROM customers',
		'SELECT [password] AS exposed FROM customers',
		"SELECT customers./**/'password' AS exposed FROM customers",
		"SELECT 1 --comment '\n, password AS exposed FROM customers --comment '",
		"SELECT '' AS password, password AS exposed FROM customers",
		'SELECT password, LENGTH(password) AS exposed FROM customers',
		'SELECT LENGTH(note) AS exposed FROM customers',
		'WITH sample (a, b, c, d, exposed) AS (SELECT * FROM customers) SELECT exposed FROM sample',
		'WITH sample (exposed) AS (SELECT id FROM customers) SELECT exposed FROM sample',
		"SELECT id FROM customers WHERE SUBSTR(password, 1, 1) = 'f'",
		'SELECT id FROM customers WHERE note IS NULL',
		'SELECT DISTINCT password FROM customers',
		'SELECT id, password FROM customers ORDER BY 2',
		'EXPLAIN SELECT password FROM customers',
	] as $sql) {
		$response = $client->execute('db_query', ['sql' => $sql, 'connection' => $connection]);
		Assert::false($response->ok, $sql);
		Assert::contains('Only direct SELECTs', $response->text, $sql);
	}
}

foreach ([
	'SELECT COUNT(id) AS n, MAX(name) AS m FROM customers',
	'SELECT c.name FROM customers c JOIN orders o USING (id)',
	'SELECT name FROM customers WHERE (name) GLOB (name)',
] as $sql) {
	Assert::hasKey('rows', $call('db_query', ['sql' => $sql]), $sql);
}

$data = $call('db_query', ['sql' => 'SELECT id, "password" FROM customers ORDER BY id', 'connection' => 'netteDatabase']);
Assert::same([1, '***'], [(int) $data['rows'][0]['id'], $data['rows'][0]['password']]);

$response = $client->execute('db_query', ['sql' => "SELECT 'first' AS id, 'second' AS id, 'third' AS label"]);
Assert::false($response->ok);
Assert::contains('duplicate column names', $response->text);
$data = $call('db_query', ['sql' => "SELECT 'first' AS id1, 'second' AS id2, 'third' AS label"]);
Assert::same(['id1' => 'first', 'id2' => 'second', 'label' => 'third'], $data['rows'][0]);

$response = $client->execute('db_query', ['sql' => 'DELETE FROM customers']);
Assert::false($response->ok);
Assert::contains('Only direct SELECTs', $response->text);

$data = $call('db_explain', ['sql' => 'SELECT * FROM orders WHERE customer_id = 1']);
Assert::contains('orders_customer', json_encode($data['plan']));


// the read-only transaction holds even without the validator
$connection = new DibiConnection(new Dibi\Connection(['driver' => 'sqlite3', 'database' => $runtimeDir . '/app.sqlite']));
Assert::exception(
	fn() => (new ReadOnlyRunner($connection, 5))->run(fn($connection) => $connection->execute('DELETE FROM customers')),
	Dibi\DriverException::class,
);
Assert::same(5, (new SQLite3($runtimeDir . '/app.sqlite'))->querySingle('SELECT COUNT(*) FROM customers'));
