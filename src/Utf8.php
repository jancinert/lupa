<?php declare(strict_types=1);

namespace Lupa;


final class Utf8
{
	public static function length(string $value): int
	{
		return strlen((string) preg_replace('~[\x80-\xBF]~', '', $value));
	}


	public static function prefix(string $value, int $length): string
	{
		$parts = preg_split('~(?<!^)(?=[^\x80-\xBF])~D', $value, $length + 1) ?: [];
		if (count($parts) > $length) {
			array_pop($parts);
		}

		return implode('', $parts);
	}


	public static function trimWidth(string $value, int $width): string
	{
		if (function_exists('mb_strimwidth')) {
			return mb_strimwidth($value, 0, $width, '…', 'UTF-8');
		}

		return self::length($value) > $width ? self::prefix($value, $width - 1) . '…' : $value;
	}


	public static function cut(string $value, int $bytes): string
	{
		while ($bytes > 0 && isset($value[$bytes]) && (ord($value[$bytes]) & 0xC0) === 0x80) {
			$bytes--;
		}

		return substr($value, 0, $bytes);
	}
}
