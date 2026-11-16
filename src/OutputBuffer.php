<?php declare(strict_types=1);

namespace Lupa;


final class OutputBuffer
{
	public static function discardAbove(int $level): bool
	{
		while (($current = ob_get_level()) > $level) {
			$status = ob_get_status();
			if (!is_int($status['flags'] ?? null) || !($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
				return false;
			}

			try {
				/** @throws \Throwable  Application output handlers run even when their output is discarded. */
				$removed = ob_end_clean();
				if (!$removed || ob_get_level() >= $current) {
					return false;
				}
			} catch (\Throwable $e) {
				return false;
			}
		}

		return ob_get_level() === $level;
	}
}
