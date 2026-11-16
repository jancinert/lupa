<?php declare(strict_types=1);

namespace Lupa\Worker;

use Lupa\Application;
use Lupa\Config;
use Lupa\Guard\DatabaseTarget;
use Lupa\Guard\EnvironmentGuard;
use Lupa\GuardException;
use Lupa\Http\ToolPolicy;
use Lupa\Inspector;
use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Nette\Toolkits\RenderToolkit;
use Lupa\OutputBuffer;
use Lupa\Plain\PlainApplication;
use Lupa\Project;
use Lupa\Tools\AppContext;
use Lupa\Tools\Tool;
use Lupa\Tools\ToolCatalog;
use Lupa\Tools\Toolkit;


/**
 * Worker side of one tool call: boots the application, checks the environment, runs the tool
 * (a tool that reads only files runs also when the application does not start)
 * and writes the result to the output after a marker, so that output of the application cannot be mistaken for it.
 */
final class Worker
{
	public const ResultMarker = '@@lupa-result:';

	private Project $project;
	private bool $http;

	/** @var resource */
	private $output;

	private string $token = '';
	private bool $emitted = false;
	private ?Masking $masking = null;

	/** @var list<string> */
	private array $identifierMaps = [];

	/** @var list<string> */
	private array $identifierValues = [];

	/**
	 * Runs a tool that reads only files when booting the application ends the process, by exit() or a fatal error.
	 * @var ?\Closure(string): WorkerResponse
	 */
	private ?\Closure $withoutApplication = null;


	public function __construct(Project $project, bool $http = false)
	{
		$this->project = $project;
		$this->http = $http;
	}


	/**
	 * @param resource $input  JSON request {token, tool, arguments}
	 * @param resource $output
	 */
	public function run($input, $output): int
	{
		$this->output = $output;
		$this->hideErrors();
		register_shutdown_function(function (): void {
			if ($this->http || $this->emitted) {
				return;
			}

			$error = error_get_last(); // also a warning silenced by @ long before, so only an error that ends the process counts
			$message = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
				? ($this->masking
					? $this->masking->maskText($error['message']) . " in {$error['file']}:{$error['line']}"
					: 'The worker failed before its masking policy was loaded.')
				: null;
			if ($this->withoutApplication !== null) {
				while (ob_get_level() > 0) {
					ob_end_clean();
				}

				$this->emit(
					($this->withoutApplication)('Booting the application ended the process' . ($message === null ? '.' : ": $message")),
				);

			} else {
				$this->emit(WorkerResponse::failure(WorkerResponse::ErrorCrash, $message ?? 'The worker ended without a result.'));
			}
		});

		$request = json_decode((string) stream_get_contents($input), true);
		if (!is_array($request) || !is_string($request['token'] ?? null) || !is_string($request['tool'] ?? null)) {
			$this->emit(WorkerResponse::failure(WorkerResponse::ErrorCrash, 'Invalid worker request.'));
			return 1;
		}

		$this->token = $request['token'];
		$arguments = is_array($request['arguments'] ?? null) ? $request['arguments'] : [];
		$response = $this->execute($request['tool'], $arguments);
		$this->emit($response);
		return $response->ok ? 0 : 1;
	}


	/**
	 * @param array<mixed> $arguments
	 */
	private function execute(string $tool, array $arguments): WorkerResponse
	{
		$masking = null;
		$response = $this->executeTool($tool, $arguments, $masking);
		// exception messages of the application may quote data, e.g. a value in a failed SQL statement
		return !$response->ok && $masking
			? WorkerResponse::failure((string) $response->errorKind, $masking->maskText($response->text))
			: $response;
	}


	/**
	 * @param array<mixed> $arguments
	 */
	private function executeTool(string $tool, array $arguments, ?Masking &$masking): WorkerResponse
	{
		$level = ob_get_level();
		ob_start(); // output of the application must not reach the result channel
		try {
			$this->project->loadAutoloader();
			if (Inspector::isDisabledByEnvironment()) {
				return WorkerResponse::failure(WorkerResponse::ErrorTool, 'Lupa is disabled.');
			}

			$container = Inspector::createContainer($this->project->configFile);
			$masking = $this->masking = $container->getByType(Masking::class);
			$config = $container->getByType(Config::class);
			if (!$config->enabled) {
				return WorkerResponse::failure(WorkerResponse::ErrorTool, 'Lupa is disabled.');
			}

			$catalog = $container->getByType(ToolCatalog::class);
			if ($this->http && ($config->framework !== 'none' || !ToolPolicy::allows($catalog, $tool))) {
				return WorkerResponse::failure(WorkerResponse::ErrorTool, 'This operation is not supported in HTTP mode.');
			}
			if ($this->http) {
				$catalog = ToolPolicy::filter($catalog);
			}
			$toolkit = $catalog->getToolkit($tool);
			if ($toolkit === null) {
				return WorkerResponse::failure(WorkerResponse::ErrorTool, "Unknown tool '$tool'.");
			}

			$definition = $catalog->getTool($tool);
			$needsApplication = $definition === null || $definition->needsApplication;
			$deferInitialization = $toolkit instanceof RenderToolkit;
			$lastStart = Inspector::getTempDirectory($this->project->configFile) . '/last-start.json';
			$this->withoutApplication = $needsApplication
				? null
				: function (string $reason) use ($toolkit, $catalog, $masking, $tool, $arguments, $lastStart): WorkerResponse {
					try {
						$application = UnbootedApplication::fromLastStart($lastStart, $this->project, $reason);
						return $this->runTool($toolkit, $catalog, $masking, $tool, $arguments, $application, []);
					} catch (\Throwable $e) {
						$kind = $e instanceof InspectorException ? WorkerResponse::ErrorTool : WorkerResponse::ErrorCrash;
						return WorkerResponse::failure(
							$kind,
							$masking->maskText($e instanceof InspectorException ? $e->getMessage() : self::describe($e)),
						);
					}
				};
			try {
				$application = $config->framework === 'none'
					? PlainApplication::boot($config->bootstrap)
					: NetteApplication::boot($config->bootstrap, $config->applicationConfigs, $deferInitialization);
				$databases = $application->getDatabaseTargets();
				if ($this->http && $application instanceof PlainApplication) {
					$application->restrictConnectionsToSqlite($this->project);
					$databases = $application->getDatabaseTargets();
				}
				UnbootedApplication::rememberDirectories($application, $lastStart);
			} catch (\Throwable $e) {
				$reason = 'Booting the application failed: ' . self::describe($e);
				return $this->withoutApplication === null
					? WorkerResponse::failure(WorkerResponse::ErrorBoot, $reason)
					: ($this->withoutApplication)($reason);
			} finally {
				$this->withoutApplication = null;
				$this->hideErrors();
			}

			try {
				$container->getByType(EnvironmentGuard::class)->check($databases);
			} catch (GuardException $e) {
				return WorkerResponse::failure(WorkerResponse::ErrorGuard, $e->getMessage());
			}

			if ($needsApplication && !$deferInitialization) {
				try {
					$application->initialize();
				} catch (\Throwable $e) {
					return WorkerResponse::failure(WorkerResponse::ErrorBoot, 'Initializing the application failed: ' . self::describe($e));
				}
			}

			return $this->runTool($toolkit, $catalog, $masking, $tool, $arguments, $application, $databases);

		} catch (InspectorException $e) {
			return WorkerResponse::failure(WorkerResponse::ErrorTool, $e->getMessage());
		} catch (\Throwable $e) {
			return WorkerResponse::failure(WorkerResponse::ErrorCrash, self::describe($e));
		} finally {
			if ($this->http) {
				if (!OutputBuffer::discardAbove($level)) {
					throw new InspectorException('Application output buffering could not be restored.');
				}
			} else {
				while (ob_get_level() > $level) {
					ob_end_clean();
				}
			}
		}
	}


	/**
	 * Keeps PHP from writing errors into the output, which in HTTP is the body of the response; the application
	 * may turn display_errors on in its bootstrap, and a fatal error such as exhausted memory passes no buffer.
	 */
	private function hideErrors(): void
	{
		ini_set('display_errors', $this->http ? '0' : 'stderr');
	}


	/**
	 * @param array<mixed> $arguments
	 * @param list<DatabaseTarget> $databases
	 */
	private function runTool(
		Toolkit $toolkit,
		ToolCatalog $catalog,
		Masking $masking,
		string $tool,
		array $arguments,
		Application $application,
		array $databases
	): WorkerResponse
	{
		$this->hideErrors(); // also after the initialization of the application
		$result = $toolkit->call($tool, $arguments, new AppContext($application, $this->project, $catalog, $databases));
		$notes = [];
		$warning = $application->getWarning();
		if ($warning !== null) {
			$notes['_warning'] = $masking->maskText($warning);
		}

		$definition = $catalog->getTool($tool);
		$this->identifierMaps = $definition !== null ? $definition->identifierMaps : [];
		$this->identifierValues = $definition !== null ? $definition->identifierValues : [];
		if ($definition !== null && $definition->untrusted) {
			$notes['_notice'] = Tool::UntrustedNotice;
		}

		return WorkerResponse::success($notes + $result->data, implode("\n", array_merge(array_values($notes), [$result->text])));
	}


	private function emit(WorkerResponse $response): void
	{
		$this->emitted = true;
		if ($this->masking !== null) {
			$response->data = $this->masking->maskArray($response->data, $this->identifierMaps, $this->identifierValues);
			$response->text = $this->masking->maskText($response->text);
		} else {
			$response = WorkerResponse::failure(
				$response->errorKind ?? WorkerResponse::ErrorCrash,
				'The worker ended before its masking policy was loaded.',
			);
		}
		// a value JSON cannot hold (INF, NAN, a recursion) becomes null instead of losing the whole result
		$json = json_encode(
			$response->toArray(),
			JSON_UNESCAPED_SLASHES
			| JSON_UNESCAPED_UNICODE
			| JSON_INVALID_UTF8_SUBSTITUTE
			| JSON_PRESERVE_ZERO_FRACTION
			| JSON_PARTIAL_OUTPUT_ON_ERROR,
		);
		fwrite($this->output, "\n" . self::ResultMarker . $this->token . "\n" . $json . "\n");
		fflush($this->output);
	}


	private static function describe(\Throwable $e): string
	{
		return get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
	}
}
