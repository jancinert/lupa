<?php declare(strict_types=1);

namespace Lupa\Nette;

use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use Nette\Neon\Exception;
use Nette\Neon\Neon;


/**
 * Reads the metadata and the generated code of a compiled container without creating services.
 * Works with nette/di 2.4 to 3.2: the metadata is in protected properties and every service
 * defined in the configuration has a createService method whose body shows its dependencies.
 */
final class ContainerReader
{
	public const
		ViaConstructor = 'constructor',
		ViaMethod = 'method',
		ViaProperty = 'property',
		ViaOther = 'other';

	private Container $container;

	/** @var array<string, string> alias => service name */
	private array $aliases;

	/** @var array<string, array<string, mixed>> tag => service name => value */
	private array $tags;

	/** @var array<string, array<int, list<string>>> type => [preferred, secondary, not autowired] */
	private array $wiring;

	/** @var array<string, true> */
	private array $names = [];


	public function __construct(Container $container)
	{
		$this->container = $container;
		/**
		 * The protected metadata of the container, whose shape nette/di does not declare.
		 * @var array{array<string, string>, array<string, array<string, mixed>>, array<string, array<int, list<string>>>, array<string, string>, array<mixed>, array<mixed>} $internals
		 */
		$internals = \Closure::bind(
			function (): array {
				if (!property_exists($this, 'meta')) {
					return [$this->aliases, $this->tags, $this->wiring, $this->names ?? [], $this->types ?? [], $this->instances];
				}

				$meta = (array) $this->meta; // nette/di 2.4 keeps everything in one array and knows only autowired or not
				$wiring = [];
				foreach ((array) ($meta['types'] ?? []) as $type => $names) {
					$names = (array) $names;
					$wiring[$type] = [$names[1] ?? [], [], $names[0] ?? []]; // keyed by true and false
				}

				return [$meta['aliases'] ?? [], $meta['tags'] ?? [], $wiring, [], $meta['services'] ?? [], $this->registry];
			},
			$container,
			Container::class,
		)();
		[$this->aliases, $this->tags, $this->wiring, $names, $types, $instances] = $internals;

		foreach (get_class_methods($container) as $method) {
			if (strlen($method) > 13 && strncmp($method, 'createService', 13) === 0) {
				$name = lcfirst(str_replace('__', '.', substr($method, 13)));
				$name = $names[$name] ?? $name;
				$this->names[$container->hasService($name) ? $name : ucfirst($name)] = true;
			}
		}

		foreach (array_keys($types + $instances) as $name) {
			$this->names[(string) $name] ??= true;
		}

		ksort($this->names, SORT_NATURAL);
	}


	public function getContainer(): Container
	{
		return $this->container;
	}


	/**
	 * @return list<string>
	 */
	public function getServiceNames(): array
	{
		return array_map('strval', array_keys($this->names));
	}


	/** @return list<string> */
	public function findByType(string $type): array
	{
		if (!class_exists($type) && !interface_exists($type)) {
			return [];
		}

		// keeps the container's service order and adds the types excluded from exported wiring
		$names = $this->container->findByType($type);
		foreach ($this->getServiceNames() as $name) {
			$declared = $this->getType($name);
			if ($declared !== null && is_a($declared, $type, true) && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}

		return $names;
	}


	/**
	 * Resolves an alias to the service name, or returns null for an unknown name.
	 */
	public function resolveName(string $name): ?string
	{
		$name = $this->aliases[$name] ?? $name;
		return isset($this->names[$name]) || $this->container->hasService($name) ? $name : null;
	}


	/**
	 * @return list<string>
	 */
	public function getAliases(string $name): array
	{
		return array_map('strval', array_keys($this->aliases, $name, true));
	}


	public function getType(string $name): ?string
	{
		try {
			return $this->container->getServiceType($name) ?: null;
		} catch (MissingServiceException $e) {
			return null;
		}
	}


	/**
	 * @return array<string, mixed> tag => value
	 */
	public function getTags(string $name): array
	{
		$result = [];
		foreach ($this->tags as $tag => $services) {
			if (array_key_exists($name, $services)) {
				$result[$tag] = $services[$name];
			}
		}

		return $result;
	}


	/**
	 * Whether the service is autowired by its own type: 'preferred', 'secondary' or false.
	 * @return string|false
	 */
	public function getAutowiring(string $name)
	{
		$type = $this->getType($name);
		$wiring = $type === null ? [] : $this->wiring[ltrim($type, '\\')] ?? [];
		if (in_array($name, $wiring[0] ?? [], true)) {
			return 'preferred';
		}

		return in_array($name, $wiring[1] ?? [], true) ? 'secondary' : false;
	}


	public function isCreated(string $name): bool
	{
		return $this->container->isCreated($name);
	}


	/**
	 * The generated factory method of the service.
	 * @return ?array{file: string, line: int, code: string}
	 */
	public function getFactoryCode(string $name): ?array
	{
		$method = Container::getMethodName($name);
		if (!method_exists($this->container, $method)) {
			return null;
		}

		$reflection = new \ReflectionMethod($this->container, $method);
		$file = $reflection->getFileName();
		if ($file === false || ($lines = @file($file)) === false) { // @ the compiled file may have been removed
			return null;
		}

		$start = (int) $reflection->getStartLine();
		return [
			'file' => $file,
			'line' => $start,
			'code' => implode('', array_slice($lines, $start - 1, (int) $reflection->getEndLine() - $start + 1)),
		];
	}


	/**
	 * Services the factory method passes to the service, and how: to the constructor, a method, a property.
	 * @return list<array{service: string, via: string, member: ?string}>
	 */
	public function getDependencies(string $name): array
	{
		$code = $this->getFactoryCode($name)['code'] ?? '';
		$result = [];
		$via = self::ViaOther;
		$member = null;
		foreach (explode("\n", $code) as $line) {
			if (preg_match('~^\s*(?:\$service\s*=|return)\s+new\b~', $line)) {
				[$via, $member] = [self::ViaConstructor, null];
			} elseif (preg_match('~^\s*\$service->(\w+)\s*\(~', $line, $m)) {
				[$via, $member] = [self::ViaMethod, $m[1]];
			} elseif (preg_match('~^\s*\$service->(\w+)\s*=~', $line, $m)) {
				[$via, $member] = [self::ViaProperty, $m[1]];
			} elseif (preg_match('~^\s*(?:\$service\b|return\b|\}|public function)~', $line)) {
				[$via, $member] = [self::ViaOther, null];
			}

			preg_match_all("~\\\$this->getService\\('([^']+)'\\)~", $line, $matches);
			foreach ($matches[1] as $dependency) {
				$result[] = ['service' => $dependency, 'via' => $via, 'member' => $member];
			}
		}

		return $result;
	}


	/**
	 * Configuration files the container was compiled from, as its header lists them.
	 * @return list<string>
	 */
	public function getSourceFiles(): array
	{
		$file = (new \ReflectionClass($this->container))->getFileName();
		$header = $file === false ? '' : (string) @file_get_contents($file, false, null, 0, 4096); // @ the file may be gone
		preg_match_all('~^// source: (.+\.neon) ?$~m', $header, $matches); // nette/di 2.4 ends the line with a space
		return array_values(array_filter($matches[1], 'is_file'));
	}


	/**
	 * The configuration files decoded, in the order they were loaded; unreadable ones are skipped.
	 * @return array<string, array<mixed>>  file => configuration
	 */
	public function getSourceConfigs(): array
	{
		$result = [];
		foreach ($this->getSourceFiles() as $file) {
			try {
				$config = Neon::decode((string) file_get_contents($file));
			} catch (Exception $e) {
				continue; // the container was compiled from it, so it was valid then; a later edit is not ours to report
			}

			if (is_array($config)) {
				$result[$file] = $config;
			}
		}

		return $result;
	}
}
