<?php declare(strict_types=1);

/**
 * Removing cache entries by a tag goes through the storage of the application, which need not keep files.
 */

use Lupa\Masking;
use Lupa\Toolkits\CacheToolkit;
use Lupa\Tools\AppContext;
use Lupa\Tools\ToolCatalog;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';


if (!class_exists(Nette\Caching\Storages\SQLiteStorage::class) || !extension_loaded('pdo_sqlite')) {
	Environment::skip('Needs nette/caching and pdo_sqlite.');
}

$storage = new Nette\Caching\Storages\SQLiteStorage(TempDir . '/cache.db');
$cache = new Nette\Caching\Cache($storage, 'Tagged');
$cache->save('kept', 1);
$cache->save('tagged', 2, ['tags' => ['orders']]); // Cache::Tags, named TAGS before nette/caching 3.2

$application = new class ($storage) implements Lupa\Application {
	private object $storage;


	public function __construct(object $storage)
	{
		$this->storage = $storage;
	}


	public function getInfo(): array
	{
		return ['framework' => 'test', 'parameters' => ['tempDir' => TempDir . '/no-file-cache']];
	}


	public function getWarning(): ?string
	{
		return null;
	}


	public function getDatabaseTargets(): array
	{
		return [];
	}


	public function initialize(): void
	{
	}


	public function getDatabaseConnection(string $service): object
	{
		throw new LogicException('No database.');
	}


	public function getServiceByType(string $type): ?object
	{
		return in_array($type, ['Nette\Caching\Storage', 'Nette\Caching\IStorage'], true) ? $this->storage : null;
	}
};
$context = new AppContext($application, createFixtureProject(), new ToolCatalog([]), []);
$toolkit = new CacheToolkit(new Masking);

$result = $toolkit->call('cache_invalidate', ['tag' => 'orders'], $context);
Assert::same('orders', $result->data['tag']);
Assert::null($cache->load('tagged'));
Assert::same(1, $cache->load('kept'));

// the operations on files still need the directory
Assert::exception(
	fn() => $toolkit->call('cache_namespaces', [], $context),
	Lupa\InspectorException::class,
	"Cache directory '%a%' not found; the application may not use FileStorage.",
);
