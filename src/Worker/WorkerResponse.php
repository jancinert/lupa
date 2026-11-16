<?php declare(strict_types=1);

namespace Lupa\Worker;


/**
 * Outcome of one tool call as the worker reports it.
 */
final class WorkerResponse
{
	public const
		ErrorTool = 'tool',
		ErrorGuard = 'guard',
		ErrorBoot = 'boot',
		ErrorCrash = 'crash',
		ErrorTimeout = 'timeout';

	public bool $ok;

	/** @var array<mixed> */
	public array $data = [];

	/** Summary on success, message on failure. */
	public string $text;

	/** One of the Error* constants on failure. */
	public ?string $errorKind = null;


	/**
	 * @param array<mixed> $data
	 */
	public static function success(array $data, string $text): self
	{
		$response = new self;
		$response->ok = true;
		$response->data = $data;
		$response->text = $text;
		return $response;
	}


	public static function failure(string $kind, string $message): self
	{
		$response = new self;
		$response->ok = false;
		$response->errorKind = $kind;
		$response->text = $message;
		return $response;
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return $this->ok
			? ['ok' => true, 'data' => $this->data, 'text' => $this->text]
			: ['ok' => false, 'error' => $this->errorKind, 'text' => $this->text];
	}


	/**
	 * @param mixed $data
	 */
	public static function fromArray($data): ?self
	{
		if (!is_array($data) || !is_bool($data['ok'] ?? null) || !is_string($data['text'] ?? null)) {
			return null;
		}

		return $data['ok']
			? self::success(is_array($data['data'] ?? null) ? $data['data'] : [], $data['text'])
			: self::failure(is_string($data['error'] ?? null) ? $data['error'] : self::ErrorCrash, $data['text']);
	}
}
