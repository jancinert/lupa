<?php declare(strict_types=1);

namespace Lupa\Tools;

use Lupa\InspectorException;
use Lupa\Scalar;


/**
 * Shorthands for the JSON Schemas of tool arguments and results, and for reading paging arguments.
 */
final class Schema
{
	/**
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 * @return array<string, mixed>
	 */
	public static function object(array $properties, array $required = [], bool $closed = true): array
	{
		$schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass];
		if ($required) {
			$schema['required'] = $required;
		}

		if ($closed) {
			$schema['additionalProperties'] = false;
		}

		return $schema;
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function string(string $description): array
	{
		return ['type' => 'string', 'description' => $description];
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function integer(string $description): array
	{
		return ['type' => 'integer', 'description' => $description];
	}


	/**
	 * @param array<string, mixed> $items
	 * @return array<string, mixed>
	 */
	public static function listOf(array $items): array
	{
		return ['type' => 'array', 'items' => $items];
	}


	/**
	 * The JSON Schema of a result from a short notation. A string is a type (string, integer, number, boolean,
	 * object, array, several joined by |, or mixed), with ? in front when the value may be null and [] behind
	 * for a list. An array of properties is an object, an array with one item a list of such items.
	 * Objects stay open: a result may carry more than its schema names.
	 * @param string|array<mixed> $shape
	 * @return array<string, mixed>
	 */
	public static function result($shape): array
	{
		if (is_array($shape)) {
			$properties = [];
			foreach ($shape as $name => $inner) {
				$properties[$name] = self::result(is_array($inner) ? $inner : Scalar::toString($inner));
			}

			return array_keys($shape) === [0]
				? self::listOf($properties[0])
				: ['type' => 'object', 'properties' => $properties ?: new \stdClass];

		} elseif (substr($shape, -2) === '[]') {
			return self::listOf(self::result(substr($shape, 0, -2)));
		}

		$types = $shape === 'mixed'
			? ['string', 'number', 'boolean', 'object', 'array', 'null']
			: explode('|', ltrim($shape, '?'));
		if ($shape[0] === '?') {
			$types[] = 'null';
		}

		return ['type' => count($types) === 1 ? $types[0] : $types];
	}


	/**
	 * @return array<string, array<string, mixed>>  the limit and offset arguments
	 */
	public static function paging(int $default, int $max): array
	{
		return [
			'limit' => self::integer("Maximum number of items, default $default, at most $max."),
			'offset' => self::integer('Number of items to skip, for the next page.'),
		];
	}


	/**
	 * @return array{int, int}  limit and offset
	 */
	public static function getPaging(Arguments $arguments, int $default, int $max): array
	{
		$limit = $arguments->int('limit', $default);
		$offset = $arguments->int('offset', 0);
		if ($limit < 1 || $limit > $max || $offset < 0) {
			throw new InspectorException("Argument 'limit' must be between 1 and $max and 'offset' must not be negative.");
		}

		return [$limit, $offset];
	}


	public static function getLimit(Arguments $arguments, string $name, int $default, int $max): int
	{
		$limit = $arguments->int($name, $default);
		if ($limit < 1 || $limit > $max) {
			throw new InspectorException("Argument '$name' must be between 1 and $max.");
		}

		return $limit;
	}
}
