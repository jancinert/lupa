<?php declare(strict_types=1);

namespace Lupa\Tools;


final class ToolResult
{
	/** @var array<mixed> data matching the tool's output schema */
	public array $data;

	/** Short human-readable summary. */
	public string $text;


	/**
	 * @param array<mixed> $data
	 */
	public function __construct(array $data, string $text)
	{
		$this->data = $data;
		$this->text = $text;
	}
}
