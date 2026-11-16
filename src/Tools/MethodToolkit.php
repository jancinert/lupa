<?php declare(strict_types=1);

namespace Lupa\Tools;

use Lupa\InspectorException;


/**
 * A toolkit whose tools are its public methods. The tool is named after the method in snake_case and described
 * by its doc comment. Its arguments are the parameters: string, int, float, bool or array (a list), optional
 * when they have a default value, described by their @param lines. A parameter typed AppContext receives
 * the booted application. A method tagged @active is a tool with side effects, one tagged @untrusted
 * returns data of the application that may hold user input. The method returns
 * a ToolResult, an array, or an object of a class whose public properties then give the schema of the result:
 * their types, nested classes, and the items of an array from @var list<Type>.
 */
abstract class MethodToolkit implements Toolkit
{
	private const Types = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => 'boolean', 'array' => 'array'];


	public function getTools(): array
	{
		$tools = [];
		foreach ($this->getMethods() as $name => $method) {
			$doc = (string) preg_replace('~^\s*(/\*\*|\*/|\*)[ \t]?|\s*\*/$~m', '', (string) $method->getDocComment());
			$description = trim((string) preg_replace('~\s+~', ' ', (string) preg_replace('~^@.*~ms', '', $doc)));
			if ($description === '') {
				throw new InspectorException("Tool '$name' needs a doc comment with its description.");
			}

			$properties = $required = [];
			foreach ($method->getParameters() as $parameter) {
				if (self::isContext($parameter)) {
					continue;
				}

				$type = $parameter->getType();
				$schema = ['type' => self::Types[$type instanceof \ReflectionNamedType ? $type->getName() : ''] ?? null];
				if ($schema['type'] === null) {
					throw new InspectorException("Parameter \$$parameter->name of tool '$name' must be a string, int, float, bool or array.");
				} elseif (preg_match('~^@param\s.*\$' . $parameter->name . '[ \t]+(\S.*)~m', $doc, $m)) {
					$schema['description'] = trim($m[1]);
				}

				$properties[$parameter->name] = $schema;
				if (!$parameter->isDefaultValueAvailable()) {
					$required[] = $parameter->name;
				}
			}

			$tool = new Tool($name, ucfirst(str_replace('_', ' ', $name)), $description, Schema::object($properties, $required));
			$return = $method->getReturnType();
			$class = $return instanceof \ReflectionNamedType ? $return->getName() : '';
			if ($class !== ToolResult::class && class_exists($class)) {
				$tool->outputSchema = self::describeClass($class);
			}

			$tool->readOnly = !preg_match('~^@active\b~m', $doc);
			$tool->untrusted = (bool) preg_match('~^@untrusted\b~m', $doc);
			$tools[] = $tool;
		}

		return $tools;
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$method = $this->getMethods()[$tool] ?? null;
		if ($method === null) {
			throw new InspectorException("Unknown tool '$tool'.");
		}

		$values = [];
		foreach ($method->getParameters() as $parameter) {
			if (self::isContext($parameter)) {
				$values[] = $context;
			} elseif (array_key_exists($parameter->name, $arguments)) {
				$values[] = $arguments[$parameter->name];
			} else {
				$values[] = $parameter->getDefaultValue();
			}
		}

		$result = $method->invokeArgs($this, $values);
		if (is_object($result) && !$result instanceof ToolResult) {
			$result = self::export($result);
		}

		if (is_array($result)) {
			$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
			return new ToolResult($result, (string) json_encode($result, $flags));
		} elseif (!$result instanceof ToolResult) {
			throw new InspectorException("Tool '$tool' must return an array, an object or a ToolResult.");
		}

		return $result;
	}


	/**
	 * @return array<string, \ReflectionMethod>  by tool name
	 */
	private function getMethods(): array
	{
		$methods = [];
		foreach ((new \ReflectionClass($this))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if (
				!$method->isStatic()
				&& $method->getDeclaringClass()->getName() !== self::class
				&& strncmp($method->name, '__', 2) !== 0
			) {
				$methods[strtolower((string) preg_replace('~(?<!^)[A-Z]~', '_$0', $method->name))] = $method;
			}
		}

		return $methods;
	}


	private static function isContext(\ReflectionParameter $parameter): bool
	{
		$type = $parameter->getType();
		return $type instanceof \ReflectionNamedType && $type->getName() === AppContext::class;
	}


	/**
	 * @param class-string $class
	 * @return array<string, mixed>  JSON Schema of an object of the class
	 */
	private static function describeClass(string $class, int $depth = 0): array
	{
		$properties = [];
		foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic()) {
				continue;
			}

			$type = $property->getType();
			$name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
			$namespace = $property->getDeclaringClass()->getNamespaceName();
			$items = '[\w\\\\]+';
			$schema = $name === 'array'
				&& preg_match("~@var\\s+\\??(?:list<)?($items)(?:>|\\[\\])~", (string) $property->getDocComment(), $m)
				? Schema::listOf(self::describeType($m[1], $namespace, $depth))
				: self::describeType($name, $namespace, $depth);
			if ($type !== null && $type->allowsNull() && isset($schema['type'])) {
				$schema['type'] = array_merge((array) $schema['type'], ['null']);
			}

			$properties[$property->name] = $schema;
		}

		return ['type' => 'object', 'properties' => $properties ?: new \stdClass];
	}


	/**
	 * @return array<string, mixed>  JSON Schema of a value of the type; empty when the type says nothing
	 */
	private static function describeType(string $type, string $namespace, int $depth): array
	{
		if ($type === 'array') {
			return ['type' => ['array', 'object']];
		} elseif (isset(self::Types[$type])) {
			return ['type' => self::Types[$type]];
		}

		foreach ([$type, $namespace . '\\' . $type] as $class) {
			if (class_exists($class)) {
				return $depth < 5 ? self::describeClass($class, $depth + 1) : ['type' => 'object'];
			}
		}

		return [];
	}


	/**
	 * Objects as arrays of their public properties.
	 * @param mixed $value
	 * @return mixed
	 */
	private static function export($value)
	{
		if (is_object($value)) {
			$value = get_object_vars($value);
		}

		return is_array($value) ? array_map([self::class, 'export'], $value) : $value;
	}
}
