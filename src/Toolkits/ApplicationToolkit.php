<?php declare(strict_types=1);

namespace Lupa\Toolkits;

use Lupa\Inspector;
use Lupa\Masking;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;


/**
 * Overview of the application: PHP, packages, framework facts and database connections.
 */
final class ApplicationToolkit implements Toolkit
{
	private const Results = [
		'app_info' => [
			'inspector' => ['version' => 'string', 'projectRoot' => 'string', 'toolkits' => 'string[]', 'tools' => 'string[]'],
			'php' => ['version' => 'string', 'sapi' => 'string', 'os' => 'string', 'extensions' => 'string[]'],
			'packages' => [['name' => 'string', 'version' => '?string', 'reference' => '?string']],
			'packageCount' => 'integer',
			'application' => 'object|array',
			'databases' => 'object[]',
		],
	];

	private const FrameworkVendors = ['nette', 'latte', 'tracy', 'dibi', 'kdyby', 'contributte', 'nextras', 'ublaboo', 'ruckusing'];
	private Masking $masking;


	public function __construct(?Masking $masking = null)
	{
		$this->masking = $masking ?? new Masking;
	}


	public function getTools(): array
	{
		return Tool::withResults([new Tool(
			'app_info',
			'Application overview',
			'PHP version and extensions, installed packages, framework directories and modes, database connections'
			. ' (host and database name, never credentials) and the inspector tools available. Start here.',
			[
				'type' => 'object',
				'properties' => [
					'packages' => [
						'type' => 'string',
						'enum' => ['framework', 'all'],
						'description' => "'framework' lists Nette, Latte, Tracy, Dibi, Kdyby and similar packages (default); 'all' lists every installed package.",
					],
				],
				'additionalProperties' => false,
			],
		)], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$packages = $this->readPackages(dirname($context->project->autoload) . '/composer/installed.json');
		$listed = $arguments->string('packages', 'framework') === 'all'
			? $packages
			: array_values(array_filter(
				$packages,
				fn(array $package) => in_array(strstr($package['name'], '/', true), self::FrameworkVendors, true),
			));

		$extensions = get_loaded_extensions();
		sort($extensions);
		$data = [
			'inspector' => [
				'version' => Inspector::Version,
				'projectRoot' => $context->project->root,
				'toolkits' => $context->catalog->getToolkitClasses(),
				'tools' => array_map(fn(Tool $tool) => $tool->name, $context->catalog->getTools()),
			],
			'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'extensions' => $extensions],
			'packages' => $listed,
			'packageCount' => count($packages),
			'application' => $context->application->getInfo(),
			'databases' => array_map(fn($target) => $target->toArray(), $context->databases),
		];

		$summary = sprintf(
			'PHP %s, %s, %d packages, %d guarded connection(s).',
			PHP_VERSION,
			Scalar::toString($data['application']['framework'] ?? 'unknown framework'),
			count($packages),
			count($context->databases),
		);
		return new ToolResult($this->masking->maskArray($data), $this->masking->maskText($summary));
	}


	/**
	 * @return list<array{name: string, version: ?string, reference: ?string}>
	 */
	private function readPackages(string $file): array
	{
		$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
		$packages = is_array($data) ? $data['packages'] ?? $data : null; // Composer 2 wraps the list, Composer 1 does not
		if (!is_array($packages)) {
			return [];
		}

		$result = [];
		foreach ($packages as $package) {
			if (is_array($package) && is_string($package['name'] ?? null)) {
				$source = is_array($package['source'] ?? null) ? $package['source'] : (array) ($package['dist'] ?? []);
				$reference = $source['reference'] ?? null;
				$result[] = [
					'name' => $package['name'],
					'version' => is_string($package['version'] ?? null) ? $package['version'] : null,
					'reference' => is_string($reference) ? substr($reference, 0, 10) : null,
				];
			}
		}

		usort($result, fn(array $a, array $b) => strcmp($a['name'], $b['name']));
		return $result;
	}
}
