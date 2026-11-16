<?php declare(strict_types=1);

namespace Lupa\Tracy;

use Lupa\InspectorException;


/**
 * Extracts the exceptions, their source and call stack, and the request from a BlueScreen HTML report of Tracy 2.x.
 * Tracy 2.4–2.8 marks sections as div.panel, later versions as section.tracy-section; both are read.
 * Variable dumps, environment and request bodies are not extracted.
 */
final class ReportParser
{
	private const ErrorBlocks = "//section[contains(concat(' ', @class, ' '), ' tracy-section--error ')]"
		. " | //div[@id = 'tracy-bs-error']"
		. " | //div[@id = 'tracyCaused']/div[@class = 'inner']/div[@class = 'panel'][count(h2) = 2]";

	private \DOMXPath $xpath;


	public function __construct(string $html)
	{
		if (!class_exists(\DOMDocument::class)) {
			throw new InspectorException('Reading Tracy reports requires the PHP extension dom.');
		}

		$document = new \DOMDocument;
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$this->xpath = new \DOMXPath($document);
	}


	/**
	 * The thrown exception first, then its previous ones.
	 * @return list<array{class: string, message: string, file: ?string, line: ?int, source: list<array{line: int, code: string, highlighted: bool}>, stack: list<array{file: ?string, line: ?int, call: string}>}>
	 */
	public function getExceptions(int $sourceRadius, int $stackLimit): array
	{
		$result = [];
		foreach ($this->query(self::ErrorBlocks) as $error) {
			$headings = $this->query('./h2', $error);
			$sections = $this->getFollowingSections($error);
			$source = $sections['Source file'] ?? null;
			[$file, $line] = $source ? $this->getEditorLink($source) : [null, null];
			$class = $this->getText($error, './p') ?: (isset($headings[0]) ? self::normalize($headings[0]->textContent) : '');
			$result[] = [
				'class' => (string) preg_replace('~ #-?\d+$~', '', $class), // without the exception code
				'message' => $this->getText($error, './/h1/span') ?: $this->getText($error, './/h1')
					?: (isset($headings[1]) ? self::normalize($headings[1]->textContent) : ''),
				'file' => $file,
				'line' => $line,
				'source' => $source ? $this->getCode($source, $sourceRadius) : [],
				'stack' => isset($sections['Call stack']) ? $this->getStack($sections['Call stack'], $stackLimit) : [],
			];
		}

		return $result;
	}


	/**
	 * Method and URL of an HTTP request, or the command line of a CLI request; whole, so that it is masked before it is shortened.
	 */
	public function getRequest(): ?string
	{
		foreach (['HTTP', 'HTTP request'] as $label) {
			$section = $this->findSection($label);
			$heading = $section ? $this->getText($section, './/h3') : '';
			if (preg_match('~^[A-Z]+ \S+~', $heading)) {
				return $heading;
			}
		}

		$cli = $this->findSection('CLI request');
		return $cli === null ? null : (self::normalize($cli->textContent) ?: null);
	}


	/**
	 * Sibling sections after an error block, up to the next one, by their label.
	 * @return array<string, \DOMElement>
	 */
	private function getFollowingSections(\DOMElement $error): array
	{
		$result = [];
		for ($node = $error->nextSibling; $node; $node = $node->nextSibling) {
			if (!$node instanceof \DOMElement) {
				continue;
			} elseif (strpos(' ' . $node->getAttribute('class') . ' ', ' tracy-section--error ') !== false) {
				break;
			}

			$label = $this->getText($node, './h2');
			if ($label !== '' && !isset($result[$label])) {
				$result[$label] = $node;
			}
		}

		return $result;
	}


	private function findSection(string $label): ?\DOMElement
	{
		$nodes = $this->query("//*[self::section or self::div][h2[normalize-space(.) = '$label']]");
		return $nodes[0] ?? null;
	}


	/**
	 * @return array{?string, ?int}
	 */
	private function getEditorLink(\DOMNode $context): array
	{
		foreach ($this->query('.//a[@title]', $context) as $link) {
			if (!preg_match('~^(.+):(\d+)$~', $link->getAttribute('title'), $m)) {
				continue;
			} elseif (strncmp($m[1], '...', 3) === 0 && preg_match('~[?&]file=([^&]+)~', $link->getAttribute('href'), $full)) {
				$m[1] = rawurldecode($full[1]); // Tracy 2.4 shortens the path in the title
			}

			return [$m[1], (int) $m[2]];
		}

		return [null, null];
	}


	/**
	 * @return list<array{line: int, code: string, highlighted: bool}>
	 */
	private function getCode(\DOMNode $context, int $radius): array
	{
		$pre = $this->query(".//pre[contains(@class, 'code')]", $context)[0] ?? null;
		if ($pre === null) {
			return [];
		}

		$highlighted = $this->getText($pre, ".//*[contains(@class, 'highlight')]");
		$highlightedLine = preg_match('~^(\d+):~', $highlighted, $m) ? (int) $m[1] : null;
		$lines = [];
		foreach (explode("\n", $pre->textContent) as $text) {
			if (preg_match('~^\s*(\d+):\s?(.*)$~', $text, $m)) {
				$lines[(int) $m[1]] = rtrim($m[2]);
			}
		}

		$result = [];
		foreach ($lines as $number => $code) {
			if ($highlightedLine === null || abs($number - $highlightedLine) <= $radius) {
				$result[] = ['line' => $number, 'code' => $code, 'highlighted' => $number === $highlightedLine];
			}
		}

		return $result;
	}


	/**
	 * @return list<array{file: ?string, line: ?int, call: string}>
	 */
	private function getStack(\DOMNode $section, int $limit): array
	{
		$result = [];
		$callees = $this->query(".//*[contains(@class, 'tracy-callstack-callee')]", $section);
		if ($callees) {
			foreach ($callees as $callee) {
				$file = $this->query("preceding-sibling::*[contains(@class, 'tracy-callstack-file')][1]", $callee)[0] ?? null;
				[$path, $line] = $file ? $this->getEditorLink($file) : [null, null];
				$result[] = ['file' => $path, 'line' => $line, 'call' => self::normalize($callee->textContent)];
			}

		} else { // Tracy 2.4–2.8: ol > li > p with the file link, a "source" toggle and the call
			foreach ($this->query('.//ol/li/p', $section) as $paragraph) {
				[$path, $line] = $this->getEditorLink($paragraph);
				$call = '';
				foreach ($paragraph->childNodes as $child) {
					$call .= $child instanceof \DOMElement && $child->nodeName === 'a' ? ' ' : $child->textContent;
				}

				$result[] = ['file' => $path, 'line' => $line, 'call' => self::normalize($call)];
			}
		}

		return array_slice($result, 0, $limit);
	}


	private function getText(\DOMNode $context, string $query): string
	{
		$node = $this->query($query, $context)[0] ?? null;
		return $node === null ? '' : self::normalize($node->textContent);
	}


	/**
	 * @return list<\DOMElement>
	 */
	private function query(string $query, ?\DOMNode $context = null): array
	{
		$result = [];
		foreach ($this->xpath->query($query, $context) ?: [] as $node) {
			if ($node instanceof \DOMElement) {
				$result[] = $node;
			}
		}

		return $result;
	}


	private static function normalize(string $text): string
	{
		return trim((string) preg_replace('~\s+~u', ' ', $text));
	}
}
