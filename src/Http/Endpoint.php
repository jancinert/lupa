<?php declare(strict_types=1);

namespace Lupa\Http;

use Lupa\AuditLog;
use Lupa\Config;
use Lupa\Inspector;
use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Mcp\Server;
use Lupa\OutputBuffer;
use Lupa\Project;
use Lupa\Tools\ToolCatalog;
use Lupa\Worker\HttpExecutor;


final class Endpoint
{
	private Project $project;
	private string $tokenHash;

	/** @var list<string> */
	private array $origins;

	/** @var resource|null */
	private $lock;
	private ?AuditLog $audit = null;

	/** @var ?\Closure(): void */
	private ?\Closure $beforeExecution = null;
	private bool $responseReady = false;
	private bool $responseEmitted = false;
	private ?int $wireStatus = null;


	/** @param array<string> $origins */
	public function __construct(Project $project, string $tokenHash, array $origins = [])
	{
		$this->project = $project;
		$this->tokenHash = $tokenHash;
		$this->origins = array_values($origins);
	}


	/** @param array<string, mixed> $headers */
	public function handle(string $method, array $headers, string $body, bool $secure): Response
	{
		$mask = umask(0077);
		try {
			return $this->createHandler()->handle($method, $headers, $body, $secure);
		} catch (\Throwable $e) {
			return Response::error(503, 'The MCP endpoint is unavailable.', -32603);
		} finally {
			if (is_resource($this->lock)) {
				flock($this->lock, LOCK_UN);
				fclose($this->lock);
				$this->lock = null;
			}

			umask($mask);
		}
	}


	/**
	 * Serves one HTTP request, discarding application output and replacing fatal errors with a fixed response.
	 * @param resource|null $input  defaults to php://input
	 */
	public function serve($input = null): void
	{
		$response = null;
		$this->wireStatus = null;
		$this->responseReady = $this->responseEmitted = false;
		$reserve = str_repeat('x', 65_536);
		ini_set('display_errors', '0');
		ini_set('log_errors', '0'); // includes autoloader and configuration failures before a worker exists
		// A lower buffer could retain a vetted reply and lose it on a later out-of-memory error.
		if (!OutputBuffer::discardAbove(0)) {
			throw new InspectorException('HTTP execution requires control of output buffering.');
		}
		$level = 1;
		$fallback = static fn(): Response => Response::error(500, 'HTTP tool execution ended without a response.', -32603);
		register_shutdown_function(function () use (&$reserve, &$response, &$level, &$filter, $fallback): void {
			$reserve = '';
			// Out-of-memory errors discard every output buffer without calling its handler.
			if (ob_get_level() < $level) {
				ob_start($filter, 8192, PHP_OUTPUT_HANDLER_FLUSHABLE);
				$level = ob_get_level();
			}
			OutputBuffer::discardAbove($level);
			if (!$this->responseReady) {
				$response = $fallback();
				$this->responseReady = true;
				if (ob_get_level() === $level) {
					ob_flush();
					flush();
				}

				try {
					if ($this->audit !== null) {
						$this->audit->write('http', 'request', [], false, 'execution ended', 0);
					}
				} catch (\Throwable $e) {
					// A failed audit must not cause diagnostics to appear in the HTTP response.
				}
			}
		});
		$sendHeaders = static function () use (&$response, $fallback): void {
			$response ??= $fallback();
			$response->sendHeaders();
		};
		header_register_callback($sendHeaders);
		// Nonremovable; only a completed response or the final fixed failure may leave this buffer.
		$filter = function (string $output, int $phase) use (&$response, $fallback, $sendHeaders): string {
			// PHP 8.1 calls the handler when an out-of-memory error discards the buffer and throws its result away.
			if (
				$this->responseEmitted
				|| ($phase & PHP_OUTPUT_HANDLER_CLEAN)
				|| (!$this->responseReady && !($phase & PHP_OUTPUT_HANDLER_FINAL))
			) {
				return '';
			}

			if ($response === null || !$this->responseReady) {
				$response = $fallback();
			}

			if (!headers_sent()) {
				header_register_callback($sendHeaders);
				$response->sendHeaders();
			}

			$this->responseEmitted = true;
			return $this->wireStatus === 202 || ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD' ? '' : $response->body;
		};
		ob_start($filter, 8192, PHP_OUTPUT_HANDLER_FLUSHABLE);
		$level = ob_get_level();
		ob_start();
		try {
			$headers = [];
			foreach ($_SERVER as $name => $value) {
				if (is_string($value) && strncmp($name, 'HTTP_', 5) === 0) {
					$headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
				}
			}

			foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $name => $header) {
				if (is_string($_SERVER[$name] ?? null)) {
					$headers[$header] = $_SERVER[$name];
				}
			}

			if (!isset($headers['authorization']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
				$headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			}

			$secure = in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true) || ($_SERVER['SERVER_PORT'] ?? '') === '443';
			$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
			$response = $this->createHandler()->authorize($headers, $secure); // the body of a refused request is never read
			if ($response === null) {
				$body = self::readBody($input);
				$this->beforeExecution = function () use (&$response, $body, $sendHeaders): void {
					// Commit safe headers before application code can replace callbacks or flush its own headers.
					$message = json_decode($body, true, 32); // RequestHandler has already validated this exact body.
					$this->wireStatus = is_array($message) && !array_key_exists('id', $message) ? 202 : 200;
					$response = new Response($this->wireStatus);
					$sendHeaders();
					flush();
				};
				$response = $this->handle($method, $headers, $body, $secure);
			}
		} catch (\Throwable $e) {
			$response = Response::error(503, 'The MCP endpoint is unavailable.', -32603);
		}

		if (!OutputBuffer::discardAbove($level)) {
			$response = Response::error(503, 'HTTP output buffering could not be restored.', -32603);
		}
		$this->beforeExecution = null;
		header_register_callback($sendHeaders);
		$this->responseReady = true;
		// A nonremovable application buffer is drained through our filter by PHP at shutdown.
		if (ob_get_level() === $level) {
			ob_flush();
			flush();
		}
	}


	private function createHandler(): RequestHandler
	{
		return new RequestHandler($this->tokenHash, $this->origins, fn(): Server => $this->createServer());
	}


	/**
	 * Reads at most one byte over the limit, so that RequestHandler can refuse a larger body.
	 * @param resource|null $input
	 */
	private static function readBody($input): string
	{
		$stream = $input ?? fopen('php://input', 'r');
		if (!is_resource($stream)) {
			throw new InspectorException('Cannot open the HTTP request body.');
		}

		$body = stream_get_contents($stream, RequestHandler::MaxBodyBytes + 1);
		if ($input === null) {
			fclose($stream);
		}

		if ($body === false) {
			throw new InspectorException('Cannot read the HTTP request body.');
		}

		return $body;
	}


	private function createServer(): Server
	{
		if (Inspector::isDisabledByEnvironment()) {
			throw new InspectorException('Lupa is disabled.');
		}

		if ($this->beforeExecution !== null) {
			($this->beforeExecution)();
		}
		$this->project->loadAutoloader();
		$container = Inspector::createContainer($this->project->configFile);
		$config = $container->getByType(Config::class);
		if (!$config->enabled || $config->framework !== 'none') {
			throw new InspectorException('HTTP execution requires framework: none and an enabled inspector.');
		}

		$audit = $container->getByType(AuditLog::class);
		$audit->open();
		$this->audit = $audit;
		$this->project->resolveInside($config->auditLog);
		$cache = Inspector::getTempDirectory($this->project->configFile);
		foreach ([$config->auditLog, dirname($config->auditLog), $cache, dirname($cache)] as $file) {
			self::checkPrivatePath($file);
		}

		$lockFile = $config->auditLog . '.http-limit.lock';
		if (is_link($lockFile)) {
			throw new InspectorException('HTTP execution requires a private lock file.');
		}

		$lock = fopen($lockFile, 'c+');
		if (!is_resource($lock)) {
			throw new InspectorException('Cannot open the HTTP execution lock.');
		}
		$this->lock = $lock;
		if (!flock($lock, LOCK_EX | LOCK_NB)) {
			throw new \RuntimeException('HTTP execution is busy.', 429);
		}

		self::checkPrivatePath($lockFile);
		// the times of the requests in the last minute, so that no 60 seconds hold more than 60 requests
		$stored = stream_get_contents($lock, 2048);
		$times = $stored === '' ? [] : json_decode((string) $stored, true);
		if (
			!is_array($times)
			|| $times !== array_values($times)
			|| array_filter($times, fn($time): bool => !is_int($time) && !is_float($time))
		) {
			throw new InspectorException('The HTTP request counter is invalid.');
		}
		$now = round(microtime(true), 3);
		$times = array_values(array_filter($times, fn($time): bool => $time > $now - 60));
		if (count($times) >= 60) {
			throw new \RuntimeException('HTTP request limit reached.', 429);
		}

		rewind($this->lock);
		ftruncate($this->lock, 0);
		$times[] = $now;
		$record = json_encode($times, JSON_THROW_ON_ERROR);
		if (fwrite($this->lock, $record) !== strlen($record)) {
			throw new InspectorException('Cannot save the HTTP request counter.');
		}
		fflush($this->lock);
		$masking = $container->getByType(Masking::class);
		return new Server(
			ToolPolicy::filter($container->getByType(ToolCatalog::class)),
			new HttpExecutor($this->project),
			$audit,
			$masking->maskText($config->instructions)
			. "\nHTTP mode offers application and SQLite metadata only; arbitrary SQL and active tools are unavailable.",
			$masking,
		);
	}


	private static function checkPrivatePath(string $file): void
	{
		if (is_link($file) || (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0)) {
			throw new InspectorException('HTTP execution requires private directories and files.');
		} elseif (function_exists('posix_geteuid') && fileowner($file) !== posix_geteuid()) {
			throw new InspectorException('HTTP execution requires paths owned by the PHP user.');
		}
	}
}
