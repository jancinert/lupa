<?php declare(strict_types=1);

namespace Lupa\Worker;

use Lupa\Masking;
use Lupa\Project;


/**
 * Runs each tool call in a fresh worker process: the code is always current, a crash of the application
 * does not stop the server, and a call that takes too long is killed.
 */
final class WorkerClient implements Executor
{
	private const DiagnosticsLength = 2000;

	private string $phpBinary;
	private string $script;
	private Project $project;
	private int $timeout;
	private Masking $masking;


	public function __construct(string $phpBinary, string $script, Project $project, int $timeout, ?Masking $masking = null)
	{
		$this->phpBinary = $phpBinary;
		$this->script = $script;
		$this->project = $project;
		$this->timeout = $timeout;
		$this->masking = $masking ?? new Masking;
	}


	public function execute(string $tool, array $arguments): WorkerResponse
	{
		$token = bin2hex(random_bytes(8));
		$files = [
			'in' => tempnam(sys_get_temp_dir(), 'aii'),
			'out' => tempnam(sys_get_temp_dir(), 'aio'),
			'err' => tempnam(sys_get_temp_dir(), 'aie'),
		];
		try {
			file_put_contents($files['in'], json_encode([
				'token' => $token,
				'tool' => $tool,
				'arguments' => $arguments ?: new \stdClass,
			]));
			return $this->runProcess($tool, $token, $files);
		} finally {
			foreach ($files as $file) {
				@unlink($file); // @ the file may already be gone
			}
		}
	}


	/**
	 * @param array{in: string, out: string, err: string} $files
	 */
	private function runProcess(string $tool, string $token, array $files): WorkerResponse
	{
		$command = [$this->phpBinary, $this->script, 'worker'];
		foreach ($this->project->toOptions() as $name => $value) {
			$command[] = "--$name";
			$command[] = $value;
		}

		$process = proc_open(
			$command,
			[0 => ['file', $files['in'], 'r'], 1 => ['file', $files['out'], 'w'], 2 => ['file', $files['err'], 'w']],
			$pipes,
			null,
			null,
			['bypass_shell' => true],
		);
		if (!is_resource($process)) {
			return WorkerResponse::failure(WorkerResponse::ErrorCrash, 'Cannot start the worker process.');
		}

		$deadline = microtime(true) + $this->timeout;
		while (($status = proc_get_status($process))['running']) {
			if (microtime(true) > $deadline) {
				proc_terminate($process, 9);
				proc_close($process);
				return WorkerResponse::failure(
					WorkerResponse::ErrorTimeout,
					"Tool '$tool' did not finish within {$this->timeout} s and was stopped.",
				);
			}

			usleep(10000);
		}

		proc_close($process);
		$output = (string) file_get_contents($files['out']);
		return self::parseResult($output, $token)
			?? WorkerResponse::failure(
				WorkerResponse::ErrorCrash,
				"The worker ended without a result (exit code {$status['exitcode']}).\n"
				. self::tail($this->masking->maskText((string) file_get_contents($files['err']) . $output)),
			);
	}


	public static function parseResult(string $output, string $token): ?WorkerResponse
	{
		$marker = Worker::ResultMarker . $token . "\n";
		$pos = strrpos($output, $marker);
		if ($pos === false) {
			return null;
		}

		$line = strtok(substr($output, $pos + strlen($marker)), "\n");
		return WorkerResponse::fromArray(json_decode((string) $line, true));
	}


	private static function tail(string $text): string
	{
		$text = trim($text);
		return strlen($text) > self::DiagnosticsLength
			? '…' . substr($text, -self::DiagnosticsLength)
			: $text;
	}
}
