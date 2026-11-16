<?php declare(strict_types=1);

namespace Lupa\Tools;

use Lupa\InspectorException;


/**
 * Arguments of a tool call, read by type. The server checks them against the input schema of the tool;
 * a toolkit called another way gets the same answer for a value of a wrong type.
 */
final class Arguments
{
	/** @var array<mixed> */
	private array $values;


	/**
	 * @param array<mixed> $values
	 */
	public function __construct(array $values)
	{
		$this->values = $values;
	}


	public function has(string $name): bool
	{
		return isset($this->values[$name]);
	}


	/**
	 * @return mixed
	 */
	public function raw(string $name)
	{
		return $this->values[$name] ?? null;
	}


	public function string(string $name, ?string $default = null): string
	{
		$value = $this->optionalString($name) ?? $default;
		if ($value === null) {
			throw new InspectorException("Argument '$name' is required.");
		}

		return $value;
	}


	public function optionalString(string $name): ?string
	{
		$value = $this->values[$name] ?? null;
		if ($value !== null && !is_string($value)) {
			throw new InspectorException("Argument '$name' must be a string.");
		}

		return $value;
	}


	public function int(string $name, ?int $default = null): int
	{
		$value = $this->values[$name] ?? $default;
		if (!is_int($value)) {
			throw new InspectorException("Argument '$name' must be an integer.");
		}

		return $value;
	}


	public function bool(string $name, bool $default = false): bool
	{
		$value = $this->values[$name] ?? $default;
		if (!is_bool($value)) {
			throw new InspectorException("Argument '$name' must be a boolean.");
		}

		return $value;
	}


	/**
	 * @return array<mixed>  empty when the argument is missing
	 */
	public function items(string $name): array
	{
		$value = $this->values[$name] ?? [];
		if (!is_array($value)) {
			throw new InspectorException("Argument '$name' must be an array or an object.");
		}

		return $value;
	}
}
