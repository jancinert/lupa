<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Latte\CompileException;
use Latte\Engine;
use Latte\Loaders\FileLoader;
use Lupa\InspectorException;
use Lupa\Latte\TemplateMapper;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;


/**
 * Latte templates: mapping compiled code back to the source, and compiling templates to find errors.
 */
final class LatteToolkit implements Toolkit
{
	private const Results = [
		'latte_map_line' => [
			'compiled' => ['file' => 'string', 'line' => 'integer'],
			'source' => '?string',
			'line' => '?integer',
			'column' => '?integer',
			'excerpt' => '?string',
		],
		'latte_check' => [
			'path' => 'string',
			'checked' => 'integer',
			'errors' => [['file' => 'string', 'line' => '?integer', 'message' => 'string']],
			'errorCount' => 'integer',
			'warnings' => [['file' => 'string', 'filter' => '?string', 'message' => 'string']],
			'warningCount' => 'integer',
		],
	];

	private const SkippedDirectories = ['vendor', 'node_modules', 'temp', 'tmp', 'log', '.git'];

	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	public function getTools(): array
	{
		return Tool::withResults([
			(new Tool(
				'latte_map_line',
				'Map compiled template line',
				'Converts a file and line of a compiled Latte template (temp/cache/latte/…php, as stack traces show it)'
				. ' to the .latte source file and line, with the code around it.',
				Schema::object([
					'file' => Schema::string('Path or file name of the compiled template; it is looked up in the Latte cache directory.'),
					'line' => Schema::integer('Line in the compiled template.'),
				], ['file', 'line']),
			))->withoutApplication(),
			new Tool(
				'latte_check',
				'Check Latte templates',
				'Compiles templates with the Latte engine of the application, without rendering them, and reports syntax errors,'
				. ' unknown tags and filters that are not registered globally (a presenter may still add them).',
				Schema::object([
					'path' => Schema::string('Template file or directory, relative to the project root; default is the app directory.'),
					'limit' => Schema::integer('Maximum number of reported problems, default 50, at most 1000.'),
				]),
			),
		], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		switch ($tool) {
			case 'latte_map_line':
				return $this->mapLine($arguments->string('file'), $arguments->int('line'), $context);
			case 'latte_check':
				return $this->check($arguments, $context);
			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
	}


	private function mapLine(string $file, int $line, AppContext $context): ToolResult
	{
		// only the cache directory is read, whatever path the stack trace shows (it may come from another environment)
		$name = basename(str_replace('\\', '/', $file));
		$compiled = $context->getDirectory('tempDir', 'temp') . '/cache/latte/' . $name;
		if (!preg_match('~^[\w.@-]+\.php$~', $name) || !is_file($compiled)) {
			throw new InspectorException("Compiled template '$name' not found in the Latte cache directory.");
		}

		$mapped = TemplateMapper::map($compiled, $line);
		if ($mapped === null) {
			throw new InspectorException("'$file' is not a compiled Latte template.");
		}
		$excerpt = $mapped['line'] === null ? null : TemplateMapper::getExcerpt($mapped['source'], $mapped['line']);

		$data = [
			'compiled' => ['file' => $context->project->relativize($compiled), 'line' => $line],
			'source' => $context->project->relativize($mapped['source']),
			'line' => $mapped['line'],
			'column' => $mapped['column'],
			'excerpt' => $excerpt === null ? null : $this->masking->maskText($excerpt),
		];
		return new ToolResult($data, $mapped['line'] === null
			? "Line $line is in the header of the template {$data['source']}; no source line is marked."
			: "{$data['source']}:{$mapped['line']}");
	}


	private function check(Arguments $arguments, AppContext $context): ToolResult
	{
		$limit = Schema::getLimit($arguments, 'limit', 50, 1000);
		$path = $context->project->resolveInside($arguments->optionalString('path') ?? $context->getDirectory('appDir', 'app'));
		$latte = $this->createEngine($context);
		$filters = array_change_key_case(array_flip(array_map('strval', array_keys($latte->getFilters()))));

		$errors = $warnings = [];
		$count = 0;
		foreach (self::findTemplates($path) as $file) {
			$count++;
			$name = $context->project->relativize($file);
			try {
				$code = $latte->compile($file);
			} catch (CompileException $e) {
				$errors[] = ['file' => $name, 'line' => self::getErrorLine($e), 'message' => $this->masking->maskText($e->getMessage())];
				continue;
			} catch (\Throwable $e) {
				$errors[] = [
					'file' => $name,
					'line' => null,
					'message' => get_class($e) . ': ' . $this->masking->maskText($e->getMessage()),
				];
				continue;
			}

			// a filter of a whole block, as in {include title|stripHtml}, is called by name through filterContent()
			preg_match_all('~\$this->filters->(?:filterContent\(\s*\'(\w+)\'|(?!filterContent\b)(\w+))~', $code, $matches);
			foreach (array_unique(array_filter(array_merge($matches[1], $matches[2]))) as $filter) {
				if (!isset($filters[strtolower($filter)])) {
					$warnings[] = ['file' => $name, 'filter' => $filter, 'message' => "Filter '$filter' is not registered globally."];
				}
			}
		}

		$data = [
			'path' => $context->project->relativize($path),
			'checked' => $count,
			'errors' => array_slice($errors, 0, $limit),
			'errorCount' => count($errors),
			'warnings' => array_slice($warnings, 0, $limit),
			'warningCount' => count($warnings),
		];
		return new ToolResult(
			$data,
			sprintf('%d template(s) checked: %d error(s), %d unknown filter(s).', $count, count($errors), count($warnings)),
		);
	}


	/**
	 * The engine as the application configures it for templates: filters, tags and the Nette tags such as {link}.
	 * @return Engine
	 */
	private function createEngine(AppContext $context): object
	{
		if (!class_exists(Engine::class)) {
			throw new InspectorException('The application does not use Latte.');
		}

		$container = NetteApplication::getContainerOf($context->application);
		$templateFactory = $container->getByType('Nette\Application\UI\TemplateFactory', false)
			?? $container->getByType('Nette\Application\UI\ITemplateFactory', false);
		if ($templateFactory !== null) {
			$latte = $templateFactory->createTemplate()->getLatte();
		} else {
			$latteFactory = $container->getByType('Nette\Bridges\ApplicationLatte\LatteFactory', false)
				?? $container->getByType('Nette\Bridges\ApplicationLatte\ILatteFactory', false);
			$latte = $latteFactory ? $latteFactory->create() : new Engine;
		}

		if (!$latte instanceof Engine) {
			throw new InspectorException('The application did not create a Latte engine.');
		}

		$latte->setLoader(new FileLoader);
		return $latte;
	}


	/**
	 * @return \Generator<string>
	 */
	private static function findTemplates(string $path): \Generator
	{
		if (is_file($path)) {
			yield $path;
			return;
		}

		$iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			fn(\SplFileInfo $file) => !$file->isDir() || !in_array($file->getFilename(), self::SkippedDirectories, true),
		));
		$files = [];
		foreach ($iterator as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'latte') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);
		yield from $files;
	}


	private static function getErrorLine(CompileException $e): ?int
	{
		if (isset($e->sourceLine)) { // Latte 2
			return $e->sourceLine;
		}

		$position = $e->position ?? null; // Latte 3
		return is_object($position) && isset($position->line) ? $position->line : null;
	}
}
