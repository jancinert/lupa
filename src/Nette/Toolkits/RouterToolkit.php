<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Nette\Application\LinkGenerator;
use Nette\Application\UI\InvalidLinkException;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Routing\Route;
use Nette\Routing\RouteList;
use Nette\Routing\Router;


/**
 * The router of nette/application: the routes, which presenter a URL leads to, and which URL a link produces.
 */
final class RouterToolkit implements Toolkit
{
	private const Results = [
		'routes' => [
			'total' => 'integer',
			'offset' => 'integer',
			'routes' => [[
				'index' => 'integer',
				'module' => '?string',
				'mask' => '?string',
				'defaults' => 'object|array',
				'oneWay' => 'boolean',
				'class' => 'string',
			]],
		],
		'route_match' => [
			'url' => 'string',
			'matched' => 'boolean',
			'presenter' => '?string',
			'action' => '?string',
			'parameters' => 'object|array',
			'route' => 'mixed', // index and mask of the route that matched
		],
		'link' => ['destination' => 'string', 'url' => '?string'],
	];

	private const RouterTypes = ['Nette\Routing\Router', 'Nette\Application\IRouter'];

	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	public function getTools(): array
	{
		return Tool::withResults([
			new Tool(
				'routes',
				'Routes',
				'Lists the routes in the order they are matched, with modules, masks, defaults and one-way flags.',
				Schema::object(
					['mask' => Schema::string('Part of the mask or the target presenter, case-insensitive.')] + Schema::paging(200, 2000),
				),
			),
			new Tool(
				'route_match',
				'Match URL',
				'Finds which presenter, action and parameters a URL leads to, and which route matched it.',
				Schema::object([
					'url' => Schema::string('Absolute URL, e.g. "http://localhost/orders/detail/5?tab=items", or a path like "/orders/detail/5",'
						. ' resolved against the address of the application, or http://localhost/ when it knows none.'
						. ' A route bound to a domain needs an absolute URL.'),
					'method' => Schema::string('HTTP method, default GET.'),
					'scriptPath' => Schema::string('Path of the front controller directory; default that of the application for a URL under its address, otherwise "/".'),
				], ['url']),
			),
			new Tool(
				'link',
				'Generate link',
				'Builds the absolute URL of a destination such as "Admin:Orders:detail" with parameters, as the router would.',
				Schema::object([
					'destination' => Schema::string('Presenter and action, e.g. "Admin:Orders:detail"; an absolute name may start with a colon.'),
					'parameters' => ['type' => 'object', 'description' => 'Parameters of the link, e.g. {"id": 5}.'],
					'baseUrl' => Schema::string('Base URL of the application, default from the container, e.g. "http://localhost/".'),
				], ['destination']),
			),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$container = NetteApplication::getContainerOf($context->application);
		$router = $this->getRouter($container);
		switch ($tool) {
			case 'routes':
				return $this->listRoutes($router, $arguments);
			case 'route_match':
				return $this->match($container, $router, $arguments);
			case 'link':
				return $this->link($container, $router, $arguments);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	private function listRoutes(object $router, Arguments $arguments): ToolResult
	{
		[$limit, $offset] = Schema::getPaging($arguments, 200, 2000);
		$routes = [];
		$this->collectRoutes($router, '', false, $routes);
		if ($arguments->has('mask')) {
			$routes = array_values(array_filter(
				$routes,
				fn(array $route) => stripos(
					Scalar::toString($route['mask']) . ' ' . json_encode($route['defaults']),
					$arguments->string('mask'),
				) !== false,
			));
		}

		$data = ['total' => count($routes), 'offset' => $offset, 'routes' => array_slice($routes, $offset, $limit)];
		return new ToolResult($data, sprintf('%d route(s).', count($routes)));
	}


	/**
	 * @param list<array<string, mixed>> $routes
	 */
	private function collectRoutes(object $router, string $module, bool $oneWay, array &$routes): void
	{
		if (self::isList($router)) {
			if (method_exists($router, 'getModule') && $router->getModule()) {
				$module .= Scalar::toString($router->getModule());
			}

			if (!$router instanceof RouteList) {
				$flags = []; // nette/application 2.4 keeps the flag in the route
			} elseif (method_exists($router, 'getFlags')) {
				$flags = $router->getFlags();
			} else { // nette/routing 3.0.0 keeps [router, flags] pairs
				$flags = array_column(\Closure::bind(fn(): array => $this->list, $router, RouteList::class)(), 1);
			}

			foreach (self::getRouters($router) as $i => $inner) {
				$this->collectRoutes($inner, $module, $oneWay || (bool) ($flags[$i] ?? 0), $routes);
			}

		} elseif (self::isRoute($router)) {
			$routes[] = [
				'index' => count($routes),
				'module' => $module === '' ? null : rtrim($module, ':'),
				'mask' => $router->getMask(),
				'defaults' => $this->masking->maskData(self::normalize($router->getDefaults())),
				'oneWay' => $oneWay || (!$router instanceof Route && (Scalar::toInt($router->getFlags()) & 1) === 1), // Route::ONE_WAY of 2.4
				'class' => get_class($router),
			];

		} else {
			$routes[] = [
				'index' => count($routes),
				'module' => $module ?: null,
				'mask' => null,
				'defaults' => [],
				'oneWay' => $oneWay,
				'class' => get_class($router),
			];
		}
	}


	private function match(Container $container, object $router, Arguments $arguments): ToolResult
	{
		$url = $arguments->string('url');
		$base = self::getApplicationUrl($container);
		if (!parse_url($url, PHP_URL_HOST)) {
			$url = $base->getBaseUrl() . ltrim($url, '/');
		}

		// a URL under the address of the application, as link builds it, finds the application in its directory
		$scriptPath = $arguments->string(
			'scriptPath',
			strncmp($url, $base->getBaseUrl(), strlen($base->getBaseUrl())) === 0 ? $base->getScriptPath() : '/',
		);

		$request = self::createRequest(new UrlScript($url, $scriptPath), strtoupper($arguments->string('method', 'GET')));
		$parameters = self::matchHttp($router, $request);
		if ($parameters === null) {
			return new ToolResult(['url' => $url, 'matched' => false], 'No route matches the URL.');
		}

		$presenter = $parameters['presenter'] ?? null;
		$action = $parameters['action'] ?? null;
		unset($parameters['presenter'], $parameters['action']);
		$data = [
			'url' => $url,
			'matched' => true,
			'presenter' => $presenter,
			'action' => $action,
			'parameters' => $this->masking->maskData(self::normalize($parameters)),
			'route' => $this->findMatchingRoute($router, $request),
		];
		return new ToolResult($data, sprintf('%s:%s', $presenter, $action));
	}


	/**
	 * The address of the application, which its links start with too. Outside a web request the HTTP request
	 * knows it only from the configuration, e.g. http: baseUrl of nette/http 3.4; otherwise it is http://localhost/.
	 */
	private static function getApplicationUrl(Container $container): UrlScript
	{
		$request = $container->getByType(IRequest::class, false);
		$url = $request instanceof IRequest ? $request->getUrl() : null;
		return $url !== null && $url->getHost() ? $url : new UrlScript('http://localhost/');
	}


	/**
	 * The first route of the list that matches, as routes lists it.
	 * @return ?array{index: int, mask: ?string}
	 */
	private function findMatchingRoute(object $router, IRequest $request): ?array
	{
		$routes = [];
		$this->collectRouters($router, $request, $routes);
		foreach ($routes as $index => [$route, $seen]) {
			if ($seen !== null && !self::isCliRouter($route) && $route->match($seen) !== null) {
				return ['index' => $index, 'mask' => self::isRoute($route) ? Scalar::toString($route->getMask()) : null];
			}
		}

		return null;
	}


	/**
	 * Matches as for a web request. The inspector runs in CLI, where a CLI router (Kdyby Console, nette/application)
	 * would accept every request, so CLI routers are skipped.
	 * @return ?array<mixed>
	 */
	public static function matchHttp(object $router, IRequest $request): ?array
	{
		if (!self::isList($router)) {
			return self::isCliRouter($router) ? null : self::toParameters($router->match($request));
		}

		$hasCliRouter = false;
		foreach (self::getRouters($router) as $inner) {
			$hasCliRouter = $hasCliRouter || self::isCliRouter($inner);
		}

		if (!$hasCliRouter) {
			return self::toParameters($router->match($request));
		}

		$request = self::prepareRequest($router, $request);
		if ($request === null) {
			return null;
		}

		$module = method_exists($router, 'getModule') ? Scalar::toString($router->getModule()) : '';
		foreach (self::getRouters($router) as $inner) {
			$parameters = self::matchHttp($inner, $request);
			if ($parameters !== null) {
				if ($module !== '' && isset($parameters['presenter'])) {
					$parameters['presenter'] = $module . Scalar::toString($parameters['presenter']);
				}

				return $parameters;
			}
		}

		return null;
	}


	/**
	 * A matched request as parameters with the presenter among them; nette/application 2.4 returns an object.
	 * @param array<mixed>|object|null $matched
	 * @return ?array<mixed>
	 */
	private static function toParameters($matched): ?array
	{
		return is_object($matched)
			? ['presenter' => $matched->getPresenterName()] + (array) $matched->getParameters()
			: $matched;
	}


	/**
	 * @param array<string, string> $headers
	 */
	public static function createRequest(UrlScript $url, string $method = 'GET', array $headers = []): Request
	{
		return interface_exists(Router::class)
			? new Request($url, [], [], [], $headers, $method)
			: new Request($url, null, [], [], [], $headers, $method); // nette/http 2.4 has a deprecated query parameter first
	}


	private static function isList(object $router): bool
	{
		return $router instanceof RouteList || is_a($router, 'Nette\Application\Routers\RouteList');
	}


	private static function isRoute(object $router): bool
	{
		return $router instanceof Route || is_a($router, 'Nette\Application\Routers\Route');
	}


	/**
	 * @return array<int, object>
	 */
	private static function getRouters(object $list): array
	{
		$routers = method_exists($list, 'getRouters')
			? $list->getRouters()
			: ($list instanceof \Traversable ? iterator_to_array($list, false) : []); // nette/application 2.4 is a collection itself
		return array_values(array_filter((array) $routers, 'is_object'));
	}


	private static function isCliRouter(object $router): bool
	{
		return substr(get_class($router), -9) === 'CliRouter';
	}


	/**
	 * The request as the routers inside the list get it: the list refuses another domain and strips its path.
	 * RouteList does this inside match(), which cannot be used when its routers are matched one by one.
	 */
	private static function prepareRequest(object $list, IRequest $request): ?IRequest
	{
		if (!$list instanceof RouteList) {
			return $request; // a list of nette/application 2.4 has neither a domain nor a path
		}

		$prepared = \Closure::bind(function () use ($request) {
			if ($this->domain) {
				$host = $request->getUrl()->getHost();
				if ($host !== $this->expandDomain($host)) {
					return null;
				}
			}

			if ($this->path) {
				$url = $request->getUrl();
				if (strncmp($url->getRelativePath(), $this->path, strlen($this->path)) !== 0) {
					return null;
				}

				$request = $request->withUrl($url->withPath($url->getPath(), $url->getBasePath() . $this->path));
			}

			return $request;
		}, $list, RouteList::class)();
		return $prepared instanceof IRequest ? $prepared : null;
	}


	/**
	 * @param list<array{object, ?IRequest}> $routes  router and the request it gets, null when its list refuses the request
	 */
	private function collectRouters(object $router, ?IRequest $request, array &$routes): void
	{
		if (self::isList($router)) {
			$request = $request === null ? null : self::prepareRequest($router, $request);
			foreach (self::getRouters($router) as $inner) {
				$this->collectRouters($inner, $request, $routes);
			}
		} else {
			$routes[] = [$router, $request];
		}
	}


	private function link(Container $container, object $router, Arguments $arguments): ToolResult
	{
		if (!$router instanceof Router && !is_a($router, 'Nette\Application\IRouter')) {
			throw new InspectorException('The router of the application does not implement ' . Router::class . '.');
		}

		$generator = $arguments->has('baseUrl')
			? new LinkGenerator(
				$router,
				new UrlScript($arguments->string('baseUrl')),
				$container->getByType('Nette\Application\IPresenterFactory', false),
			)
			: $container->getByType('Nette\Application\LinkGenerator', false);
		if ($generator === null) {
			throw new InspectorException("The application has no LinkGenerator service; give 'baseUrl'.");
		}

		try {
			$url = $generator->link(ltrim($arguments->string('destination'), ':'), $arguments->items('parameters'));
		} catch (InvalidLinkException $e) {
			throw new InspectorException('Invalid link: ' . $e->getMessage());
		}

		if (!parse_url($url, PHP_URL_HOST)) {
			throw new InspectorException("The application does not know its address outside a web request; give 'baseUrl'.");
		}

		return new ToolResult(['destination' => $arguments->string('destination'), 'url' => $url], $url);
	}


	private function getRouter(Container $container): object
	{
		foreach (self::RouterTypes as $type) {
			if (interface_exists($type) && ($router = $container->getByType($type, false))) {
				return $router;
			}
		}

		throw new InspectorException('The application has no router service.');
	}


	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function normalize($value)
	{
		if (is_array($value)) {
			return array_map([self::class, 'normalize'], $value);
		}

		return is_object($value) ? get_class($value) : $value;
	}
}
