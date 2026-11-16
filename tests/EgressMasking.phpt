<?php declare(strict_types=1);

use Lupa\Masking;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$masking = new Masking(['birthDate'], ['contacts' => ['note']]);
Assert::same('{"password":"***"}', $masking->maskText('{"pa\u0073sword":"synthetic-secret"}'));
Assert::same('Failure: {"password":"***"}', $masking->maskText('Failure: {"password":{"value":"synthetic-secret"}}'));
Assert::same('password=***; public=visible', $masking->maskText('password={value:"synthetic-secret"}; public=visible'));
Assert::same('Failure: {"password":***', $masking->maskText('Failure: {"password":"synthetic-secret"'));
Assert::same('{"note":"***"}', $masking->maskText('{"note":"synthetic-note"}'));
Assert::same(
	'{"wrapper":"{\\"password\\":\\"***\\"}"}',
	$masking->maskText('{"wrapper":"{\\"pa\\\\u0073sword\\":\\"synthetic-secret\\"}"}'),
);
foreach (['pa%73sword', 'pa%2573sword', 'nested%5Bpassword%5D'] as $key) {
	Assert::same(
		'https://example.test/?' . $key . '=***&id=5',
		$masking->maskText('https://example.test/?' . $key . '=synthetic-secret&id=5'),
	);
}
Assert::same('https://example.test/#pa%73sword=***', $masking->maskText('https://example.test/#pa%73sword=synthetic-secret'));
Assert::same('https://example.test/?cfg:pa%73sword=***', $masking->maskText('https://example.test/?cfg:pa%73sword=synthetic-secret'));
Assert::same(
	'https://example.test/?email=p%2A%2A%2A%40%2A%2A%2A.test',
	$masking->maskText('https://example.test/?email=person%40example.test'),
);
Assert::same('https://example.test/?name=Public%20Name&id=5', $masking->maskText('https://example.test/?name=Public%20Name&id=5'));
Assert::same('plain public text', $masking->maskText('plain public text'));
Assert::same('password=***', $masking->maskText('password="' . str_repeat('Z', 100000) . '"'));
Assert::same('***', $masking->maskText(str_repeat('x', 1_048_577)));
Assert::same('***', $masking->maskText("password=secret\xFF"));

// other encodings: non-breaking spaces, entities in text, JSON escapes, percent-encoding in a path, pluses in a query
Assert::same('Tel. *** *** 456', $masking->maskText("Tel. 777\u{a0}123\u{a0}456"));
Assert::same('Tel. *** *** 456', $masking->maskText('Tel. 777&nbsp;123&nbsp;456'));
Assert::same(
	'Callback https://admin:***@10.0.0.5/x failed',
	$masking->maskText('Callback https:\/\/admin:synthetic-secret@10.0.0.5\/x failed'),
);
Assert::same('http://localhost/users/j***@***.com/edit', $masking->maskText('http://localhost/users/jan.novak%40example.com/edit'));
Assert::same('http://localhost/?phone=+*** *** *** 456', rawurldecode($masking->maskText('http://localhost/?phone=%2B420+777+123+456')));
Assert::same('http://localhost/?q=***&page=2', rawurldecode($masking->maskText('http://localhost/?q=%E8erven%E9&page=2'))); // a legacy encoding
Assert::same('card **** **** **** 1111', $masking->maskText("card 4111\u{2003}1111\u{2003}1111\u{2003}1111"));
Assert::same('j***@***.com', $masking->maskText('jan.novak&#64example.com')); // browsers read entities without a semicolon too
Assert::same('*** *** 456', $masking->maskText('603&nbsp123&nbsp456'));
Assert::same('***', $masking->maskText('&' . str_repeat('amp;', 64000))); // crafted nesting, decoded round by round, would exhaust memory
Assert::same('password: ***', $masking->maskText('password: ***&synthetic-secret')); // only looks like a masked query parameter
Assert::same('https://example.test/?password=***&id=5', $masking->maskText('https://example.test/?password=synthetic-secret&id=5'));
$long = 'memo: "' . str_repeat('a', 9000) . '" password: synthetic-secret';
Assert::same(substr($long, 0, -16) . '***', $masking->maskText($long));

// more shapes of secrets, base64, quoted-printable and an escaped backslash
foreach ([
	'Cookie PHPSESSID=synthetic-secret',
	'redis://:synthetic-secret@cache:6379',
	'//admin:synthetic-secret@host/x',
	'connect root:synthetic-secret@db.local',
	'mysql -u root -psynthetic-secret shop',
	'api key: synthetic-secret',
	'Access token = synthetic-secret',
	'set password to synthetic9secret',
	"Array\n(\n    [password] => synthetic-secret\n)",
	"array(1) {\n  [\"password\"]=>\n  string(16) \"synthetic-secret\"\n}",
	'["password", "synthetic-secret"]',
	'heslo: synthetic-secret',
	'{"password":"synthetic-secret"', // a document cut short
	'payload ' . base64_encode('{"email":"synthetic.person@example.com"}'),
	'token ' . rtrim(strtr(base64_encode('synthetic.person@example.com'), '+/', '-_'), '='),
	'Kontakt: synthetic.person=40example.com',
	'synthetic.person\\\\u0040example.com',
	'data=' . base64_encode('synthetic.person@example.com'), // base64 after = and in a path
	'http://x/confirm/' . rtrim(strtr(base64_encode('synthetic.person@example.com'), '+/', '-_'), '='),
	chunk_split(base64_encode(str_repeat('x', 20) . ' your login is synthetic.person@example.com, regards'), 76, "\r\n"),
	'[password:protected] => synthetic-secret',
	"Array\n(\n    [password] => Array\n        (\n            [0] => synthetic-secret\n        )\n\n)",
	"array(1) {\n  [\"token\"]=>\n  array(1) {\n    [0]=>\n    string(16) \"synthetic-secret\"\n  }\n}",
	"[password] => synthetic-first\nsynthetic-second line\n    [user] => jan",
	'user:synthetic-secret@db.local',
	"mysql -u root -p'synthetic-secret' shop",
	'sqlplus scott/synthetic-secret@orcl',
	'[{"name":"password","value":"synthetic-secret"}]',
	'The password was changed to synthetic9secret.',
] as $text) {
	Assert::notContains('synthetic', $masking->maskText($text), $text);
}

Assert::same(
	"Array\n(\n    [password] => ***\n    [user] => jan\n)",
	$masking->maskText("Array\n(\n    [password] => Array\n        (\n            [0] => synthetic-secret\n        )\n\n    [user] => jan\n)"),
);
Assert::same('host:3306@db', $masking->maskText('host:3306@db')); // a port is no password
Assert::same('[[ link ]] wiki', $masking->maskText('[[ link ]] wiki'));
Assert::same([['Password reset', 'sent']], $masking->maskData([['Password reset', 'sent']])); // no field name
$start = microtime(true);
$masking->maskText(str_repeat('mysql  ', 150000)); // each command scanned to the end of the line
$masking->maskText(str_repeat("[password] => a\n", 30000)); // each key spliced the whole list of lines
$masking->maskText(str_repeat("[\"password\"]=>\nstring(3) \"a\"\n", 10000));
$masking->maskText(chunk_split(base64_encode(str_repeat("\xAB\xCD\xEF", 40000)), 76, "\r\n")); // retried from each offset
$masking->maskText(str_repeat('abcdefghijklmnop/', 36000));
Assert::true(microtime(true) - $start < getTimeLimit(5));
Assert::same(1_000_008, strlen($masking->maskText('password' . str_repeat(' ', 1_000_000)))); // no failure of PCRE

// dumps on one line, after a prefix, of Xdebug; a date and public dumps stay
foreach ([
	'Array ( [password] => synthetic-secret [name] => Bob )',
	'[2026-11-12]     [password] => synthetic-secret',
	"array(1) {\n  'password' =>\n  string(16) \"synthetic-secret\"\n}",
] as $text) {
	Assert::notContains('synthetic', $masking->maskText($text), $text);
}

Assert::same('token na 2026-11-12 vypršel', $masking->maskText('token na 2026-11-12 vypršel'));
Assert::same(
	"Array\n(\n    [name] => Jan\n    [city] => Praha\n)",
	$masking->maskText("Array\n(\n    [name] => Jan\n    [city] => Praha\n)"),
);

Assert::same('mailto:i***@***.com', $masking->maskText('mailto:info@example.com')); // as an e-mail, its domain hidden too
Assert::same('MAILTO:i***@***.com', $masking->maskText('MAILTO:info@example.com'));
Assert::same('root:***@localhost', $masking->maskText('root:synthetic-secret@localhost'));
Assert::same('root:***@***.test', $masking->maskText('root:synthetic-secret@example.test'));
Assert::same('json {"a":"x\/y"} j***@***.com', $masking->maskText('json {"a":"x\/y"} jan.novak@example.com'));
$start = microtime(true);
foreach (['a=', 'a:', '?a=b&'] as $repeated) { // took quadratic time, or a copy for each level
	$masking->maskText(str_repeat($repeated, 100000));
}

Assert::true(microtime(true) - $start < getTimeLimit(10));

// legitimate text stays as it is
foreach ([
	'[2026-11-12 10:00] ERROR Import failed',
	'[1] foo [2] bar',
	'{"a":1} and more',
	'hash d41d8cd98f00b204e9800998ecf8427e',
	'http://localhost/?limit=40&offset=20',
	'Password is required and must have 8 characters',
	'Start 10:30 at office',
	"Cena 1\u{a0}250 Kč",
	'Moved to https:\/\/example.test\/orders',
	'SQLSTATE[42S22]: Column not found',
	'Latte: missing ] after [1, 2',
	'http://localhost/?q=red+shoes&page=2',
	'C++ code a+b',
] as $text) {
	Assert::same($text, $masking->maskText($text));
}

$keys = $masking->maskData(['person@example.test' => 1, 'p***@***.test' => 2, 'p.other@example.test' => 3]);
Assert::same(3, count($keys));
Assert::same([1, 2, 3], array_values($keys));
Assert::notContains('person@example.test', json_encode($keys));
Assert::notContains('p.other@example.test', json_encode($keys));
Assert::same(
	['author' => 'Jan', 'enabled' => false, 'id' => 123_456_789],
	$masking->maskData(['author' => 'Jan', 'enabled' => false, 'id' => 123_456_789]),
);
Assert::same(['person@example.test' => 1], (new Masking([], [], []))->maskData(['person@example.test' => 1]));

if (class_exists(DOMDocument::class)) {
	$html = $masking->maskHtml('<div><input value="synthetic-secret" name="api_key"><textarea name="password">synthetic-secret</textarea><meta name="csrf-token" content="synthetic-secret"><p>person&#64;example.test</p><input name="author" value="Public"></div>');
	Assert::notContains('synthetic-secret', $html);
	Assert::notContains('person&#64;example.test', $html);
	Assert::contains('p***@***.test', $html);
	Assert::contains('value="Public"', $html);
	// libxml 2.14 drops an unfinished tag, and a page left with nothing is hidden as a whole
	Assert::contains(
		$masking->maskHtml('<input name="password" value="unterminated>'),
		['<input name="password" value="***">', '***'],
	);
	// pages with ordinary parse errors stay readable and in UTF-8; processing instructions and spaced phones are masked too
	Assert::same('<p>Tom &amp; Jerry – Objednávka</p>', $masking->maskHtml('<p>Tom & Jerry &ndash; Objednávka</p>'));
	Assert::same('<p>Tel. *** *** 456</p>', $masking->maskHtml('<p>Tel. 777&nbsp;123&nbsp;456</p>'));
	Assert::contains( // libxml 2.14 reads a processing instruction as a comment
		$masking->maskHtml('<p><?note jan.novak@example.com?></p>'),
		['<p><?note j***@***.com?></p>', '<p><!--?note j***@***.com?--></p>'],
	);
	// data in names of tags, which the parser would cut, hides the page; a value next to its label is hidden
	Assert::same('***', $masking->maskHtml('<div jan.novak@example.com="1">x</div>'));
	Assert::same('***', $masking->maskHtml('<!DOCTYPE jan.novak@example.com><p>x</p>'));
	Assert::same(
		'<table><tr><th>Password</th><td>***</td></tr></table>',
		$masking->maskHtml('<table><tr><th>Password</th><td>synthetic-secret</td></tr></table>'),
	);
	Assert::same('<dl><dt>Heslo</dt><dd>***</dd></dl>', $masking->maskHtml('<dl><dt>Heslo</dt><dd>synthetic-secret</dd></dl>'));
	Assert::same(
		'<label for="f1">Token</label><input id="f1" name="f1" value="***">',
		$masking->maskHtml('<label for="f1">Token</label><input id="f1" name="f1" value="synthetic-secret">'),
	);
	// a label of public data, a NUL byte and an entity without a semicolon stay readable
	Assert::same(
		'<table><tr><th>Author</th><td>Jan</td></tr></table>',
		$masking->maskHtml('<table><tr><th>Author</th><td>Jan</td></tr></table>'),
	);
	Assert::same('<p>first</p><p>second</p>', $masking->maskHtml("<p>first</p>\0<p>second</p>"));
	Assert::same('<p>Tom &amp; Jerry</p>', $masking->maskHtml('<p>Tom &amp Jerry</p>'));
	// labels in a header row, before an empty cell, in bold, around a control; a > inside an attribute value
	foreach ([
		'<table><tr><th>User</th><th>Password</th></tr><tr><td>jan</td><td>synthetic-secret</td></tr></table>',
		'<table><tr><th>Password</th><td></td><td>synthetic-secret</td></tr></table>',
		'<dl><dt>Heslo</dt><dd>a</dd><dd>synthetic-secret</dd></dl>',
		'<p><strong>Password:</strong> synthetic-secret</p>',
		'<p><b>API key</b><br>synthetic-secret</p>',
		'<label>Password <input value="synthetic-secret"></label>',
		'<div id="f1">a</div><label for="f1">Token</label><input id="f1" value="synthetic-secret">',
		'<div title="a>b" synthetic.person@example.com>x</div>',
	] as $html) {
		Assert::notContains('synthetic', $masking->maskHtml($html), $html);
	}

	// public headers and a link with & stay; a page of many unclosed tags takes linear time, also in PHP 7.4
	Assert::same(
		'<table><tr><th>Passenger</th><td>Jan</td></tr><tr><th>Hash</th><td>abc123</td></tr></table>',
		$masking->maskHtml('<table><tr><th>Passenger</th><td>Jan</td></tr><tr><th>Hash</th><td>abc123</td></tr></table>'),
	);
	Assert::same(
		'<a href="https://example.test/?a=1&amp;b=2">link</a>',
		$masking->maskHtml('<a href="https://example.test/?a=1&b=2">link</a>'),
	);
	$start = microtime(true);
	$masking->maskHtml(str_repeat('<p>', 40000));
	$masking->maskHtml('<table><tr>' . str_repeat('<th>Password</th>', 5000) . '</tr></table>'); // a pass for each header
	$masking->maskHtml('<table><tr>' . str_repeat('<th>Password</th>', 50) . '</tr>' . str_repeat('<tr>' . str_repeat('<td>1</td>', 50) . '</tr>', 400) . '</table>');
	Assert::true(microtime(true) - $start < getTimeLimit(5));

	// a label wrapped in its cell, a header over two columns; a heading or a link is no label of what follows
	foreach ([
		'<table><tr><th><span>Password</span></th><td>synthetic-secret</td></tr></table>',
		'<table><tr><th>User</th><th><a href="?sort=p">Password</a></th></tr><tr><td>jan</td><td>synthetic-secret</td></tr></table>',
		'<table><tr><th><b>Password</b>:</th><td>synthetic-secret</td></tr></table>',
		'<table><tr><th colspan="2">Password</th></tr><tr><td>a</td><td>synthetic-secret</td></tr></table>',
	] as $html) {
		Assert::notContains('synthetic', $masking->maskHtml($html), $html);
	}

	Assert::same('<h2>Secret</h2><p>The secret garden.</p>', $masking->maskHtml('<h2>Secret</h2><p>The secret garden.</p>'));
	Assert::same(
		'<table><tr><th colspan="2">Password</th></tr><tr><td>***</td><td>***</td></tr></table>',
		$masking->maskHtml('<table><tr><th colspan="2">Password</th></tr><tr><td>a</td><td>b</td></tr></table>'),
	);
} else {
	Assert::same('***', $masking->maskHtml('<input name="password" value="synthetic-secret">'));
}

$target = new Lupa\Guard\DatabaseTarget('test', PDO::class, Lupa\Guard\DatabaseTarget::SourceObject);
$target->applyDsn('mysql:host=127.0.0.1;dbname=0');
Assert::notNull((new Lupa\Guard\EnvironmentGuard(['127.0.0.1'], ['0']))->findViolation($target));
Assert::null((new Lupa\Guard\EnvironmentGuard(['127.0.0.1'], ['production']))->findViolation($target));

class EgressSecretConstructor
{
	public function __construct(string $password)
	{
	}
}

$factory = new Lupa\Nette\FactoryCodeMasker($masking);
$interpolated = <<<'PHP'
	return new EgressSecretConstructor("$prefix(" . 'synthetic-secret');
	PHP;
Assert::same("return new EgressSecretConstructor('***');", $factory->mask($interpolated, EgressSecretConstructor::class));
$interpolated = <<<'PHP'
	return new EgressSecretConstructor("${foo}synthetic-secret");
	PHP;
Assert::same("return new EgressSecretConstructor('***');", $factory->mask($interpolated, EgressSecretConstructor::class));
Assert::same(
	"return new EgressSecretConstructor('***');",
	$factory->mask("return new EgressSecretConstructor('synthetic-' . 'factory-secret');", EgressSecretConstructor::class),
);
Assert::same(
	"return new EgressSecretConstructor('***');",
	$factory->mask("return new EgressSecretConstructor(strtolower('synthetic-secret'));", EgressSecretConstructor::class),
);

// nette/http 2.4.0 calls each(), deprecated since PHP 7.2; unlike the test runner, a worker does not fail on it
$reporting = error_reporting(E_ALL & ~E_DEPRECATED);
$form = new Nette\Forms\Form;
$control = $form->addText('api_key')->setDefaultValue('synthetic-form-secret');
$control->addRule(Nette\Forms\Form::EQUAL, 'Invalid', 'synthetic-form-secret');
$tool = new Lupa\Nette\Toolkits\FormToolkit($masking, new Lupa\Database\Connections);
$method = new ReflectionMethod($tool, 'describeControl');
if (PHP_VERSION_ID < 80100) {
	$method->setAccessible(true);
}
$data = $method->invoke($tool, $control, $form);
Assert::same('***', $data['value']);
Assert::same('***', $data['rules'][0]['argument']);
$public = $form->addText('author')->addRule(Nette\Forms\Form::MAX_LENGTH, null, 100);
$data = $method->invoke($tool, $public, $form);
Assert::same(100, $data['rules'][0]['argument']);
error_reporting($reporting);

$container = new Nette\DI\Container(['tempDir' => TempDir, 'logDir' => TempDir]);
$context = new Lupa\Tools\AppContext(
	new Lupa\Nette\NetteApplication($container),
	createFixtureProject(),
	new Lupa\Tools\ToolCatalog([]),
	[],
);
file_put_contents(TempDir . '/privacy.log', "[2026-11-12 10-00-00] Public status password=synthetic-log-secret  @  CLI\n");
$logs = new Lupa\Toolkits\TracyToolkit($masking);
Assert::same(1, $logs->call('tracy_errors', ['search' => 'Public status'], $context)->data['total']);
Assert::same(0, $logs->call('tracy_errors', ['search' => 'synthetic-log-secret'], $context)->data['total']);

// the CLI request of a report is masked before it is shortened, so the cut cannot leave a part of an e-mail
file_put_contents(
	TempDir . '/exception--2026-11-12--10-00--cli0000001.html',
	'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
	. '<section class="tracy-section tracy-section--error"><p>RuntimeException</p><h1><span>Import failed.</span></h1></section>'
	. '<section class="tracy-section"><h2>CLI request</h2><div><pre>php bin/console app:import ' . str_repeat('y', 250)
	. ' jan.novak@example.com</pre></div></section></body></html>',
);
if (class_exists(DOMDocument::class)) {
	$request = $logs->call('tracy_report', ['file' => 'exception--2026-11-12--10-00--cli0000001.html'], $context)->data['request'];
	Assert::notContains('novak', $request);
	Assert::true(Lupa\Utf8::length($request) <= 300);
} else {
	Assert::exception(
		fn() => $logs->call('tracy_report', ['file' => 'exception--2026-11-12--10-00--cli0000001.html'], $context),
		Lupa\InspectorException::class,
		'Reading Tracy reports requires the PHP extension dom.',
	);
}
mkdir(TempDir . '/cache/latte', 0777, true);
$cache = new Nette\Caching\Cache(new Nette\Caching\Storages\FileStorage(TempDir . '/cache'), 'Privacy');
$cache->save('document', '{"pa\u0073sword":"synthetic-cache-secret"}');
$cache->save('dictionary', ['person@example.test' => 1]);
$cacheTool = new Lupa\Nette\Toolkits\CacheToolkit($masking);
Assert::same('{"password":"***"}', $cacheTool->call('cache_get', ['namespace' => 'Privacy', 'key' => 'document'], $context)->data['value']);
Assert::same(
	['p***@***.test' => 1],
	$cacheTool->call('cache_get', ['namespace' => 'Privacy', 'key' => 'dictionary'], $context)->data['value'],
);
file_put_contents(TempDir . '/source.latte', 'password="synthetic-latte-secret"');
file_put_contents(TempDir . '/cache/latte/privacy.php', "<?php\n/** source: " . TempDir . "/source.latte */\n/* line 1 */\n");
Assert::same(
	'>    1: password=***',
	(new Lupa\Nette\Toolkits\LatteToolkit($masking))->call(
		'latte_map_line',
		['file' => 'privacy.php', 'line' => 3],
		$context,
	)->data['excerpt'],
);

$permission = new Nette\Security\Permission;
$permission->addRole('user');
$permission->addRole('authenticated', 'user');
$permission->addResource('orders');
$permission->allow('authenticated', 'orders', 'read');
$permission->addResource('customer:alice@example.test');
$provider = new class ($permission) implements Lupa\Nette\PermissionProvider {
	private Nette\Security\Permission $permission;


	public function __construct(Nette\Security\Permission $permission)
	{
		$this->permission = $permission;
	}


	public function getPermission(Nette\DI\Container $container): Nette\Security\Permission
	{
		return $this->permission;
	}
};
$acl = (new Lupa\Nette\Toolkits\AclToolkit($provider, $masking))->call('acl_roles', [], $context)->data;
// A value after a colon also has the shape of credentials, so its first letter is hidden too.
Assert::same(['orders' => null, 'customer:***@***.test' => null], $acl['resources']);
Assert::same(['user'], $acl['roles']['authenticated']);
$matrix = (new Lupa\Nette\Toolkits\AclToolkit($provider, $masking))->call('acl_matrix', ['resource' => 'orders'], $context)->data;
Assert::same(true, $matrix['matrix']['authenticated']['read']);
Assert::same(
	['roles' => ['authenticated' => '***']],
	(new Masking(['authenticated']))->maskArray(['roles' => ['authenticated' => ['user']]], ['roles']),
);

$command = new Symfony\Component\Console\Command\Command('probe');
$command->addOption('password', null, Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Secret', 'synthetic-console-secret');
$command->addOption('author', null, Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Public', 'Jan');
$console = new Lupa\Nette\Toolkits\ConsoleToolkit($masking);
$method = new ReflectionMethod($console, 'describeDefinition');
if (PHP_VERSION_ID < 80100) {
	$method->setAccessible(true);
}
$data = $method->invoke($console, $command->getDefinition());
Assert::same(['***', 'Jan'], array_column($data['options'], 'default'));

$directory = TempDir . '/worker';
mkdir($directory);
file_put_contents(
	$directory . '/lupa.neon',
	"inspector:\n\tframework: none\n\tbootstrap: boot.php\n\taudit: audit.jsonl\nmasking:\n\tkeys: [birthDate]\n",
);
// only the directories of a bootstrap without a framework are shown, as a secret may be under any name
file_put_contents(
	$directory . '/boot.php',
	'<?php return ["parameters" => ["logDir" => __DIR__, "password" => "synthetic-worker-secret", "stripe" => "synthetic-stripe-key"]];',
);
$project = new Lupa\Project($directory, VendorAutoload, $directory . '/lupa.neon');
$client = new Lupa\Worker\WorkerClient(PHP_BINARY, BinFile, $project, 15, $masking);
$response = $client->execute('app_info', []);
Assert::true($response->ok, $response->text);
Assert::same(['logDir'], array_keys($response->data['application']['parameters']));
Assert::notContains('synthetic-', json_encode($response->data));
if (getenv('LUPA_TEST_NO_MBSTRING')) {
	Assert::notContains('mbstring', $response->data['php']['extensions']);
}

// db_usage counts lines also in files named like a secret
mkdir($directory . '/src');
file_put_contents($directory . '/src/Authenticator.php', "<?php\n// SELECT password FROM customers\n");
$response = $client->execute('db_usage', ['table' => 'customers']);
Assert::true($response->ok, $response->text);
Assert::same(['src/Authenticator.php' => 1], $response->data['files']);
file_put_contents(
	$directory . '/boot.php',
	'<?php set_error_handler(static function (): bool { return false; }); trigger_error("birthDate=2000-01-01", E_USER_ERROR);',
);
$response = $client->execute('app_info', []);
Assert::same(Lupa\Worker\WorkerResponse::ErrorCrash, $response->errorKind);
Assert::notContains('2000-01-01', $response->text);
Assert::contains('birthDate=***', $response->text);

file_put_contents($directory . '/crash.php', '<?php fwrite(STDERR, "password=" . str_repeat("X", 3000) . "\npublic crash");');
$response = (new Lupa\Worker\WorkerClient(PHP_BINARY, $directory . '/crash.php', $project, 10, $masking))->execute('app_info', []);
Assert::same(Lupa\Worker\WorkerResponse::ErrorCrash, $response->errorKind);
Assert::notContains(str_repeat('X', 100), $response->text);
Assert::contains("password=***\npublic crash", $response->text);
