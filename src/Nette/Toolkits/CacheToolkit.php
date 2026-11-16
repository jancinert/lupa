<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Lupa\Utf8;


/**
 * The FileStorage of nette/caching in <tempDir>/cache. Cached values are unserialized without instantiating
 * classes, so no application code runs. Deleting entries is an active tool, off unless enabled.
 */
final class CacheToolkit implements Toolkit
{
	private const Results = [
		'cache_namespaces' => [
			'directory' => 'string',
			'total' => 'integer',
			'namespaces' => [['namespace' => 'string', 'entries' => 'integer', 'bytes' => 'integer', 'newest' => '?string']],
			'namespace' => 'string',
			'entries' => [['entry' => 'string', 'bytes' => '?integer', 'written' => '?string']],
		],
		'cache_get' => [
			'namespace' => 'string',
			'entry' => 'string',
			'written' => '?string',
			'expires' => '?string',
			'slidingSeconds' => '?integer',
			'dependsOnFiles' => 'string[]',
			'dependsOnItems' => 'string[]',
			'otherCallbacks' => 'integer',
			'value' => 'mixed',
		],
		'cache_invalidate' => ['namespace' => '?string', 'removed' => 'integer'],
	];

	private const HeaderLength = 6;
	private const LegacySignature = '<?php //netteCache[01]';
	private const ValueLength = 20000;

	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	public function getTools(): array
	{
		$namespace = Schema::string('Cache namespace, e.g. "Nette.Configurator" or "App.Orders".');
		return Tool::withResults([
			(new Tool(
				'cache_namespaces',
				'Cache namespaces',
				'Lists the namespaces of the file cache with the number and size of entries; with a namespace,'
				. ' lists its entries, newest first.',
				Schema::object(['namespace' => $namespace] + Schema::paging(50, 1000)),
			))->withoutApplication(),
			(new Tool(
				'cache_get',
				'Cache entry',
				'Shows a cache entry: when it was written, its expiration, the files and callbacks it depends on,'
				. ' and the cached value with classes shown by name and secrets masked. Give the key as the application'
				. ' passes it to Cache::load(), or the entry file name from cache_namespaces.',
				Schema::object([
					'namespace' => $namespace,
					'key' => Schema::string('Key passed to Cache::load(); only scalar keys can be given.'),
					'entry' => Schema::string('Entry file name as cache_namespaces lists it.'),
				], ['namespace']),
			))->markUntrusted()->withoutApplication(),
			(new Tool(
				'cache_invalidate',
				'Invalidate cache',
				'Active tool, off unless enabled: deletes a cache entry (namespace with key or entry), all entries of a'
				. ' namespace (namespace with all: true), or the entries with a tag through the storage of the application.',
				Schema::object([
					'namespace' => $namespace,
					'key' => Schema::string('Key passed to Cache::load(); only scalar keys can be given.'),
					'entry' => Schema::string('Entry file name as cache_namespaces lists it.'),
					'all' => ['type' => 'boolean', 'description' => 'Delete all entries of the namespace.'],
					'tag' => Schema::string('Delete the entries with this tag; needs the journal of the application.'),
				]),
			))->markActive(),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$dir = $context->getDirectory('tempDir', 'temp') . '/cache';
		if (!is_dir($dir)) {
			throw new InspectorException("Cache directory '$dir' not found; the application may not use FileStorage.");
		}

		switch ($tool) {
			case 'cache_namespaces':
				return $arguments->has('namespace')
					? $this->listEntries($dir, $arguments)
					: $this->listNamespaces($dir, $arguments);
			case 'cache_get':
				return $this->showEntry($dir, $arguments);
			case 'cache_invalidate':
				return $this->invalidate($dir, $arguments, $context);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	private function listNamespaces(string $dir, Arguments $arguments): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 50, 1000);
		$namespaces = [];
		foreach (glob($dir . '/_*', GLOB_ONLYDIR) ?: [] as $path) {
			$files = glob($path . '/_*') ?: [];
			$times = array_filter(array_map('filemtime', $files));
			$namespaces[] = [
				'namespace' => urldecode(substr(basename($path), 1)),
				'entries' => count($files),
				'bytes' => array_sum(array_map('filesize', $files)),
				'newest' => $times ? date('Y-m-d H:i:s', max($times)) : null,
			];
		}

		usort($namespaces, fn(array $a, array $b) => $b['entries'] <=> $a['entries']);
		$data = ['directory' => $dir, 'total' => count($namespaces), 'namespaces' => array_slice($namespaces, $offset, $limit)];
		return new ToolResult($data, sprintf('%d namespace(s).', count($namespaces)));
	}


	private function listEntries(string $dir, Arguments $arguments): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 50, 1000);
		$entries = [];
		foreach (glob($this->getNamespaceDir($dir, $arguments->string('namespace')) . '/_*') ?: [] as $file) {
			$entries[] = ['entry' => basename($file), 'bytes' => filesize($file), 'written' => date('Y-m-d H:i:s', (int) filemtime($file))];
		}

		usort($entries, fn(array $a, array $b) => strcmp($b['written'], $a['written']));
		$data = [
			'namespace' => $arguments->string('namespace'),
			'total' => count($entries),
			'entries' => array_slice($entries, $offset, $limit),
		];
		return new ToolResult(
			$data,
			sprintf('%d entr%s in %s.', count($entries), count($entries) === 1 ? 'y' : 'ies', $arguments->string('namespace')),
		);
	}


	private function showEntry(string $dir, Arguments $arguments): ToolResult
	{
		$file = $this->findEntryFile($dir, $arguments);
		[$meta, $value] = self::readEntry($file);
		$files = $callbacks = [];
		foreach ((array) ($meta['callbacks'] ?? []) as $callback) { // Cache::Files are stored as checkFile callbacks
			if (is_array($callback) && is_array($callback[0] ?? null) && ($callback[0][1] ?? null) === 'checkFile') {
				$files[] = Scalar::toString($callback[1]);
			} else {
				$callbacks[] = $callback;
			}
		}

		$data = [
			'namespace' => $arguments->string('namespace'),
			'entry' => basename($file),
			'written' => date('Y-m-d H:i:s', (int) filemtime($file)),
			'expires' => isset($meta['expire']) ? date('Y-m-d H:i:s', Scalar::toInt($meta['expire'])) : null,
			'slidingSeconds' => $meta['delta'] ?? null,
			'dependsOnFiles' => $files,
			'dependsOnItems' => array_map(fn($item) => basename((string) $item), array_keys((array) ($meta['di'] ?? []))),
			'otherCallbacks' => count($callbacks),
			'value' => $this->presentValue($value),
		];
		return new ToolResult($data, sprintf('Entry %s written %s.', $data['entry'], $data['written']));
	}


	/**
	 * Deletes an entry, all entries of a namespace, or the entries with a tag through the application's storage
	 * (tags are known only to its journal).
	 */
	private function invalidate(string $dir, Arguments $arguments, AppContext $context): ToolResult
	{
		if ($arguments->has('tag')) {
			$storage = NetteApplication::getContainerOf($context->application)->getByType('Nette\Caching\Storage', false)
				?? NetteApplication::getContainerOf($context->application)->getByType('Nette\Caching\IStorage', false);
			if ($storage === null) {
				throw new InspectorException('The application has no cache storage service.');
			}

			$storage->clean(['tags' => [$arguments->string('tag')]]); // Cache::Tags
			return new ToolResult(
				['tag' => $arguments->string('tag'), 'storage' => get_class($storage)],
				"Entries tagged '{$arguments->string('tag')}' removed.",
			);
		} elseif (!$arguments->has('namespace')) {
			throw new InspectorException("Give the 'namespace' (with 'key', 'entry' or 'all'), or the 'tag'.");
		}

		$files = $arguments->bool('all')
			? glob($this->getNamespaceDir($dir, $arguments->string('namespace')) . '/_*') ?: []
			: [$this->findEntryFile($dir, $arguments)];
		$removed = 0;
		foreach ($files as $file) {
			$removed += (int) @unlink($file); // @ a file removed meanwhile counts as not removed
		}

		$data = ['namespace' => $arguments->string('namespace'), 'removed' => $removed];
		return new ToolResult(
			$data,
			sprintf('%d entr%s removed from %s.', $removed, $removed === 1 ? 'y' : 'ies', $arguments->string('namespace')),
		);
	}


	private function findEntryFile(string $dir, Arguments $arguments): string
	{
		$namespaceDir = $this->getNamespaceDir($dir, $arguments->string('namespace'));
		if ($arguments->has('entry')) {
			if (!preg_match('~^_[\w%.-]+$~', $arguments->string('entry'))) {
				throw new InspectorException("Argument 'entry' must be a file name as cache_namespaces lists it.");
			}

			$candidates = [$namespaceDir . '/' . $arguments->string('entry')];

		} elseif ($arguments->has('key')) {
			// nette/caching hashes the key with md5, since 3.3 with xxh128
			$candidates = [$namespaceDir . '/_' . md5($arguments->string('key'))];
			if (in_array('xxh128', hash_algos(), true)) {
				$candidates[] = $namespaceDir . '/_' . hash('xxh128', $arguments->string('key'));
			}
		} else {
			throw new InspectorException("Give the 'key' or the 'entry'.");
		}

		foreach ($candidates as $candidate) {
			if (is_file($candidate)) {
				return $candidate;
			}
		}

		throw new InspectorException('The entry is not in the cache.');
	}


	/**
	 * @return array{array<mixed>, mixed}  metadata and value
	 */
	private static function readEntry(string $file): array
	{
		$content = (string) file_get_contents($file);
		$legacy = strncmp($content, self::LegacySignature, strlen(self::LegacySignature)) === 0;
		$offset = $legacy ? strlen(self::LegacySignature) : 0;
		$header = substr($content, $offset, self::HeaderLength);
		$size = strspn($header, '0123456789') === self::HeaderLength ? (int) $header : 0;
		$metadata = substr($content, $offset + self::HeaderLength, $size);
		if ($legacy) {
			$metadata = substr($metadata, -2) === '?>' ? substr($metadata, 0, -2) : '';
		}
		$meta = $size ? @unserialize($metadata, ['allowed_classes' => false]) : null; // @ invalid data is reported below
		if (!is_array($meta)) {
			throw new InspectorException('The file is not a FileStorage cache entry.');
		}

		$data = substr($content, $offset + self::HeaderLength + $size);
		$value = empty($meta['serialized']) ? $data : @unserialize($data, ['allowed_classes' => false]); // @ invalid data shows as false
		return [$meta, $value];
	}


	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function presentValue($value)
	{
		$value = $this->masking->maskData(self::toPlain($value, 0));
		$json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return strlen($json) > self::ValueLength
			? ['truncated' => true, 'json' => Utf8::cut($json, self::ValueLength) . '…', 'bytes' => strlen($json)]
			: $value;
	}


	/**
	 * Objects become arrays with their class under "@class"; private and protected property names lose their prefixes.
	 * @param mixed $value
	 * @return mixed
	 */
	private static function toPlain($value, int $depth)
	{
		if ($depth > 10) {
			return '…';
		} elseif (is_object($value)) {
			$result = [];
			foreach ((array) $value as $key => $item) {
				$key = (string) $key;
				if ($key === '__PHP_Incomplete_Class_Name') {
					$result = ['@class' => $item] + $result;
				} else {
					$result[(string) preg_replace('~^\x00[^\x00]*\x00~', '', $key)] = self::toPlain($item, $depth + 1);
				}
			}

			return $value instanceof \__PHP_Incomplete_Class ? $result : ['@class' => get_class($value)] + $result;

		} elseif (is_array($value)) {
			return array_map(fn($item) => self::toPlain($item, $depth + 1), $value);
		} elseif (is_string($value) && !preg_match('~~u', $value)) {
			return '(binary, ' . strlen($value) . ' bytes)';
		}

		return $value;
	}


	private function getNamespaceDir(string $dir, string $namespace): string
	{
		$path = $dir . '/_' . urlencode($namespace);
		if (!is_dir($path)) {
			throw new InspectorException("Namespace '$namespace' not found; use cache_namespaces to list them.");
		}

		return $path;
	}
}
