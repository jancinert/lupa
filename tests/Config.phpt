<?php declare(strict_types=1);

/**
 * lupa.neon builds the inspector's container: sections, defaults, validation.
 */

use Lupa\Config;
use Lupa\ConfigException;
use Lupa\Guard\EnvironmentGuard;
use Lupa\Inspector;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function createInspector(string $neon): Nette\DI\Container
{
	$file = TempDir . '/config-' . md5($neon) . '.neon';
	file_put_contents($file, $neon);
	return Inspector::createContainer($file);
}


// defaults and relative paths
$container = createInspector('');
$config = $container->getByType(Config::class);
Assert::same(realpath(TempDir) . DIRECTORY_SEPARATOR . 'lupa-bootstrap.php', $config->bootstrap);
Assert::same(realpath(TempDir) . DIRECTORY_SEPARATOR . 'log/lupa/audit.jsonl', $config->auditLog);
Assert::true($config->enabled);
Assert::same(30, $config->timeout);
$tools = array_map(fn($tool) => $tool->name, $container->getByType(ToolCatalog::class)->getTools());
Assert::same(
	['acl_check', 'acl_matrix', 'acl_roles', 'app_info', 'cache_get', 'cache_namespaces', 'console_commands', 'db_env', 'db_explain', 'db_query',
		'db_relations', 'db_table', 'db_tables', 'db_usage', 'di_dependents', 'di_extensions', 'di_parameters', 'di_service', 'di_services', 'form',
		'latte_check', 'latte_map_line', 'link', 'migrations_conflicts', 'migrations_status', 'presenter', 'presenters', 'route_match',
		'routes', 'tracy_errors', 'tracy_report'],
	(function (array $tools): array {
		sort($tools);
		return $tools;
	})($tools),
);

$target = new Lupa\Guard\DatabaseTarget('db', 'Dibi\Connection', 'compiled');
$target->host = '127.0.0.1';
Assert::null($container->getByType(EnvironmentGuard::class)->findViolation($target));


// all sections
$container = createInspector(<<<'NEON'
	inspector:
		bootstrap: /app/boot.php
		enabled: false
		timeout: 5
		disabledTools: [db_query]
	guard:
		allowedHosts: [mysql]
		deniedDatabases: ['*_prod']
	services:
		- Lupa\Tests\Fixtures\TestToolkit
	NEON);
$config = $container->getByType(Config::class);
Assert::same(['/app/boot.php', false, 5], [$config->bootstrap, $config->enabled, $config->timeout]);
$tools = array_map(fn($tool) => $tool->name, $container->getByType(ToolCatalog::class)->getTools());
Assert::notContains('db_query', $tools);
Assert::contains('test_echo', $tools);

$guard = $container->getByType(EnvironmentGuard::class);
$target = new Lupa\Guard\DatabaseTarget('db', 'Dibi\Connection', 'compiled');
$target->host = 'localhost';
Assert::contains('not in guard.allowedHosts', $guard->findViolation($target));


// errors
Assert::exception(fn() => createInspector("guard:\n\tallowedHost: []"), ConfigException::class, "%a%allowedHost%a%");
Assert::exception(fn() => createInspector("inspector:\n\ttimeout: '5'"), ConfigException::class, '%a%timeout%a%');
Assert::exception(fn() => createInspector("inspector:\n\ttimeout: 0"), ConfigException::class, '%a%timeout%a%');
Assert::exception(fn() => createInspector("unknown:\n\ta: 1"), ConfigException::class, "%a%unknown%a%");
Assert::exception(fn() => createInspector("masking:\n\tdetectors: [nonsense]"), ConfigException::class, '%a%detectors%a%');
Assert::exception(fn() => Inspector::createContainer(TempDir . '/missing.neon'), ConfigException::class, '%a%not found%a%');


// patterns in disabledTools, instructions for the model and keys that are never masked
$container = createInspector(<<<'NEON'
	inspector:
		disabledTools: ['db_*', app_info]
		instructions: 'Use test_echo first.'
	masking:
		ignoreKeys: [hash]
	services:
		- Lupa\Tests\Fixtures\TestToolkit
	NEON);
$tools = array_map(fn($tool) => $tool->name, $container->getByType(ToolCatalog::class)->getTools());
Assert::same([], preg_grep('~^db_|^app_info$~', $tools));
Assert::contains('test_echo', $tools);
Assert::contains('di_services', $tools);
Assert::same('Use test_echo first.', $container->getByType(Config::class)->instructions);
Assert::same(
	['hash' => 'made-up-file-hash', 'passwordHash' => '***'],
	$container->getByType(Lupa\Masking::class)->maskData(['hash' => 'made-up-file-hash', 'passwordHash' => 'made-up-value']),
);
