<?php declare(strict_types=1);

/**
 * The tools against the fixture application booted by Nette\Configurator of Nette 2.4.
 */

use Lupa\Worker\WorkerClient;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

if (interface_exists(Nette\Routing\Router::class)) {
	Tester\Environment::skip('Needs Nette 2.4 installed instead of Nette 3.');
}


$runtimeDir = TempDir . '/runtime';
@mkdir($runtimeDir . '/log', 0777, true); // @ may exist
@mkdir($runtimeDir . '/temp/cache', 0777, true); // @ may exist
putenv('FIXTURE_RUNTIME_DIR=' . $runtimeDir);

$db = new SQLite3($runtimeDir . '/app.sqlite');
$db->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, note TEXT DEFAULT 'fixture-note', password TEXT)");
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(id), total INTEGER)');
$db->exec("INSERT INTO customers VALUES (1, 'Customer 1', 'customer1@example.com', 'private note', 'hash')");
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
@mkdir($runtimeDir . '/temp/cache/latte'); // @ may exist
$report = 'exception--2026-11-09--10-00--0123456789.html';
try {
	$latte->renderToString(FixtureDir . '/src/Presenters/templates/Order/detail.latte', ['order' => new FailingOrder]);
	Assert::fail('The template should fail.');
} catch (RuntimeException $e) {
	(new Tracy\BlueScreen)->renderToFile($e, $runtimeDir . '/log/' . $report);
}

$client = new WorkerClient(PHP_BINARY, BinFile, createFixtureProject('lupa-nette2.neon'), 20);
$call = function (string $tool, array $arguments = []) use ($client): array {
	$response = $client->execute($tool, $arguments);
	Assert::true($response->ok, "$tool: $response->text");
	return $response->data;
};


// the container of nette/di 2.4
$data = $call('app_info');
Assert::same(['dibi', 'database.default.connection'], array_column($data['databases'], 'service'));

$data = $call('di_services', ['type' => 'App\Model\OrderFacade']);
Assert::same(
	[['name' => 'orders', 'type' => 'App\Model\OrderFacade', 'tags' => [], 'autowired' => 'preferred', 'created' => false]],
	$data['services'],
);

$data = $call('di_service', ['name' => 'orders']);
Assert::same([['service' => 'dibi', 'via' => 'constructor', 'member' => null, 'type' => 'Dibi\Connection']], $data['dependencies']);
Assert::same('config-nette2.neon', $data['definedIn'][0]['file']);

$data = $call('di_dependents', ['name' => 'orders']);
Assert::same(['orderPresenter'], array_column($data['dependents'], 'service'));

$data = $call('di_parameters', ['path' => 'mail']);
Assert::same(['from' => 'i***@***.com', 'password' => '***'], $data['value']);
Assert::same('config-nette2.neon', $data['setIn'][0]['file']);


// presenters and the router of nette/application 2.4
Assert::same(['Legacy', 'Order'], array_column($call('presenters')['presenters'], 'name'));
Assert::same(['default'], array_column($call('presenter', ['name' => 'Order'])['actions'], 'name'));

$routes = $call('routes')['routes'];
Assert::same(
	['index.php', 'admin/<presenter>/<action>[/<id>]', 'order/<id \d+>', '<presenter>/<action>'],
	array_column($routes, 'mask'),
);
Assert::same([true, false, false, false], array_column($routes, 'oneWay'));
Assert::same([null, 'Admin', null, null], array_column($routes, 'module'));

$data = $call('route_match', ['url' => 'http://localhost/admin/users/edit/3']);
Assert::same(
	['Admin:Users', 'edit', ['id' => '3'], 1],
	[$data['presenter'], $data['action'], $data['parameters'], $data['route']['index']],
);
Assert::false($call('route_match', ['url' => 'http://localhost/a/b/c/d'])['matched']);
Assert::same('Admin:Users', $call('route_match', ['url' => '/admin/users/edit/3'])['presenter']);
Assert::same(
	'http://example.test/order/7',
	$call('link', ['destination' => 'Order:detail', 'parameters' => ['id' => 7], 'baseUrl' => 'http://example.test/'])['url'],
);
$response = $client->execute('link', ['destination' => 'Order:detail', 'parameters' => ['id' => 7]]);
Assert::same("The application does not know its address outside a web request; give 'baseUrl'.", $response->text);


// forms and permissions
$controls = array_column($call('form', ['service' => 'orderFormFactory'])['controls'], null, 'name');
Assert::same('Name', $controls['name']['caption']);
Assert::true($controls['name']['required']);
Assert::true($call('acl_check', ['role' => 'admin', 'resource' => 'orders'])['allowed']);
Assert::same(['*' => false, 'edit' => false, 'read' => true], $call('acl_matrix', ['resource' => 'orders'])['matrix']['guest']);


// the database through Dibi and through nette/database 2.4
foreach ([null, 'database.default.connection'] as $connection) {
	$arguments = $connection === null ? [] : ['connection' => $connection];
	$data = $call('db_query', ['sql' => 'SELECT name, email, note FROM customers'] + $arguments);
	Assert::same([['name' => 'Customer 1', 'email' => 'c***@***.com', 'note' => '***']], $data['rows']);
	Assert::same(['customers', 'orders'], array_column($call('db_tables', $arguments)['tables'], 'name'));
	Assert::same('customers', $call('db_relations', $arguments)['relations'][0]['referencedTable']);
}


// templates and logs
$data = $call('latte_check', ['path' => 'src/Presenters/templates']);
Assert::contains('src/Presenters/templates/Broken/syntax.latte', array_column($data['errors'], 'file'));
Assert::notContains('src/Presenters/templates/Order/detail.latte', array_column($data['errors'], 'file'));

$exception = $call('tracy_report', ['file' => $report])['exceptions'][0];
Assert::same('Customer of order 7 (j***@***.com) not found.', $exception['message']);
$frames = array_values(array_filter($exception['stack'], fn(array $frame) => $frame['template'] !== null));
Assert::true(count($frames) > 0, 'a stack frame in the compiled template');
Assert::same('src/Presenters/templates/Order/detail.latte', $frames[0]['template']['source']);
Assert::same(3, $frames[0]['template']['line']);

$data = $call('latte_map_line', ['file' => basename($frames[0]['file']), 'line' => $frames[0]['line']]);
Assert::same(3, $data['line']);
Assert::contains('>    3:', $data['excerpt']);

// rendering: the page, a write refused by the read-only session, a recorded e-mail, a logged-in user
$data = $call('render', ['presenter' => 'Legacy']);
Assert::same([200, 'Legacy page'], [$data['status'], $data['title']]);
Assert::contains('<p>c***@***.com</p>', $data['html']);
Assert::same(['mail.mailer', 'http.request'], $data['safety']['replaced']);
Assert::same(['dibi', 'database.default.connection'], $data['safety']['databases']['readOnly']);

$data = $call('render', ['url' => 'http://localhost/legacy/write']);
Assert::same(500, $data['status']);
Assert::contains('the page tried to write to the read-only database', $data['summary']);
Assert::same(0, (new SQLite3($runtimeDir . '/app.sqlite'))->querySingle('SELECT COUNT(*) FROM orders'));

$data = $call('render', ['presenter' => 'Legacy', 'action' => 'mail']);
Assert::same([['to' => ['j***@***.com'], 'subject' => 'Order received']], $data['mails']);

Assert::same('nobody', $call('render', ['presenter' => 'Legacy', 'action' => 'who'])['html']);
Assert::same('7:editor', $call('render', ['presenter' => 'Legacy', 'action' => 'who', 'roles' => ['editor'], 'userId' => 7])['html']);
Assert::same(403, $call('render', ['presenter' => 'Order'])['status']);
Assert::same(200, $call('render', ['presenter' => 'Order', 'roles' => ['admin']])['status']);
Assert::same(404, $call('render', ['presenter' => 'Legacy', 'action' => 'missing'])['status']);


// a configuration that stops compiling: the container of the last build, compiled by the real Configurator,
// which detects the production mode on the command line
$broken = TempDir . '/broken';
mkdir($broken . '/temp', 0777, true);
mkdir($broken . '/var/log', 0777, true);
file_put_contents("$broken/var/log/exception.log", "[2026-11-12 10-00-00] RuntimeException: Made-up failure.  @  CLI\n");
file_put_contents("$broken/lupa.neon", "inspector:\n\tbootstrap: boot.php\n\taudit: audit.jsonl\n");
$enableTracy = method_exists(Nette\Configurator::class, 'enableTracy') ? 'enableTracy' : 'enableDebugger'; // nette/bootstrap 2.4.0
file_put_contents(
	"$broken/boot.php",
	"<?php\n\$configurator = new Nette\Configurator;\n\$configurator->setTempDirectory(__DIR__ . '/temp');\n"
	. "\$configurator->$enableTracy(__DIR__ . '/var/log');\n\$configurator->addConfig(__DIR__ . '/app.neon');\nreturn \$configurator;\n",
);
$valid = "application:\n\tscanDirs: false\nparameters:\n\tcolor: red\nservices:\n\tthing: stdClass\n";
$client = new WorkerClient(PHP_BINARY, BinFile, new Lupa\Project($broken, VendorAutoload, "$broken/lupa.neon"), 20);

file_put_contents("$broken/app.neon", $valid);
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::true($response->ok, $response->text);
Assert::same('red', $response->data['value']);
Assert::false(isset($response->data['_warning']));

$response = $client->execute('tracy_errors', ['level' => 'exception']); // no parameter names the directory of the log
Assert::true($response->ok, $response->text);
Assert::same(['RuntimeException: Made-up failure.'], array_column($response->data['entries'], 'message'));

sleep(1); // the Configurator notices a change by the modification time
file_put_contents("$broken/app.neon", str_replace(['red', 'stdClass'], ['blue', 'Missing\Service'], $valid));
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::true($response->ok, $response->text);
Assert::same('red', $response->data['value']);
Assert::contains('does not compile', $response->data['_warning']);

sleep(1);
file_put_contents("$broken/app.neon", str_replace('red', 'green', $valid));
$response = $client->execute('di_parameters', ['path' => 'color']);
Assert::same('green', $response->data['value']);
Assert::false(isset($response->data['_warning']));
