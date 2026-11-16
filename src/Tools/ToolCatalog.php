<?php declare(strict_types=1);

namespace Lupa\Tools;

use Lupa\InspectorException;


/**
 * The tools offered by the registered toolkits, minus the disabled ones. Active tools, those with side effects,
 * are offered only when the configuration enables them by name.
 */
final class ToolCatalog
{
	/** @var array<string, array{Tool, Toolkit}> */
	private array $tools = [];


	/**
	 * @param list<Toolkit> $toolkits
	 * @param list<string> $disabledTools  names, or patterns where * stands for any characters
	 * @param list<string> $enabledActiveTools
	 */
	public function __construct(array $toolkits, array $disabledTools = [], array $enabledActiveTools = [])
	{
		$active = [];
		foreach ($toolkits as $toolkit) {
			foreach ($toolkit->getTools() as $tool) {
				if (isset($this->tools[$tool->name]) || isset($active[$tool->name])) {
					throw new InspectorException("Tool '$tool->name' is defined twice.");
				} elseif (!$tool->readOnly) {
					$active[$tool->name] = true;
					if (!in_array($tool->name, $enabledActiveTools, true)) {
						continue;
					}
				}

				if (!self::matches($tool->name, $disabledTools)) {
					$this->tools[$tool->name] = [$tool, $toolkit];
				}
			}
		}

		foreach ($enabledActiveTools as $name) {
			if (!isset($active[$name])) {
				throw new InspectorException("Configuration 'inspector.enableActive' names '$name', which is not an active tool.");
			}
		}
	}


	/**
	 * @param list<string> $patterns
	 */
	private static function matches(string $name, array $patterns): bool
	{
		foreach ($patterns as $pattern) {
			if (preg_match('~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~D', $name)) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @return list<Tool>
	 */
	public function getTools(): array
	{
		return array_values(array_map(fn(array $pair) => $pair[0], $this->tools));
	}


	public function getTool(string $name): ?Tool
	{
		return $this->tools[$name][0] ?? null;
	}


	/** @param array<string> $names */
	public function select(array $names): self
	{
		$copy = clone $this;
		$copy->tools = array_intersect_key($this->tools, array_fill_keys($names, true));
		return $copy;
	}


	public function getToolkit(string $name): ?Toolkit
	{
		return $this->tools[$name][1] ?? null;
	}


	/**
	 * @return list<class-string<Toolkit>>
	 */
	public function getToolkitClasses(): array
	{
		$classes = array_map(fn(array $pair) => get_class($pair[1]), $this->tools);
		return array_values(array_unique($classes));
	}
}
