<?php declare(strict_types=1);

namespace Lupa;


final class HtmlMasker
{
	private const StructuralAttributes = [
		'name', 'id', 'type', 'class', 'disabled', 'readonly', 'required', 'autocomplete',
		'inputmode', 'maxlength', 'minlength', 'size', 'rows', 'cols', 'multiple', 'colspan', 'rowspan',
	];

	/** Labels of a secret, whose value next to them is hidden; a narrow list, as headers like Hash or Passenger are public. */
	private const SecretLabel = '~^(?:pass(?:word)?|passwd|heslo|secret|token|(?:api|access|secret|private)[ _-]?(?:key|klíč)'
		. '|client[ _-]?secret)$~iu';


	/**
	 * @param callable(string): string $sanitize
	 * @param callable(string): bool $isSensitive
	 */
	public function mask(string $html, callable $sanitize, callable $isSensitive): string
	{
		if (!class_exists(\DOMDocument::class)) {
			return Masking::Mask;
		}

		// the parser cuts names in tags, of attributes or a doctype, at characters like @, which would leave a part of data
		// written there, so such a page is hidden; values of attributes, also with > inside, are masked below
		if (preg_match_all('~<(!?/?[a-z](?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+)>~i', $html, $tags)) {
			$names = implode("\n", (array) preg_replace('~=\s*(?:"[^"]*+"|\'[^\']*+\'|[^\s"\'>]++)~', '', $tags[1]));
			if ($sanitize($names) !== $names) {
				return Masking::Mask;
			}
		}

		$html = str_replace("\0", '', $html); // the parser would end the document there
		$html = (string) preg_replace('~&(amp|lt|gt|quot|nbsp)(?![\w;])~i', '&$1;', $html); // as browsers read them without ;
		// errors of real pages, such as a bare &, are recovered from; the result is made only of the masked nodes below
		$previous = libxml_use_internal_errors(true);
		try {
			$document = new \DOMDocument;
			$loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded) {
			return Masking::Mask;
		}
		// the declaration of the encoding added above; libxml 2.14 reads it as a comment
		foreach (iterator_to_array($document->childNodes) as $node) {
			if ($node->nodeType === XML_PI_NODE || ($node instanceof \DOMComment && $node->data === '?xml encoding="UTF-8"')) {
				$document->removeChild($node);
				break;
			}
		}
		if (!$document->hasChildNodes()) { // e.g. an unfinished tag, which libxml 2.14 drops with all it holds
			return Masking::Mask;
		}
		// by XPath, as a list from getElementsByTagName() walks the tree again for every item in PHP before 8.3
		$xpath = new \DOMXPath($document);
		$elements = [];
		foreach ($xpath->query('//*') ?: [] as $element) {
			if ($element instanceof \DOMElement) {
				$elements[] = $element;
			}
		}

		$this->maskLabelledValues($elements);
		foreach ($elements as $element) {
			$sensitive = strcasecmp($element->getAttribute('type'), 'password') === 0;
			foreach (['name', 'id'] as $attribute) {
				$name = $element->getAttribute($attribute);
				for ($i = 0; $i < 8; $i++) {
					$decoded = urldecode($name);
					if ($decoded === $name) {
						break;
					}
					$name = $decoded;
				}
				$sensitive = $sensitive || $isSensitive($name);
			}
			if ($sensitive && $element->hasChildNodes()) {
				$element->textContent = Masking::Mask;
			}
			foreach (iterator_to_array($element->attributes) as $attribute) {
				if ($sanitize($attribute->name) !== $attribute->name) { // a name that hides data, which no real attribute has
					$element->removeAttributeNode($attribute);
					continue;
				}

				$structural = in_array(strtolower($attribute->name), self::StructuralAttributes, true);
				// set as text, as a value assigned to the attribute node would be read for entities, a & in a URL cutting it
				$element->setAttribute($attribute->name, ($sensitive && !$structural) || $isSensitive($attribute->name)
					? Masking::Mask
					: $sanitize($attribute->value));
			}
		}
		$nodes = $xpath->query('//text()|//comment()|//processing-instruction()');
		if ($nodes === false) {
			return Masking::Mask;
		}
		foreach ($nodes as $node) {
			$node->nodeValue = $sanitize((string) $node->nodeValue);
		}

		// node by node, as the whole document would be written with entities in place of non-ASCII characters
		$result = '';
		foreach ($document->childNodes as $node) {
			$result .= $node instanceof \DOMDocumentType
				? '<!DOCTYPE ' . $sanitize($node->name) . ">\n" // without the identifiers, which nothing masks
				: (string) $document->saveHTML($node);
		}
		return $result;
	}


	/**
	 * Hides a value next to its label: in the next cells of a row (<th>Password</th><td>…</td>), in a column
	 * under its header, in the descriptions of a term (<dt>Heslo</dt><dd>…</dd>), in the control a label is for
	 * or contains, and in what follows any other element holding just the label (<strong>Token:</strong> …).
	 * @param array<\DOMElement> $elements
	 */
	private function maskLabelledValues(array $elements): void
	{
		$byId = [];
		foreach ($elements as $element) {
			$byId[$element->getAttribute('id')][] = $element;
		}

		unset($byId['']);
		$headerRows = $tables = [];
		foreach ($elements as $element) {
			$label = self::getLabel($element);
			if ($label === null || !preg_match(self::SecretLabel, $label)) {
				continue;
			}

			// a label wrapped in an element of its cell, as in <th><a href="…">Password</a></th>, labels the cell
			while (!preg_match('~^(?:th|td|dt|dd|li|label)$~', strtolower($element->nodeName))
				&& !self::hasContentAfter($element) && $element->parentNode instanceof \DOMElement
				&& self::normalizeLabel($element->parentNode->textContent) === $label
			) {
				$element = $element->parentNode;
			}

			$tag = strtolower($element->nodeName);
			$controls = $tag === 'label' ? $byId[$element->getAttribute('for')] ?? [] : [];
			foreach (['input', 'textarea', 'select'] as $controlTag) {
				$controls = array_merge($controls, iterator_to_array($element->getElementsByTagName($controlTag)));
			}

			foreach ($controls as $control) {
				self::hide($control);
			}

			if ($tag === 'label' && $controls) {
				continue; // what follows a label with its control is another field
			}

			$row = $element->parentNode;
			if ($tag === 'th' && $row instanceof \DOMElement && ($headerRows[spl_object_id($row)] ??= self::isHeaderRow($row))) {
				$table = self::findTable($row);
				if ($table !== null) { // hidden at once below, as a pass over the table for each header takes cubic time
					$tables[spl_object_id($table)][0] = $table;
					$tables[spl_object_id($table)][1][spl_object_id($element)] = true;
				}

				continue;
			}

			// the following siblings: cells up to the next header, descriptions up to the next term, else one node;
			// a heading or a link names a section, not a value
			$cells = ['th' => ['td'], 'td' => ['td'], 'dt' => ['dd']][$tag] ?? null;
			if ($cells === null && preg_match('~^(?:a|h[1-6]|caption|legend|title|button|option)$~', $tag)) {
				continue;
			}

			for ($node = $element->nextSibling; $node !== null; $node = $node->nextSibling) {
				$name = $node instanceof \DOMElement ? strtolower($node->nodeName) : null;
				if (($node instanceof \DOMText && trim($node->nodeValue ?? '') === '') || $name === 'br' || $node instanceof \DOMComment) {
					continue;
				} elseif ($cells !== null && !in_array($name, $cells, true)) {
					break;
				}

				self::hide($node);
				if ($cells === null) {
					break;
				}
			}
		}

		foreach ($tables as [$table, $headers]) {
			self::hideColumns($table, $headers);
		}
	}


	/**
	 * The text of an element that holds just a short label, without a colon; null for others, e.g. the row around a cell.
	 */
	private static function getLabel(\DOMElement $element): ?string
	{
		$label = self::normalizeLabel($element->textContent);
		if ($label === '' || strlen($label) > 40) {
			return null;
		}

		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement && self::normalizeLabel($child->textContent) === $label) {
				return null; // the child holds it
			}
		}

		return $label;
	}


	private static function normalizeLabel(string $text): string
	{
		return rtrim(trim((string) preg_replace('~\s+~u', ' ', $text)), ': ');
	}


	/**
	 * Whether a value may follow the node in its parent: an element other than a line break, or text with a letter or digit.
	 */
	private static function hasContentAfter(\DOMNode $node): bool
	{
		for ($next = $node->nextSibling; $next !== null; $next = $next->nextSibling) {
			if (($next instanceof \DOMElement && strtolower($next->nodeName) !== 'br')
				|| ($next instanceof \DOMText && preg_match('~[\pL\d]~u', (string) $next->nodeValue))
			) {
				return true;
			}
		}

		return false;
	}


	private static function hide(\DOMNode $node): void
	{
		if ($node instanceof \DOMElement) {
			foreach (iterator_to_array($node->attributes) as $attribute) {
				if (!in_array(strtolower($attribute->name), self::StructuralAttributes, true)) {
					$node->setAttribute($attribute->name, Masking::Mask);
				}
			}
		}

		if ($node instanceof \DOMText || $node->hasChildNodes()) {
			$node->textContent = Masking::Mask;
		}
	}


	private static function isHeaderRow(\DOMElement $row): bool
	{
		if (strtolower($row->nodeName) !== 'tr') {
			return false;
		}

		foreach ($row->childNodes as $cell) {
			if ($cell instanceof \DOMElement && strtolower($cell->nodeName) === 'td') {
				return false;
			}
		}

		return true;
	}


	private static function findTable(\DOMNode $node): ?\DOMElement
	{
		for ($table = $node->parentNode; $table !== null; $table = $table->parentNode) {
			if ($table instanceof \DOMElement && strtolower($table->nodeName) === 'table') {
				return $table;
			}
		}

		return null;
	}


	/**
	 * Hides the cells under the headers in the other rows of their table, by columns counted with colspan,
	 * every row passed once.
	 * @param array<int, true> $headers  object ids of the header cells
	 */
	private static function hideColumns(\DOMElement $table, array $headers): void
	{
		$rows = [];
		foreach ((new \DOMXPath($table->ownerDocument ?? new \DOMDocument))->query('.//tr', $table) ?: [] as $row) {
			if (!$row instanceof \DOMElement) {
				continue;
			}

			$cells = [];
			$column = 0;
			foreach ($row->childNodes as $cell) {
				if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
					$span = min(1000, max(1, (int) $cell->getAttribute('colspan')));
					$cells[] = [$cell, $column, $span];
					$column += $span;
				}
			}

			$rows[] = $cells;
		}

		$secret = [];
		foreach ($rows as $cells) {
			foreach ($cells as [$cell, $column, $span]) {
				if (isset($headers[spl_object_id($cell)])) {
					$secret += array_fill_keys(range($column, $column + $span - 1), true);
				}
			}
		}

		foreach ($rows as $cells) {
			foreach ($cells as [$cell, $column, $span]) {
				if (!isset($headers[spl_object_id($cell)])
					&& array_intersect_key($secret, array_fill_keys(range($column, $column + $span - 1), true))
				) {
					self::hide($cell);
				}
			}
		}
	}
}
