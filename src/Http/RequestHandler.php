<?php declare(strict_types=1);

namespace Lupa\Http;

use Lupa\Mcp\Server;
use Lupa\Scalar;


/** Stateless Streamable HTTP binding for the protocol versions offered by the stdio server. */
final class RequestHandler
{
	public const MaxBodyBytes = 65_536;
	public const MaxResponseBytes = 1_048_576;
	private string $tokenHash;

	/** @var list<string> */
	private array $origins;

	/** @var \Closure(): Server */
	private \Closure $createServer;


	/**
	 * @param array<string> $origins  exact permitted browser origins; native clients may omit Origin
	 * @param callable(): Server $createServer  invoked only after authentication and request validation
	 */
	public function __construct(string $tokenHash, array $origins, callable $createServer)
	{
		if (!preg_match('~^[a-f0-9]{64}$~D', $tokenHash)) {
			throw new \InvalidArgumentException('HTTP authentication requires a SHA-256 token hash.');
		}

		$this->tokenHash = $tokenHash;
		$this->origins = array_values($origins);
		$this->createServer = \Closure::fromCallable($createServer);
	}


	/** @param array<string, mixed> $headers */
	public function handle(string $method, array $headers, string $body, bool $secure): Response
	{
		$headers = self::normalizeHeaders($headers);
		if ($headers === null) {
			return Response::error(400, 'Invalid HTTP headers.');
		}

		$rejection = $this->checkAccess($headers, $secure);
		if ($rejection !== null) {
			return $rejection;
		} elseif ($method !== 'POST') {
			return Response::error(
				405,
				'Only POST is supported; this endpoint does not offer SSE or sessions.',
				-32600,
				['Allow' => 'POST'],
			);
		} elseif (isset($headers['mcp-session-id'])) {
			return Response::error(400, 'This endpoint is stateless and does not accept session identifiers.');
		} elseif (!preg_match('~^application/json(?:\s*;\s*charset=utf-8)?\s*$~iD', $headers['content-type'] ?? '')) {
			return Response::error(415, 'Content-Type must be application/json with UTF-8 encoding.');
		} elseif (!self::accepts($headers['accept'] ?? '', 'application/json') || !self::accepts($headers['accept'] ?? '', 'text/event-stream')) {
			return Response::error(406, 'Accept must include application/json and text/event-stream.');
		} elseif (strlen($body) > self::MaxBodyBytes) {
			return Response::error(413, 'The MCP request is too large.');
		}

		if (
			isset($headers['content-length'])
			&& (!Scalar::isDigits($headers['content-length']) || (int) $headers['content-length'] !== strlen($body))
		) {
			return Response::error(400, 'Invalid Content-Length.');
		}

		$message = json_decode($body, true, 32);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return Response::error(400, 'Invalid JSON request.', -32700);
		} elseif (!is_array($message) || substr(ltrim($body), 0, 1) !== '{'
			|| ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)
			|| (isset($message['id']) && !is_int($message['id']) && !is_string($message['id']))
		) {
			return Response::error(400, 'Expected one JSON-RPC 2.0 request or notification.');
		}

		$version = $headers['mcp-protocol-version'] ?? '2025-03-26';
		if (!in_array($version, Server::ProtocolVersions, true) || $version === '2024-11-05') {
			return Response::error(400, 'Unsupported MCP protocol version.');
		}
		if ($message['method'] === 'initialize' && is_array($message['params'] ?? null)
			&& ($message['params']['protocolVersion'] ?? null) === '2024-11-05'
		) {
			$message['params']['protocolVersion'] = Server::ProtocolVersions[0];
		}
		// The server ignores notifications; acknowledge them without running project shutdown handlers.
		if (!array_key_exists('id', $message)) {
			return new Response(202);
		}

		try {
			$server = ($this->createServer)();
			if ($message['method'] !== 'initialize') {
				$server->handleMessage([
					'jsonrpc' => '2.0',
					'id' => null,
					'method' => 'initialize',
					'params' => ['protocolVersion' => $version],
				]);
			}

			$response = $server->handleMessage($message);
			$result = $response === null ? new Response(202) : new Response(200, $response);
			return strlen($result->body) <= self::MaxResponseBytes
				? $result
				: Response::error(503, 'The MCP result exceeds the HTTP response limit.', -32603);
		} catch (\Throwable $e) {
			return $e->getCode() === 429
				? Response::error(429, 'The HTTP request limit was reached or another call is running.', -32603, ['Retry-After' => '1'])
				: Response::error(503, 'The MCP endpoint is unavailable.', -32603);
		}
	}


	/**
	 * Checks the headers, HTTPS, the origin and the token, which needs no request body, so it can run before the body is read.
	 * @param array<string, mixed> $headers
	 */
	public function authorize(array $headers, bool $secure): ?Response
	{
		$headers = self::normalizeHeaders($headers);
		return $headers === null
			? Response::error(400, 'Invalid HTTP headers.')
			: $this->checkAccess($headers, $secure);
	}


	/**
	 * @param array<string, mixed> $headers
	 * @return ?array<string, string>  null for a value that is not one line of text or a name given twice
	 */
	private static function normalizeHeaders(array $headers): ?array
	{
		$normalized = [];
		foreach ($headers as $name => $value) {
			$name = strtolower($name);
			if (!is_string($value) || isset($normalized[$name]) || preg_match('~[\r\n]~', $value)) {
				return null;
			}

			$normalized[$name] = $value;
		}

		return $normalized;
	}


	/** @param array<string, string> $headers */
	private function checkAccess(array $headers, bool $secure): ?Response
	{
		if (!$secure) {
			return Response::error(403, 'HTTPS is required.');
		} elseif (isset($headers['origin']) && !in_array($headers['origin'], $this->origins, true)) {
			return Response::error(403, 'Origin is not allowed.');
		} elseif (!preg_match('~^Bearer ([A-Za-z0-9_-]{43,128})$~D', $headers['authorization'] ?? '', $match)
			|| !hash_equals($this->tokenHash, hash('sha256', $match[1]))
		) {
			return Response::error(401, 'Authentication required.', -32600, ['WWW-Authenticate' => 'Bearer realm="Lupa"']);
		}

		return null;
	}


	private static function accepts(string $header, string $type): bool
	{
		foreach (explode(',', strtolower($header)) as $part) {
			$parameters = array_map('trim', explode(';', $part));
			if ($parameters[0] === $type && !preg_match('~(?:^|;)\s*q=0(?:\.0*)?\s*(?:;|$)~', $part)) {
				return true;
			}
		}

		return false;
	}
}
