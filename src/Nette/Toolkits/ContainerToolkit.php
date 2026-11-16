<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\ContainerReader;
use Lupa\Nette\FactoryCodeMasker;
use Lupa\Nette\NetteApplication;
use Lupa\Project;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Nette\Neon\Entity;


/**
 * The DI container of the application: services, their dependencies and dependents, parameters.
 * Reads the compiled container; services are never created.
 */
final class ContainerToolkit implements Toolkit
{
	private const Results = [
		'di_services' => [
			'total' => 'integer',
			'offset' => 'integer',
			'services' => [[
				'name' => 'string',
				'type' => '?string',
				'tags' => 'object|array',
				'autowired' => 'mixed',
				'created' => 'boolean',
			]],
		],
		'di_service' => [
			'name' => 'string',
			'type' => '?string',
			'classFile' => '?string',
			'aliases' => 'string[]',
			'tags' => 'object|array',
			'autowired' => 'mixed',
			'created' => 'boolean',
			'dependencies' => [['service' => '?string', 'via' => 'string', 'member' => '?string', 'type' => '?string']],
			'factory' => '?object', // line and code of the generated factory
			'definedIn' => [['file' => 'string', 'line' => 'integer', 'text' => 'string']],
		],
		'di_dependents' => [
			'targets' => 'string[]',
			'type' => '?string',
			'total' => 'integer',
			'dependents' => [['service' => 'string', 'type' => '?string', 'via' => 'string', 'member' => '?string']],
			'references' => [['file' => 'string', 'lines' => 'integer[]']],
			'referenceCount' => 'integer',
		],
		'di_parameters' => [
			'path' => '?string',
			'value' => 'mixed',
			'setIn' => [['file' => 'string', 'value' => 'mixed']],
		],
		'di_extensions' => [
			'extensions' => [[
				'name' => 'string',
				'class' => '?string',
				'registeredIn' => '?string',
				'configured' => 'boolean',
				'config' => 'mixed',
			]],
		],
	];

	private const CodeLines = 60;

	private Masking $masking;
	private FactoryCodeMasker $codeMasker;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
		$this->codeMasker = new FactoryCodeMasker($masking);
	}


	public function getTools(): array
	{
		$service = [
			'name' => Schema::string('Service name or alias, e.g. "database" or "application.application".'),
			'type' => Schema::string('Class or interface; the service autowired for it is used.'),
		];
		return Tool::withResults([
			new Tool(
				'di_services',
				'DI services',
				'Lists services of the DI container with their type, tags, autowiring and whether they are created.'
				. ' Filter by part of the name, by class or interface (including subclasses) or by tag.',
				Schema::object([
					'name' => Schema::string('Part of the service name, case-insensitive.'),
					'type' => Schema::string('Class or interface the service must be an instance of.'),
					'tag' => Schema::string('Tag the service must have.'),
				] + Schema::paging(50, 500)),
			),
			new Tool(
				'di_service',
				'DI service detail',
				'Shows one service: class and its file, aliases, tags, autowiring, the services passed to its constructor,'
				. ' inject methods, setup calls and properties, the generated factory code and where the configuration defines it.',
				Schema::object($service),
			),
			new Tool(
				'di_dependents',
				'Who uses a service',
				'Finds the services, presenters and components that get the service (or any service of a type) as a dependency,'
				. ' and the PHP files of the application that refer to its class.',
				Schema::object($service + Schema::paging(100, 1000)),
			),
			new Tool(
				'di_parameters',
				'DI parameters',
				'Shows the parameters of the container, optionally only the branch under a dotted path such as "database".'
				. ' For a path, also lists the configuration files that set it, in the order they were loaded:'
				. ' the last one wins unless the bootstrap overrides it. Secret values are masked.',
				Schema::object(['path' => Schema::string('Dotted path of a branch, e.g. "mail.smtp".')]),
			),
			new Tool(
				'di_extensions',
				'DI extensions',
				'Lists the compiler extensions registered in the configuration files and those the Configurator adds by default,'
				. ' with the configuration of their sections. Secret values are masked.',
				Schema::object(['name' => Schema::string('Part of the extension name or class, case-insensitive.')]),
			),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$reader = new ContainerReader(NetteApplication::getContainerOf($context->application));
		switch ($tool) {
			case 'di_services':
				return $this->listServices($reader, $arguments, $context->project);
			case 'di_service':
				return $this->showService($reader, $this->findService($reader, $arguments), $context->project);
			case 'di_dependents':
				return $this->findDependents($reader, $arguments, $context);
			case 'di_parameters':
				return $this->showParameters($reader, $arguments->optionalString('path'), $context->project);
			case 'di_extensions':
				return $this->listExtensions($reader, $arguments->optionalString('name'), $context->project);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	private function listServices(ContainerReader $reader, Arguments $arguments, Project $project): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 50, 500);
		$names = $reader->getServiceNames();
		if ($arguments->has('name')) {
			$names = array_filter($names, fn(string $name) => stripos($name, $arguments->string('name')) !== false);
		}

		if ($arguments->has('tag')) {
			$names = array_filter($names, fn(string $name) => array_key_exists($arguments->string('tag'), $reader->getTags($name)));
		}

		if ($arguments->has('type')) {
			$type = ltrim($arguments->string('type'), '\\');
			$names = array_filter($names, fn(string $name) => ($class = $reader->getType($name)) !== null && is_a($class, $type, true));
		}

		$names = array_values($names);
		$services = [];
		foreach (array_slice($names, $offset, $limit) as $name) {
			$services[] = [
				'name' => $name,
				'type' => $reader->getType($name),
				'tags' => array_keys($reader->getTags($name)),
				'autowired' => $reader->getAutowiring($name),
				'created' => $reader->isCreated($name),
			];
		}

		$data = ['total' => count($names), 'offset' => $offset, 'services' => $services];
		return new ToolResult($data, sprintf('%d service(s) match, showing %d.', count($names), count($services)));
	}


	private function showService(ContainerReader $reader, string $name, Project $project): ToolResult
	{
		$type = $reader->getType($name);
		$classFile = $type !== null && class_exists($type) ? (new \ReflectionClass($type))->getFileName() : false;
		$dependencies = [];
		foreach ($reader->getDependencies($name) as $dependency) {
			$dependencies[] = $dependency + ['type' => $reader->getType($dependency['service'])];
		}

		$factory = $reader->getFactoryCode($name);
		$data = [
			'name' => $name,
			'type' => $type,
			'classFile' => $classFile === false ? null : $project->relativize($classFile),
			'aliases' => $reader->getAliases($name),
			'tags' => $this->masking->maskData($reader->getTags($name)),
			'autowired' => $reader->getAutowiring($name),
			'created' => $reader->isCreated($name),
			'dependencies' => $dependencies,
			'factory' => $factory === null ? null : [
				'line' => $factory['line'],
				'code' => self::limitLines($this->codeMasker->mask($factory['code'], $type), self::CodeLines),
			],
			'definedIn' => $this->findDefinitions($reader, $name, $type, $project),
		];
		return new ToolResult($data, sprintf('Service %s (%s), %d dependencies.', $name, $type ?? 'unknown type', count($dependencies)));
	}


	private function findDependents(ContainerReader $reader, Arguments $arguments, AppContext $context): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 100, 1000);
		if ($arguments->has('type')) {
			$type = ltrim($arguments->string('type'), '\\');
			$targets = $reader->findByType($type);
			if (!$targets) {
				throw new InspectorException("No service of type '$type' found.");
			}
		} else {
			$targets = [$this->findService($reader, $arguments)];
			$type = $reader->getType($targets[0]);
		}

		$dependents = [];
		foreach ($reader->getServiceNames() as $name) {
			foreach ($reader->getDependencies($name) as $dependency) {
				if (in_array($dependency['service'], $targets, true)) {
					$dependents[] = ['service' => $name, 'type' => $reader->getType($name)] + $dependency;
				}
			}
		}

		$references = $type === null ? [] : $this->findClassReferences($type, $context);
		$data = [
			'targets' => $targets,
			'type' => $type,
			'total' => count($dependents),
			'dependents' => array_slice($dependents, $offset, $limit),
			'references' => array_slice($references, 0, $limit),
			'referenceCount' => count($references),
		];
		return new ToolResult($data, sprintf(
			'%d service(s) receive %s; %d file(s) refer to %s.',
			count($dependents),
			implode(', ', $targets),
			count($references),
			$type ?? 'the type',
		));
	}


	private function showParameters(ContainerReader $reader, ?string $path, Project $project): ToolResult
	{
		$value = $reader->getContainer()->getParameters();
		$keys = $path === null || $path === '' ? [] : explode('.', $path);
		$hidden = false; // everything under a secret key is hidden, whatever the keys below it are called
		foreach ($keys as $key) {
			if (!is_array($value) || !array_key_exists($key, $value)) {
				throw new InspectorException("Parameter '$path' not found.");
			}

			$value = $value[$key];
			$hidden = $hidden || $this->masking->isSecretKey($key);
		}

		$data = ['path' => $path, 'value' => $this->maskParameter($value, $hidden)];
		if ($keys) {
			$data['setIn'] = [];
			foreach ($reader->getSourceConfigs() as $file => $config) {
				$found = true;
				$branch = $config['parameters'] ?? null;
				foreach ($keys as $key) {
					if (!is_array($branch) || !array_key_exists($key, $branch)) {
						$found = false;
						break;
					}

					$branch = $branch[$key];
				}

				if ($found) {
					$data['setIn'][] = ['file' => $project->relativize($file), 'value' => $this->maskParameter($branch, $hidden)];
				}
			}
		}

		return new ToolResult(
			$data,
			$path ? "Parameter $path." : sprintf('%d top-level parameter(s).', is_array($value) ? count($value) : 1),
		);
	}


	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function maskParameter($value, bool $hidden)
	{
		return $hidden && $value !== null && $value !== '' && !is_bool($value)
			? Masking::Mask
			: $this->masking->maskData(self::normalize($value));
	}


	private function listExtensions(ContainerReader $reader, ?string $filter, Project $project): ToolResult
	{
		$extensions = [];
		foreach (self::getConfiguratorExtensions() as $name => $class) {
			$extensions[$name] = ['name' => $name, 'class' => $class, 'registeredIn' => 'Configurator default'];
		}

		$configs = $reader->getSourceConfigs();
		foreach ($configs as $file => $config) {
			foreach ((array) ($config['extensions'] ?? []) as $name => $definition) {
				$extensions[$name] = [
					'name' => $name,
					'class' => self::normalize($definition),
					'registeredIn' => $project->relativize($file),
				];
			}
		}

		foreach ($extensions as $name => &$extension) {
			$sections = [];
			foreach ($configs as $config) {
				if (array_key_exists($name, $config)) {
					$sections[] = (array) self::normalize($config[$name]);
				}
			}

			$extension['configured'] = (bool) $sections;
			$extension['config'] = $sections ? $this->masking->maskData(array_replace_recursive(...$sections)) : null;
		}

		unset($extension);
		$extensions = array_values(array_filter($extensions, fn(array $extension) => $filter === null
			|| stripos($extension['name'] . ' ' . json_encode($extension['class']), $filter) !== false));
		return new ToolResult(['extensions' => $extensions], sprintf('%d extension(s).', count($extensions)));
	}


	/**
	 * Extensions the Configurator of nette/bootstrap registers without configuration.
	 * @return array<string, string>
	 */
	private static function getConfiguratorExtensions(): array
	{
		foreach (['Nette\Bootstrap\Configurator', 'Nette\Configurator'] as $class) {
			if (class_exists($class)) {
				$result = [];
				foreach ((array) ((new \ReflectionClass($class))->getDefaultProperties()['defaultExtensions'] ?? []) as $name => $extension) {
					$result[(string) $name] = Scalar::toString(is_array($extension) ? $extension[0] ?? null : $extension);
				}

				return $result;
			}
		}

		return [];
	}


	private function findService(ContainerReader $reader, Arguments $arguments): string
	{
		if ($arguments->has('name')) {
			$name = $reader->resolveName($arguments->string('name'));
			if ($name === null) {
				throw new InspectorException("Service '{$arguments->string('name')}' not found. Use di_services to search.");
			}

			return $name;

		} elseif ($arguments->has('type')) {
			$type = ltrim($arguments->string('type'), '\\');
			$known = class_exists($type) || interface_exists($type);
			$names = $known ? $reader->getContainer()->findAutowired($type) : [];
			if (count($names) !== 1) {
				$all = $reader->findByType($type);
				throw new InspectorException($all
					? "Type '$type' matches " . count($all) . ' services (' . implode(', ', array_slice($all, 0, 10)) . '); give the name.'
					: "No service of type '$type' found.");
			}

			return $names[0];
		}

		throw new InspectorException("Give the service 'name' or 'type'.");
	}


	/**
	 * Lines of the configuration files that name the service or its class.
	 * @return list<array{file: string, line: int, text: string}>
	 */
	private function findDefinitions(ContainerReader $reader, string $name, ?string $type, Project $project): array
	{
		$patterns = ['~^\s*' . preg_quote($name, '~') . '\s*:~'];
		if ($type !== null) {
			$patterns[] = '~(?<![\w\\\\])\\\\?' . preg_quote($type, '~') . '(?![\w\\\\])~i';
		}

		$result = [];
		foreach ($reader->getSourceFiles() as $file) {
			foreach ((array) @file($file) as $i => $line) { // @ unreadable files are skipped
				foreach ($patterns as $pattern) {
					if (preg_match($pattern, (string) $line)) {
						$result[] = [
							'file' => $project->relativize($file),
							'line' => $i + 1,
							'text' => $this->maskDefinitionLine((string) $line),
						];
						break;
					}
				}
			}
		}

		return array_slice($result, 0, 20);
	}


	/**
	 * A line of configuration that defines a service. Its arguments may be literal secrets under any name,
	 * so only the keys and the class or factory stay readable.
	 */
	private function maskDefinitionLine(string $line): string
	{
		$line = (string) preg_replace(
			['~\((?!\s*\)).*\)~', '~\[(?!\s*\]).*\]~', '~\{(?!\s*\}).*\}~'],
			['(…)', '[…]', '{…}'],
			$line,
		);
		return trim($this->masking->maskText($line));
	}


	/**
	 * PHP files of the application that refer to the class: by its full name, or by the short name
	 * in files that import it or share its namespace.
	 * @return list<array{file: string, lines: list<int>}>
	 */
	private function findClassReferences(string $type, AppContext $context): array
	{
		$dir = $context->getDirectory('appDir', 'app');
		if (!is_dir($dir)) {
			return [];
		}

		$type = ltrim($type, '\\');
		$short = substr((string) strrchr('\\' . $type, '\\'), 1);
		$namespace = (string) substr($type, 0, max(0, strlen($type) - strlen($short) - 1));
		$full = '~(?<![\w\\\\])\\\\?' . preg_quote($type, '~') . '(?![\w\\\\])~i';
		$shortPattern = '~(?<![\w\\\\$])' . preg_quote($short, '~') . '(?![\w\\\\])~';

		$ownFile = class_exists($type) || interface_exists($type) ? realpath((string) (new \ReflectionClass($type))->getFileName()) : null;
		$result = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (
				!$file instanceof \SplFileInfo
				|| $file->getExtension() !== 'php'
				|| ($code = @file_get_contents($file->getPathname())) === false // @ unreadable files are skipped
			) {
				continue;
			}

			$importsOrShares = preg_match($full, $code)
				|| ($namespace !== '' && preg_match('~^namespace\s+' . preg_quote($namespace, '~') . '\s*;~mi', $code));
			if (!$importsOrShares || !preg_match($shortPattern, $code)) {
				continue;
			}

			$lines = [];
			foreach (explode("\n", $code) as $i => $line) {
				if (preg_match($shortPattern, $line) && !preg_match('~^\s*(use|namespace)\s~', $line)) {
					$lines[] = $i + 1;
				}
			}

			if ($lines && realpath($file->getPathname()) !== $ownFile) {
				$result[] = ['file' => $context->project->relativize($file->getPathname()), 'lines' => array_slice($lines, 0, 20)];
			}
		}

		usort($result, fn(array $a, array $b) => strcmp($a['file'], $b['file']));
		return $result;
	}


	private static function limitLines(string $code, int $max): string
	{
		$lines = explode("\n", rtrim($code));
		return count($lines) > $max
			? implode("\n", array_slice($lines, 0, $max)) . "\n… " . (count($lines) - $max) . ' more lines'
			: implode("\n", $lines);
	}


	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function normalize($value)
	{
		if (is_array($value)) {
			return array_map([self::class, 'normalize'], $value);
		} elseif ($value instanceof Entity) {
			return $value->attributes
				? ['entity' => self::normalize($value->value), 'arguments' => self::normalize($value->attributes)]
				: self::normalize($value->value);
		} elseif (is_object($value)) {
			return get_class($value);
		}

		return $value;
	}
}
