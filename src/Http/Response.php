<?php declare(strict_types=1);

namespace Lupa\Http;

use Lupa\Mcp\Server;


final class Response
{
	public int $status;
	public string $body;

	/** @var array<string, string> */
	public array $headers;


	/**
	 * @param ?array<mixed> $message
	 * @param array<string, string> $headers
	 */
	public function __construct(int $status, ?array $message = null, array $headers = [])
	{
		$this->status = $status;
		$this->body = $message === null ? '' : Server::encode($message);
		$this->headers = $headers + [
			'Content-Type' => 'application/json; charset=utf-8',
			'Cache-Control' => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		];
	}


	/** @param array<string, string> $headers */
	public static function error(int $status, string $message, int $code = -32600, array $headers = []): self
	{
		return new self($status, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]], $headers);
	}


	public function sendHeaders(): void
	{
		// Application headers, including cookies and debug headers, must not cross the MCP boundary.
		header_remove();
		foreach ($this->headers as $name => $value) {
			header($name . ': ' . $value, true, $this->status);
		}
	}


	public function send(): void
	{
		$this->sendHeaders();

		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
			echo $this->body;
		}
	}
}
