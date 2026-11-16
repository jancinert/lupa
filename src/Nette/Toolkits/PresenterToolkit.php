<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Project;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Nette\Application\InvalidPresenterException;
use Nette\Application\PresenterFactory;
use Nette\DI\Container;


/**
 * Presenters of nette/application: their names, mapping, and what a presenter consists of.
 * Uses reflection and the presenter factory; no presenter is created.
 */
final class PresenterToolkit implements Toolkit
{
	private const Results = [
		'presenters' => [
			'mapping' => 'mixed', // module => mask or its parts
			'total' => 'integer',
			'offset' => 'integer',
			'presenters' => [['name' => '?string', 'class' => 'string', 'file' => '?string', 'service' => '?string']],
		],
		'presenter' => [
			'class' => 'string',
			'file' => '?string',
			'parents' => 'string[]',
			'actions' => [[
				'name' => 'string',
				'method' => 'string',
				'parameters' => [['name' => 'string', 'type' => '?string', 'optional' => 'boolean']],
				'returns' => '?string',
				'declaredIn' => 'string',
				'line' => '?integer',
			]],
			'renders' => [[
				'name' => 'string',
				'method' => 'string',
				'parameters' => [['name' => 'string', 'type' => '?string', 'optional' => 'boolean']],
				'returns' => '?string',
				'declaredIn' => 'string',
				'line' => '?integer',
			]],
			'signals' => [[
				'name' => 'string',
				'method' => 'string',
				'parameters' => [['name' => 'string', 'type' => '?string', 'optional' => 'boolean']],
				'returns' => '?string',
				'declaredIn' => 'string',
				'line' => '?integer',
			]],
			'components' => [[
				'name' => 'string',
				'method' => 'string',
				'parameters' => [['name' => 'string', 'type' => '?string', 'optional' => 'boolean']],
				'returns' => '?string',
				'declaredIn' => 'string',
				'line' => '?integer',
			]],
			'injects' => [[
				'name' => 'string',
				'method' => 'string',
				'parameters' => [['name' => 'string', 'type' => '?string', 'optional' => 'boolean']],
				'returns' => '?string',
				'declaredIn' => 'string',
				'line' => '?integer',
			]],
			'persistent' => [['name' => 'string', 'type' => '?string', 'default' => 'mixed', 'declaredIn' => 'string']],
			'injectedProperties' => [['name' => 'string', 'type' => '?string', 'declaredIn' => 'string']],
			'checks' => [['method' => 'string', 'class' => 'string', 'file' => 'string', 'line' => 'integer', 'code' => 'string']],
			'templates' => [['file' => 'string', 'view' => 'string', 'blocks' => 'string[]', 'snippets' => 'string[]']],
		],
	];

	private const PresenterType = 'Nette\Application\UI\Presenter';
	private const FactoryType = 'Nette\Application\IPresenterFactory';
	private const SourceLines = 40;

	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	public function getTools(): array
	{
		return Tool::withResults([
			new Tool(
				'presenters',
				'Presenters',
				'Lists the presenters registered in the DI container with their names (Module:Presenter) and classes,'
				. ' and the mapping of presenter names to classes.',
				Schema::object([
					'name' => Schema::string('Part of the presenter name or class, case-insensitive.'),
				] + Schema::paging(100, 1000)),
			),
			new Tool(
				'presenter',
				'Presenter detail',
				'Shows a presenter: parents, actions, render methods, signals (handle*), components (createComponent*),'
				. ' persistent parameters, injected services, the code of startup() and checkRequirements() with their'
				. ' permission checks, and the templates with their blocks and snippets.',
				Schema::object([
					'name' => Schema::string('Presenter name such as "Admin:Orders", or its class.'),
				], ['name']),
			),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$container = NetteApplication::getContainerOf($context->application);
		if (!interface_exists(self::FactoryType)) {
			throw new InspectorException('The application does not use nette/application.');
		}

		switch ($tool) {
			case 'presenters':
				return $this->listPresenters($container, $arguments, $context->project);
			case 'presenter':
				return $this->showPresenter($container, $arguments->string('name'), $context->project);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	private function listPresenters(Container $container, Arguments $arguments, Project $project): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 100, 1000);
		$factory = $container->getByType(self::FactoryType);
		$presenters = [];
		foreach ($container->findByType(self::PresenterType) as $service) {
			$class = $container->getServiceType($service);
			$name = self::findPresenterName($factory, $class);
			if (!$arguments->has('name') || stripos($name . ' ' . $class, $arguments->string('name')) !== false) {
				$presenters[] = [
					'name' => $name,
					'class' => $class,
					'file' => $project->relativize((string) (new \ReflectionClass($class))->getFileName()),
					'service' => $service,
				];
			}
		}

		usort($presenters, fn(array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));
		$data = [
			'mapping' => self::getMapping($factory),
			'total' => count($presenters),
			'offset' => $offset,
			'presenters' => array_slice($presenters, $offset, $limit),
		];
		return new ToolResult($data, sprintf('%d presenter(s).', count($presenters)));
	}


	private function showPresenter(Container $container, string $name, Project $project): ToolResult
	{
		$class = $this->resolveClass($container, $name);
		$reflection = new \ReflectionClass($class);
		$methods = ['actions' => [], 'renders' => [], 'signals' => [], 'components' => [], 'injects' => []];
		foreach ($reflection->getMethods() as $method) {
			if (
				$method->isStatic()
				|| strncmp($method->getDeclaringClass()->getName(), 'Nette\\', 6) === 0
				|| !preg_match('~^(action|render|handle|createComponent|inject)(\w+)$~', $method->getName(), $m)
			) {
				continue;
			}

			$key = [
				'action' => 'actions',
				'render' => 'renders',
				'handle' => 'signals',
				'createComponent' => 'components',
				'inject' => 'injects',
			][$m[1]];
			if ($key === 'injects' && ($m[2] === 'Primary' || !$method->isPublic())) {
				continue;
			}

			$methods[$key][] = [
				'name' => lcfirst($m[2]),
				'method' => $method->getName(),
				'parameters' => array_map([self::class, 'describeParameter'], $method->getParameters()),
				'returns' => $method->getReturnType() ? self::typeToString($method->getReturnType()) : null,
				'declaredIn' => $method->getDeclaringClass()->getName(),
				'line' => $method->getStartLine(),
			];
		}

		$data = [
			'class' => $class,
			'file' => $project->relativize((string) $reflection->getFileName()),
			'parents' => self::getParents($reflection),
		] + $methods + [
			'persistent' => $this->getPersistentParameters($reflection),
			'injectedProperties' => self::getInjectedProperties($reflection),
			'checks' => $this->getChecks($reflection, $project),
			'templates' => $this->findTemplates($reflection, $project),
		];
		return new ToolResult($data, sprintf(
			'%s: %d action(s), %d render(s), %d signal(s), %d component(s), %d template(s).',
			$class,
			count($methods['actions']),
			count($methods['renders']),
			count($methods['signals']),
			count($methods['components']),
			count($data['templates']),
		));
	}


	/**
	 * @return class-string
	 */
	private function resolveClass(Container $container, string $name): string
	{
		if (class_exists($name)) {
			$class = ltrim($name, '\\');
		} else {
			$factory = $container->getByType(self::FactoryType);
			try {
				$class = $factory->getPresenterClass($name);
			} catch (InvalidPresenterException $e) {
				throw new InspectorException($e->getMessage() . ' Use the tool presenters to list them.');
			}
		}

		if (!is_a($class, self::PresenterType, true)) {
			throw new InspectorException("Class '$class' is not a presenter.");
		}

		return $class;
	}


	/**
	 * The name under which the factory finds the class. unformatPresenterClass() is gone in nette/application 3.3,
	 * so likely names are derived from the namespace and confirmed by formatPresenterClass().
	 */
	private static function findPresenterName(object $factory, string $class): ?string
	{
		$segments = explode('\\', (string) preg_replace('~Presenter$~', '', $class));
		$presenter = array_pop($segments);
		$modules = array_map(
			fn(string $segment) => (string) preg_replace('~Module$~', '', $segment),
			array_filter($segments, fn(string $segment) => (bool) preg_match('~.Module$~', $segment)),
		);
		$candidates = [
			implode(':', array_merge($modules, [$presenter])),
			$presenter,
			implode(':', array_merge(array_slice(array_diff($segments, ['Presenters', 'UI']), 1), [$presenter])),
		];
		foreach (array_unique($candidates) as $name) {
			try {
				if (
					method_exists($factory, 'formatPresenterClass')
					&& ltrim(Scalar::toString($factory->formatPresenterClass($name)), '\\') === $class
				) {
					return $name;
				}
			} catch (InvalidPresenterException $e) {
			}
		}

		return null;
	}


	/**
	 * @return array<mixed>
	 */
	private static function getMapping(object $factory): array
	{
		$mapping = \Closure::bind(fn() => $this->mapping ?? null, $factory, get_class($factory))();
		if ($mapping === null && $factory instanceof PresenterFactory) {
			$mapping = \Closure::bind(fn() => $this->mapping, $factory, PresenterFactory::class)();
		}

		return is_array($mapping) ? $mapping : [];
	}


	/**
	 * @param \ReflectionClass<object> $class
	 * @return list<string>
	 */
	private static function getParents(\ReflectionClass $class): array
	{
		$parents = [];
		for ($parent = $class->getParentClass(); $parent; $parent = $parent->getParentClass()) {
			$parents[] = $parent->getName();
		}

		return $parents;
	}


	/**
	 * @param \ReflectionClass<object> $class
	 * @return list<array{name: string, type: ?string, default: mixed, declaredIn: string}>
	 */
	private function getPersistentParameters(\ReflectionClass $class): array
	{
		$result = [];
		foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
			$attribute = PHP_VERSION_ID >= 80000 && $property->getAttributes('Nette\Application\Attributes\Persistent');
			if (!$property->isStatic() && ($attribute || preg_match('~@persistent\b~', (string) $property->getDocComment()))) {
				$defaults = $property->getDeclaringClass()->getDefaultProperties();
				$name = $property->getName();
				$result[] = [
					'name' => $name,
					'type' => $property->getType() ? self::typeToString($property->getType()) : null,
					'default' => current((array) $this->masking->maskData([$name => $defaults[$name] ?? null])),
					'declaredIn' => $property->getDeclaringClass()->getName(),
				];
			}
		}

		return $result;
	}


	/**
	 * @param \ReflectionClass<object> $class
	 * @return list<array{name: string, type: ?string, declaredIn: string}>
	 */
	private static function getInjectedProperties(\ReflectionClass $class): array
	{
		$result = [];
		foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
			$attribute = PHP_VERSION_ID >= 80000 && $property->getAttributes('Nette\DI\Attributes\Inject');
			if ($attribute || preg_match('~@inject\b~', (string) $property->getDocComment())) {
				preg_match('~@var\s+([\w\\\\|?]+)~', (string) $property->getDocComment(), $m);
				$result[] = [
					'name' => $property->getName(),
					'type' => $property->getType() ? self::typeToString($property->getType()) : ($m[1] ?? null),
					'declaredIn' => $property->getDeclaringClass()->getName(),
				];
			}
		}

		return $result;
	}


	/**
	 * Code of startup() and checkRequirements() in the application classes of the hierarchy,
	 * where login and permission checks usually are.
	 * @param \ReflectionClass<object> $class
	 * @return list<array{method: string, class: string, file: string, line: int, code: string}>
	 */
	private function getChecks(\ReflectionClass $class, Project $project): array
	{
		$result = [];
		for ($current = $class; $current; $current = $current->getParentClass()) {
			if (strncmp($current->getName(), 'Nette\\', 6) === 0) {
				break;
			}

			foreach (['startup', 'checkRequirements', 'beforeRender'] as $name) {
				if (
					$current->hasMethod($name)
					&& ($method = $current->getMethod($name))->getDeclaringClass()->getName() === $current->getName()
				) {
					$file = (string) $method->getFileName();
					$length = $method->getEndLine() - $method->getStartLine() + 1;
					$lines = array_slice((array) @file($file), $method->getStartLine() - 1, $length); // @ unreadable file gives no code
					$result[] = [
						'method' => $name,
						'class' => $current->getName(),
						'file' => $project->relativize($file),
						'line' => (int) $method->getStartLine(),
						'code' => $this->masking->maskText(rtrim(implode('', array_slice($lines, 0, self::SourceLines))))
							. (count($lines) > self::SourceLines ? "\n… " . (count($lines) - self::SourceLines) . ' more lines' : ''),
					];
				}
			}
		}

		return $result;
	}


	/**
	 * Templates in the usual places: templates/<Presenter>/<view>.latte and templates/<Presenter>.<view>.latte,
	 * next to the presenter or one directory up; without a directory of templates, <view>.latte next to the presenter,
	 * as nette/application 3.2 looks for them.
	 * @param \ReflectionClass<object> $class
	 * @return list<array{file: string, view: string, blocks: list<string>, snippets: list<string>}>
	 */
	private function findTemplates(\ReflectionClass $class, Project $project): array
	{
		$short = (string) preg_replace('~Presenter$~', '', $class->getShortName());
		$dir = dirname((string) $class->getFileName());
		$files = [];
		foreach ([$dir, dirname($dir)] as $base) {
			$files = array_merge(
				$files,
				glob("$base/templates/$short/*.latte") ?: [],
				glob("$base/templates/$short.*.latte") ?: [],
			);
		}

		// with more presenters in the directory, nothing tells whose a template is
		if (!is_dir("$dir/templates") && !is_dir(dirname($dir) . '/templates') && count(glob("$dir/*Presenter.php") ?: []) === 1) {
			$files = array_filter(glob("$dir/*.latte") ?: [], fn(string $file): bool => basename($file)[0] !== '@'); // not layouts
		}

		$result = [];
		foreach (array_unique($files) as $file) {
			$code = (string) @file_get_contents($file); // @ unreadable template gives no blocks
			preg_match_all('~\{(?:block|define)\s+#?([\w-]+)~', $code, $blocks);
			preg_match_all('~\{snippet\s+([\w-]+)|n:snippet="([\w-]+)"~', $code, $snippets);
			$result[] = [
				'file' => $project->relativize($file),
				'view' => (string) preg_replace('~^.*[/.]~', '', substr($file, 0, -6)),
				'blocks' => array_values(array_unique($blocks[1])),
				'snippets' => array_values(array_unique(array_filter(array_merge($snippets[1], $snippets[2])))),
			];
		}

		return $result;
	}


	/**
	 * @return array{name: string, type: ?string, optional: bool}
	 */
	private static function describeParameter(\ReflectionParameter $parameter): array
	{
		return [
			'name' => $parameter->getName(),
			'type' => $parameter->getType() ? self::typeToString($parameter->getType()) : null,
			'optional' => $parameter->isOptional(),
		];
	}


	private static function typeToString(\ReflectionType $type): string
	{
		return $type instanceof \ReflectionNamedType
			? ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName()
			: (string) $type;
	}
}
