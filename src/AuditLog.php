<?php declare(strict_types=1);

namespace Lupa;


/**
 * Appends one JSON line per tool call. A log over MaxBytes moves to a file with the suffix .1, replacing the
 * previous one, so that the two files together never take much more than twice the limit.
 */
final class AuditLog
{
	public const MaxBytes = 10_000_000;

	private string $file;
	private Masking $masking;


	public function __construct(string $file, Masking $masking)
	{
		$this->file = $file;
		$this->masking = $masking;
	}


	/**
	 * Creates the directory and verifies the file can be written, so that calls are never left unrecorded.
	 */
	public function open(): void
	{
		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) { // @ the error is reported below
			throw new InspectorException("Cannot create audit log directory '$dir'.");
		}

		if (@file_put_contents($this->file, '', FILE_APPEND | LOCK_EX) === false) { // @ the error is reported below
			throw new InspectorException("Audit log '$this->file' is not writable.");
		}
	}


	/**
	 * @param array<mixed> $arguments
	 */
	public function write(string $source, string $tool, array $arguments, bool $ok, ?string $error, int $durationMs): void
	{
		$entry = [
			'time' => date('c'),
			'source' => $source,
			'tool' => $tool,
			'arguments' => $this->masking->maskData($arguments),
			'ok' => $ok,
			'error' => $error,
			'durationMs' => $durationMs,
		];
		$line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		clearstatcache(true, $this->file);
		if (@filesize($this->file) > self::MaxBytes) { // @ the file may not exist yet
			$this->rotate();
		}

		if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) { // @ the error is reported below
			throw new InspectorException("Cannot write to audit log '$this->file'.");
		}
	}


	/**
	 * Moves the full log aside under a lock, so that a second process cannot move the new log over it,
	 * and starts the new log with the permissions of the old one, which the HTTP mode requires to be private.
	 */
	private function rotate(): void
	{
		$lock = @fopen($this->file . '.rotate', 'c'); // @ the error is reported below
		if ($lock === false || !flock($lock, LOCK_EX)) {
			throw new InspectorException("Cannot lock audit log '$this->file' for rotation.");
		}

		try {
			clearstatcache(true, $this->file);
			$mode = (int) @fileperms($this->file); // @ another process may have rotated it meanwhile
			$full = @filesize($this->file) > self::MaxBytes; // @ another process may have rotated it meanwhile
			if ($full && @rename($this->file, $this->file . '.1')) { // @ on Windows a writer may hold it open, the next call retries
				touch($this->file);
				chmod($this->file, $mode & 0777);
			}
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
