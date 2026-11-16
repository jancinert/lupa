<?php declare(strict_types=1);

namespace Lupa\Worker;

use Lupa\OutputBuffer;
use Lupa\Project;


/** Runs one restricted tool inside the current HTTP request, without starting a child process. */
final class HttpExecutor implements Executor
{
	private Project $project;
	private bool $used = false;


	public function __construct(Project $project)
	{
		$this->project = $project;
	}


	public function execute(string $tool, array $arguments): WorkerResponse
	{
		if ($this->used) {
			return WorkerResponse::failure(WorkerResponse::ErrorTool, 'HTTP execution accepts one tool call per request.');
		}

		$this->used = true;
		$level = ob_get_level();
		$displayErrors = ini_get('display_errors');
		$logErrors = ini_get('log_errors');
		$input = fopen('php://temp', 'w+');
		$output = fopen('php://temp', 'w+');
		$nonce = bin2hex(random_bytes(16));
		$handlerInstalled = false;
		try {
			if (!is_resource($input) || !is_resource($output)) {
				throw new \RuntimeException('Cannot open HTTP execution streams.');
			}
			ob_start();
			ini_set('log_errors', '0'); // the HTTP audit records outcomes; PHP diagnostics may contain application secrets
			set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
				if (!(error_reporting() & $severity)) {
					return true;
				}

				throw new \ErrorException($message, 0, $severity, $file, $line);
			});
			$handlerInstalled = true;
			fwrite($input, json_encode(['token' => $nonce, 'tool' => $tool, 'arguments' => $arguments], JSON_THROW_ON_ERROR));
			rewind($input);
			(new Worker($this->project, true))->run($input, $output);
			rewind($output);
			$response = WorkerClient::parseResult((string) stream_get_contents($output), $nonce)
				?? WorkerResponse::failure(WorkerResponse::ErrorCrash, 'HTTP execution failed without a result.');
		} catch (\Throwable $e) {
			$response = WorkerResponse::failure(WorkerResponse::ErrorCrash, 'HTTP execution failed.');
		} finally {
			$cleaned = OutputBuffer::discardAbove($level);

			ini_set('display_errors', (string) $displayErrors);
			ini_set('log_errors', (string) $logErrors);
			if ($handlerInstalled) {
				restore_error_handler();
			}
			if (is_resource($input)) {
				fclose($input);
			}
			if (is_resource($output)) {
				fclose($output);
			}
		}

		return $cleaned ? $response : WorkerResponse::failure(WorkerResponse::ErrorCrash, 'HTTP output buffering could not be restored.');
	}
}
