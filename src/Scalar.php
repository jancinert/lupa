<?php declare(strict_types=1);

namespace Lupa;


/**
 * Reads values of an unknown type, such as cells of a database row or items of decoded configuration.
 */
final class Scalar
{
	/**
	 * The value as text; an empty string for what has no text form (null, an array, a plain object).
	 * @param mixed $value
	 */
	public static function toString($value): string
	{
		return is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))
			? (string) $value
			: '';
	}


	/**
	 * @param mixed $value
	 */
	public static function toInt($value): int
	{
		return is_numeric($value) ? (int) $value : 0;
	}


	/**
	 * Tells whether the value is written in decimal digits only, as ctype_digit() does, without the ctype extension.
	 * @param mixed $value
	 */
	public static function isDigits($value): bool
	{
		$value = is_int($value) ? (string) $value : $value;
		return is_string($value) && $value !== '' && strspn($value, '0123456789') === strlen($value);
	}
}
