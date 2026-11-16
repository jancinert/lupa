<?php declare(strict_types=1);

namespace Lupa\Latte;


/**
 * Maps a line of a compiled Latte template back to the .latte source. Latte 2 and 3 both write
 * the source path in a "source:" comment (a line comment before Latte 2.10) and mark the generated code
 * with "line N" (Latte 3.1: "pos N:M") comments.
 */
final class TemplateMapper
{
	/**
	 * @return ?array{source: string, line: ?int, column: ?int}  null when the file is not a compiled template
	 */
	public static function map(string $compiledFile, int $line): ?array
	{
		$lines = @file($compiledFile); // @ a missing file is reported by the caller
		if ($lines === false || !preg_match('~/\*\* source: (.+?) \*/|^// source: (.+)$~m', implode('', array_slice($lines, 0, 20)), $m)) {
			return null;
		}

		$sourceLine = $column = null;
		$index = min($line, count($lines)) - 1;
		for ($i = $index; $i >= 0; $i--) {
			if (preg_match_all('~/\* (?:line|pos) (\d+)(?::(\d+))? \*/~', $lines[$i], $markers, PREG_SET_ORDER)) {
				$marker = end($markers);
				$sourceLine = (int) $marker[1];
				$column = isset($marker[2]) ? (int) $marker[2] : null;
				break;
			}
		}

		return ['source' => trim($m[2] ?? $m[1]), 'line' => $sourceLine, 'column' => $column];
	}


	/**
	 * Lines around the given one, numbered, with the line itself marked by ">".
	 */
	public static function getExcerpt(string $file, int $line, int $radius = 3): ?string
	{
		$lines = @file($file); // @ the source may not exist in this environment
		if ($lines === false) {
			return null;
		}

		$result = [];
		for ($i = max(1, $line - $radius); $i <= min(count($lines), $line + $radius); $i++) {
			$result[] = ($i === $line ? '>' : ' ') . str_pad((string) $i, 5, ' ', STR_PAD_LEFT) . ': ' . rtrim($lines[$i - 1]);
		}

		return implode("\n", $result);
	}
}
