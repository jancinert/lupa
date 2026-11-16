<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;


/**
 * Symfony Console commands registered as services (Kdyby Console, Contributte Console). Commands are created
 * to read their names and definitions, never run.
 */
final class ConsoleToolkit implements Toolkit
{
	private const Results = [
		'console_commands' => [
			'count' => 'integer',
			'commands' => [[
				'name' => '?string',
				'description' => '?string',
				'aliases' => 'string[]',
				'hidden' => 'boolean',
				'class' => 'string',
				'service' => 'string',
				'arguments' => [[
					'name' => 'string',
					'required' => 'boolean',
					'array' => 'boolean',
					'description' => '?string',
					'default' => 'mixed',
				]],
				'options' => [[
					'name' => 'string',
					'shortcut' => '?string',
					'acceptsValue' => 'boolean',
					'description' => '?string',
					'default' => 'mixed',
				]],
			]],
			'errors' => [['service' => 'string', 'error' => 'string']],
		],
	];

	private const CommandType = 'Symfony\Component\Console\Command\Command';

	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	public function getTools(): array
	{
		$tools = Tool::withResults([new Tool(
			'console_commands',
			'Console commands',
			'Lists the console commands of the application with description, arguments and options, without running them.',
			Schema::object([
				'name' => Schema::string('Part of the command name, case-insensitive.'),
				'details' => ['type' => 'boolean', 'description' => 'Include arguments and options; default true when a name is given.'],
			]),
		)], self::Results);
		$tools[0]->identifierValues = ['name']; // a command such as cache:clear is one name, not a key with a value
		return $tools;
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		if ($tool !== 'console_commands') {
			throw new InspectorException("Unknown tool '$tool'.");
		} elseif (!class_exists(self::CommandType)) {
			throw new InspectorException('The application does not use Symfony Console.');
		}

		$container = NetteApplication::getContainerOf($context->application);
		$filter = $arguments->optionalString('name');
		$details = $arguments->bool('details', $filter !== null);
		$commands = $errors = [];
		foreach ($container->findByType(self::CommandType) as $service) {
			try {
				$command = $container->getService($service);
			} catch (\Throwable $e) {
				$errors[] = ['service' => $service, 'error' => get_class($e) . ': ' . $this->masking->maskText($e->getMessage())];
				continue;
			}

			if (
				!$command instanceof Command
				|| ($filter !== null && stripos((string) $command->getName(), $filter) === false)
			) {
				continue;
			}

			$entry = [
				'name' => $command->getName(),
				'description' => $command->getDescription(),
				'aliases' => $command->getAliases(),
				'hidden' => $command->isHidden(),
				'class' => get_class($command),
				'service' => $service,
			];
			if ($details) {
				$entry += $this->describeDefinition($command->getDefinition());
			}

			$commands[] = $entry;
		}

		usort($commands, fn(array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));
		$data = ['count' => count($commands), 'commands' => $commands, 'errors' => $errors];
		return new ToolResult(
			$this->masking->maskArray($data, [], ['name']),
			sprintf('%d command(s)%s.', count($commands), $errors ? sprintf(', %d could not be created', count($errors)) : ''),
		);
	}


	/**
	 * @return array{arguments: list<array<string, mixed>>, options: list<array<string, mixed>>}
	 */
	private function describeDefinition(InputDefinition $definition): array
	{
		$arguments = $options = [];
		foreach ($definition->getArguments() as $argument) {
			$arguments[] = [
				'name' => $argument->getName(),
				'required' => $argument->isRequired(),
				'array' => $argument->isArray(),
				'description' => $argument->getDescription(),
				'default' => current($this->masking->maskArray([$argument->getName() => $argument->getDefault()])),
			];
		}

		foreach ($definition->getOptions() as $option) {
			$options[] = [
				'name' => '--' . $option->getName(),
				'shortcut' => $option->getShortcut(),
				'acceptsValue' => $option->acceptValue(),
				'description' => $option->getDescription(),
				'default' => current($this->masking->maskArray([$option->getName() => $option->getDefault()])),
			];
		}

		return ['arguments' => $arguments, 'options' => $options];
	}
}
