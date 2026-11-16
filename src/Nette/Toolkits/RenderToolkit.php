<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\Database\Connections;
use Lupa\InspectorException;
use Lupa\Latte\TemplateMapper;
use Lupa\Masking;
use Lupa\Nette\ContainerReader;
use Lupa\Nette\NetteApplication;
use Lupa\Nette\Render\LegacyRecordingMailer;
use Lupa\Nette\Render\LegacyUserStorage;
use Lupa\Nette\Render\MemoryUserStorage;
use Lupa\Nette\Render\RecordingMailer;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Lupa\Utf8;
use Nette\Application\BadRequestException;
use Nette\Application\LinkGenerator;
use Nette\Application\Request;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\InvalidLinkException;
use Nette\DI\Container;
use Nette\Http\UrlScript;
use Nette\Security\Identity;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;


/**
 * Runs a presenter for a GET request and reports what it produced. This runs code of the application,
 * so it is an active tool, off unless enabled, with these safeguards:
 * - every guarded database connection is switched to a read-only session first, so writes fail and are reported;
 * - mailers are replaced by a recorder, nothing is sent;
 * - the HTTP request and the logged-in user are in memory; the user is never authenticated against the application.
 * Other side effects of the code (files, sessions, calls of external services) are not prevented.
 */
final class RenderToolkit implements Toolkit
{
	private const Results = [
		'render' => [
			'presenter' => 'string',
			'action' => 'string',
			'parameters' => 'object|array',
			'url' => 'string',
			'status' => '?integer',
			'summary' => 'string',
			'redirect' => '?string',
			'title' => '?string',
			'html' => '?string',
			'htmlBytes' => 'integer',
			'snippets' => 'mixed',
			'payload' => 'mixed',
			'exceptions' => [['class' => 'string', 'message' => 'string', 'file' => 'string', 'line' => 'integer', 'template' => 'mixed']],
			'mails' => [['to' => 'string[]', 'subject' => '?string']],
			'durationMs' => 'integer',
			'safety' => 'object', // what was replaced and which connections are read-only
		],
	];

	private const HtmlLength = 20000;
	private const MailerTypes = ['Nette\Mail\Mailer', 'Nette\Mail\IMailer'];

	private Masking $masking;
	private Connections $connections;


	public function __construct(Masking $masking, Connections $connections)
	{
		$this->masking = $masking;
		$this->connections = $connections;
	}


	public function getTools(): array
	{
		return Tool::withResults([(new Tool(
			'render',
			'Render presenter',
			'Active tool, off unless enabled: runs a presenter for a GET request, optionally as a user with given roles,'
			. ' and returns the HTTP code, a redirect, the HTML (shortened, masked), snippets of an AJAX request,'
			. ' or the exception with its place in the .latte source. Database connections are read-only and e-mails'
			. ' are only recorded; other side effects of the application code are not prevented.',
			Schema::object([
				'url' => Schema::string('URL to render; the router finds the presenter. Or give presenter instead.'),
				'presenter' => Schema::string('Presenter name, e.g. "Admin:Orders".'),
				'action' => Schema::string('Action, default "default".'),
				'parameters' => ['type' => 'object', 'description' => 'Request parameters, e.g. {"id": 5}.'],
				'baseUrl' => Schema::string('Base URL for a request given by presenter, default "http://localhost/".'),
				'roles' => Schema::listOf(['type' => 'string'])
					+ ['description' => 'Log in a user with these roles.'],
				'userId' => ['type' => ['integer', 'string'], 'description' => 'Id of the logged-in user, default 1.'],
				'ajax' => ['type' => 'boolean', 'description' => 'Send the request as AJAX and report the snippets.'],
			]),
		))->markActive()->markUntrusted()], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		if ($tool !== 'render') {
			throw new InspectorException("Unknown tool '$tool'.");
		} elseif (!interface_exists('Nette\Application\IPresenterFactory')) {
			throw new InspectorException('The application does not use nette/application.');
		}

		$container = NetteApplication::getContainerOf($context->application);
		$safety = ['replaced' => [], 'alreadyCreated' => []];
		$mailer = $this->replaceMailers($container, $safety);
		$safety['databases'] = $this->connections->lockSessions($context);
		$context->application->initialize();

		[$presenterName, $parameters, $url] = $this->resolveRequest($container, $arguments);
		if (isset($parameters['do'])) {
			throw new InspectorException(
				"The request calls the signal '"
				. Scalar::toString($parameters['do'])
				. "'; signals change state, so render does not run them.",
			);
		}

		$request = self::createHttpRequest($url, $arguments->bool('ajax'));
		$this->replaceServices($container, $request, $safety, $container->findByType('Nette\Http\IRequest'));
		if ($arguments->has('roles')) {
			$user = self::createUser($container, $arguments->items('roles'), $arguments->raw('userId') ?? 1);
			$this->replaceServices($container, $user, $safety, $container->findByType(User::class));
		}

		$start = microtime(true);
		$level = ob_get_level();
		ob_start();
		try {
			$presenter = $container->getByType('Nette\Application\IPresenterFactory')->createPresenter($presenterName);
			if (property_exists($presenter, 'autoCanonicalize')) {
				$presenter->autoCanonicalize = false;
			}

			$response = $presenter->run(new Request($presenterName, 'GET', $parameters));
			$result = $this->describeResponse($response, $container) + ['snippets' => self::getSnippets($presenter)];
		} catch (\Throwable $e) {
			$result = $this->describeException($e, $context);
		} finally {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}
		}

		$data = [
			'presenter' => $presenterName,
			'action' => $parameters['action'],
			'parameters' => $this->masking->maskData(array_diff_key($parameters, ['action' => 1])),
			'url' => $this->masking->maskText($url),
		] + $result + [
			'mails' => $mailer ? $this->masking->maskData($mailer->messages) : [],
			'durationMs' => (int) round((microtime(true) - $start) * 1000),
			'safety' => $safety,
		];
		return new ToolResult($data, sprintf('%s:%s → %s', $presenterName, $parameters['action'], $result['summary']));
	}


	/**
	 * @return array{string, array<mixed>, string}  presenter, parameters with action, URL
	 */
	private function resolveRequest(Container $container, Arguments $arguments): array
	{
		if ($arguments->has('url')) {
			$router = $container->getByType('Nette\Routing\Router', false) ?? $container->getByType('Nette\Application\IRouter');
			$parameters = RouterToolkit::matchHttp($router, self::createHttpRequest($arguments->string('url'), false));
			if ($parameters === null || !isset($parameters['presenter'])) {
				throw new InspectorException("No route matches '{$arguments->string('url')}'.");
			}

			$presenter = Scalar::toString($parameters['presenter']);
			unset($parameters['presenter']);
			return [$presenter, $parameters + ['action' => 'default'], $arguments->string('url')];
		} elseif (!$arguments->has('presenter')) {
			throw new InspectorException("Give the 'url' or the 'presenter'.");
		}

		$presenter = ltrim($arguments->string('presenter'), ':');
		$parameters = ['action' => $arguments->string('action', 'default')] + (array) ($arguments->items('parameters'));
		$baseUrl = $arguments->string('baseUrl', 'http://localhost/');
		$router = $container->getByType('Nette\Routing\Router', false) ?? $container->getByType('Nette\Application\IRouter', false);
		try {
			// the LinkGenerator of the container takes its base URL from the CLI request, which has no host
			$url = $router
				? (new LinkGenerator($router, new UrlScript($baseUrl), $container->getByType('Nette\Application\IPresenterFactory')))
					->link($presenter . ':' . $parameters['action'], array_diff_key($parameters, ['action' => 1]))
				: null;
		} catch (InvalidLinkException $e) {
			$url = null; // the request still runs, only its URL is unknown
		}

		return [$presenter, $parameters, $url ?? $baseUrl];
	}


	/**
	 * @param array{replaced: list<string>, alreadyCreated: list<string>} $safety  what was replaced, by kind
	 * @return RecordingMailer|LegacyRecordingMailer|null
	 */
	private function replaceMailers(Container $container, array &$safety): ?object
	{
		$reader = new ContainerReader($container);
		$names = [];
		foreach (self::MailerTypes as $type) {
			$names = array_merge($names, interface_exists($type) ? $reader->findByType($type) : []);
		}

		foreach ($names as $name) {
			if ($container->isCreated($name)) {
				throw new InspectorException("Cannot intercept the mailer '$name' because it has already been created.");
			}
		}

		if (!$names) {
			return null;
		}

		$mailer = interface_exists('Nette\Mail\Mailer') ? new RecordingMailer : new LegacyRecordingMailer;
		$this->replaceServices($container, $mailer, $safety, array_unique($names));
		return $mailer;
	}


	/**
	 * @param array{replaced: list<string>, alreadyCreated: list<string>} $safety  what was replaced, by kind
	 * @param list<string> $names  services to replace
	 */
	private function replaceServices(Container $container, object $service, array &$safety, array $names): void
	{
		foreach ($names as $name) {
			if ($container->isCreated($name)) {
				$safety['alreadyCreated'][] = $name; // objects created earlier keep the original
			}

			// addService() would refuse a replacement that is not of the declared class, e.g. SendmailMailer
			\Closure::bind(function () use ($name, $service): void {
				if (property_exists($this, 'registry')) { // nette/di 2.4
					$this->registry[$name] = $service;
				} else {
					$this->instances[$name] = $service;
				}
			}, $container, Container::class)();
			$safety['replaced'][] = $name;
		}
	}


	private static function createHttpRequest(string $url, bool $ajax): \Nette\Http\Request
	{
		$script = new UrlScript($url, '/');
		if (!$script->getHost()) {
			throw new InspectorException("Argument 'url' must be an absolute URL.");
		}

		return RouterToolkit::createRequest($script, 'GET', $ajax ? ['x-requested-with' => 'XMLHttpRequest'] : []);
	}


	/**
	 * @param list<string> $roles
	 * @param int|string $id
	 */
	private static function createUser(Container $container, array $roles, $id): User
	{
		if (!interface_exists('Nette\Security\UserStorage')) { // nette/security before 3.1
			$user = new User(new LegacyUserStorage, null, $container->getByType('Nette\Security\IAuthorizator', false));
			$user->login(new Identity($id, $roles));
			return $user;
		}

		$storage = new MemoryUserStorage;
		$authorizator = $container->getByType('Nette\Security\Authorizator', false);
		$first = (new \ReflectionMethod(User::class, '__construct'))->getParameters()[0]->getType();
		$user = $first instanceof \ReflectionNamedType && $first->getName() === 'Nette\Security\UserStorage'
			? new User($storage, null, $authorizator) // nette/security 3.2+
			: new User(null, null, $authorizator, $storage); // 3.1 keeps the legacy storage first
		$user->login(new SimpleIdentity($id, $roles));
		return $user;
	}


	/**
	 * Masked before it is shortened, so that the cut cannot split a secret into a part no pattern recognizes.
	 */
	private function maskHtml(string $html): string
	{
		$html = $this->masking->maskHtml($html);
		return Utf8::length($html) > self::HtmlLength
			? Utf8::prefix($html, self::HtmlLength) . '…'
			: $html;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function describeResponse(object $response, Container $container): array
	{
		if ($response instanceof RedirectResponse) {
			$target = $this->masking->maskText($response->getUrl());
			return [
				'status' => $response->getCode(),
				'summary' => "redirect {$response->getCode()} to $target",
				'redirect' => $target,
			];
		} elseif ($response instanceof JsonResponse) {
			return [
				'status' => 200,
				'summary' => 'JSON',
				'payload' => $this->masking->maskData(json_decode((string) json_encode($response->getPayload()), true)),
			];
		} elseif ($response instanceof ForwardResponse) {
			return [
				'status' => null,
				'summary' => 'forward to ' . $response->getRequest()->getPresenterName(),
				'forward' => $response->getRequest()->getPresenterName(),
			];
		} elseif (!$response instanceof TextResponse) {
			return ['status' => null, 'summary' => get_class($response)];
		}

		$source = $response->getSource();
		$html = Scalar::toString(is_object($source) && method_exists($source, 'renderToString') ? $source->renderToString() : $source);
		$httpResponse = $container->getByType('Nette\Http\IResponse', false);
		$status = $httpResponse ? $httpResponse->getCode() : 200;
		preg_match('~<title>(.*?)</title>~is', $html, $title);
		return [
			'status' => $status,
			'summary' => sprintf('%d, %d bytes of HTML', $status, strlen($html)),
			'title' => isset($title[1]) ? $this->masking->maskText(trim(html_entity_decode($title[1]))) : null,
			'html' => $this->maskHtml($html),
			'htmlBytes' => strlen($html),
		];
	}


	/**
	 * @return array<string, mixed>
	 */
	private function describeException(\Throwable $e, AppContext $context): array
	{
		if ($e instanceof BadRequestException) {
			$status = method_exists($e, 'getHttpCode') ? $e->getHttpCode() : $e->getCode(); // the method is missing in nette/application 2.4.0
		} else {
			$status = 500;
		}

		$errors = [];
		for ($current = $e; $current; $current = $current->getPrevious()) {
			$place = null;
			foreach (array_merge([['file' => $current->getFile(), 'line' => $current->getLine()]], $current->getTrace()) as $frame) {
				if (isset($frame['file']) && preg_match('~\.latte--\w+\.php$~', $frame['file'])) {
					$place = TemplateMapper::map($frame['file'], $frame['line'] ?? 0);
					break;
				}
			}

			$errors[] = [
				'class' => get_class($current),
				'message' => $this->masking->maskText($current->getMessage()),
				'file' => $context->project->relativize($current->getFile()),
				'line' => $current->getLine(),
				'template' => $place ? ['source' => $context->project->relativize($place['source'])] + $place : null,
			];
		}

		$readOnly = stripos($e->getMessage(), 'READ ONLY') !== false || stripos($e->getMessage(), 'readonly database') !== false;
		return [
			'status' => $status,
			'summary' => sprintf('%d %s: %s', $status, get_class($e), Utf8::trimWidth($errors[0]['message'], 200))
				. ($readOnly ? ' (the page tried to write to the read-only database)' : ''),
			'exceptions' => $errors,
		];
	}


	/**
	 * @return ?list<string>  snippet names of an AJAX response
	 */
	private static function getSnippets(object $presenter): ?array
	{
		$payload = method_exists($presenter, 'getPayload') ? $presenter->getPayload() : null;
		return is_object($payload) && isset($payload->snippets) ? array_map('strval', array_keys((array) $payload->snippets)) : null;
	}
}
