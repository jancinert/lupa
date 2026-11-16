<?php declare(strict_types=1);

namespace Lupa\Mcp;

use Lupa\Scalar;


/**
 * Checks tool arguments against the subset of JSON Schema the tools use:
 * an object with typed properties, enum, required and additionalProperties: false.
 */
final class ArgumentValidator
{
	/**
	 * @param array<string, mixed> $schema
	 * @param array<mixed> $arguments
	 * @return list<string>  error messages, empty when the arguments are valid
	 */
	public function validate(array $schema, array $arguments): array
	{
		if ($arguments !== [] && array_values($arguments) === $arguments) {
			return ['Arguments must be an object.'];
		}

		$errors = [];
		$properties = (array) ($schema['properties'] ?? []);
		foreach ((array) ($schema['required'] ?? []) as $name) {
			if (is_string($name) && !array_key_exists($name, $arguments)) {
				$errors[] = "Missing required argument '$name'.";
			}
		}

		foreach ($arguments as $name => $value) {
			$property = $properties[$name] ?? null;
			if ($property === null) {
				if (($schema['additionalProperties'] ?? true) === false) {
					$errors[] = "Unknown argument '$name'.";
				}
				continue;
			}

			$property = (array) $property;
			$types = array_filter((array) ($property['type'] ?? []), 'is_string');
			$enum = (array) ($property['enum'] ?? []);
			if ($types && !array_filter($types, fn(string $type) => $this->isOfType($value, $type))) {
				$errors[] = "Argument '$name' must be of type " . implode(' or ', $types) . '.';
			} elseif ($enum && !in_array($value, $enum, true)) {
				$errors[] = "Argument '$name' must be one of: " . implode(', ', array_map([Scalar::class, 'toString'], $enum)) . '.';
			}
		}

		return $errors;
	}


	/**
	 * @param mixed $value
	 */
	private function isOfType($value, string $type): bool
	{
		switch ($type) {
			case 'string':
				return is_string($value);
			case 'integer':
				return is_int($value);
			case 'number':
				return is_int($value) || is_float($value);
			case 'boolean':
				return is_bool($value);
			case 'array':
				return is_array($value) && array_values($value) === $value;
			case 'object':
				return is_array($value);
			case 'null':
				return $value === null;
			default:
				return true;
		}
	}
}
