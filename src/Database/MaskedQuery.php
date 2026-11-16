<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Scalar;


/**
 * Recognizes SELECTs whose column usage can be checked before the database evaluates them.
 */
final class MaskedQuery
{
	private const Aggregates = ['AVG', 'COUNT', 'MAX', 'MIN', 'SUM'];
	private const Boundaries = [
		'FROM', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL', 'ON', 'USING',
		'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'WINDOW', 'UNION', 'INTERSECT', 'EXCEPT',
	];

	private Masking $masking;
	private string $platform;
	private string $identifier;

	/** @var list<string> */
	private array $tokens;

	private int $position = 0;

	/** @var array<string, array{name: string, parts: list<string>, sql: string}> alias => table */
	private array $sources = [];

	/** @var list<array{non-empty-list<string>, bool, bool}> name parts, used directly, may be an output alias */
	private array $fields = [];

	/** @var array<string, true> */
	private array $outputAliases = [];

	/** @var list<array{field: ?non-empty-list<string>, alias: ?string, aggregate: bool}> */
	private array $select = [];

	/** @var list<list<array{alias: string, using: list<string>, right: bool}>> */
	private array $from = [];


	public function __construct(Masking $masking, string $code, string $platform)
	{
		$this->masking = $masking;
		$this->platform = $platform;
		$this->identifier = $platform === Connection::Sqlite ? QueryValidator::SqliteIdentifier : QueryValidator::Identifier;
		if (!preg_match_all(
			'~' . $this->identifier . '|\'\'|\d+[a-z_$\x80-\xff][\w$\x80-\xff]*|\d+(?:\.\d+)?|<=|>=|<>|!=|\S~i',
			$code,
			$matches,
		)) {
			self::reject();
		}

		$this->tokens = $matches[0];
		$this->readSelect();
	}


	/**
	 * @return array<string, array{name: string, parts: list<string>, sql: string}>  alias => table
	 */
	public function getSources(): array
	{
		return $this->sources;
	}


	/**
	 * @param array<string, array<string, bool>> $columns  alias => column => included in a wildcard
	 */
	public function checkFields(array $columns, bool $explain): QueryProjection
	{
		foreach ($this->fields as [$parts, $direct, $usesOutputAlias]) {
			$name = end($parts);
			$qualifier = count($parts) === 2 ? $parts[0] : null;
			if ($qualifier === null && isset($this->sources[$name])) {
				self::reject(); // a PostgreSQL source name can refer to an entire row
			}

			if ($usesOutputAlias && $qualifier === null && isset($this->outputAliases[$name])) {
				continue; // only public projections can have output aliases
			}

			$possibleSources = $qualifier === null ? $columns : [$columns[$qualifier] ?? []];
			if ($name === '*') {
				if (!$possibleSources || $possibleSources === [[]]) {
					self::reject();
				}

				if ($explain && $direct) {
					foreach ($possibleSources as $names) {
						foreach (array_keys($names) as $column) {
							if ($this->masking->isMaskedColumn(null, (string) $column)) {
								self::reject();
							}
						}
					}
				}

				continue;
			}

			$found = false;
			foreach ($possibleSources as $names) {
				if (isset($names[$name])) {
					$found = true;
					break;
				}
			}

			if (!$found || ((!$direct || $explain) && $this->masking->isMaskedColumn(null, $name))) {
				self::reject();
			}
		}

		return new QueryProjection($this->masking, $this->getOutputColumns($columns));
	}


	private function readSelect(): void
	{
		$this->expect('SELECT');
		$distinct = $this->accept('DISTINCT');
		do {
			$aggregate = in_array($this->keyword(), self::Aggregates, true) && ($this->tokens[$this->position + 1] ?? '') === '(';
			if ($aggregate) {
				$function = $this->keyword();
				$this->position += 2;
				$field = $this->readValue($function === 'COUNT');
				$this->expect(')');
			} else {
				$field = $this->readValue(true);
			}

			$aliased = $this->accept('AS');
			$alias = null;
			if ($aliased) {
				$alias = $this->normalize($this->readIdentifier());
				if ($field !== null && end($field) === '*') {
					self::reject();
				}

				$this->outputAliases[$alias] = true;
			}

			if ($field !== null) {
				if ($distinct && end($field) === '*') {
					self::reject();
				}

				$this->addField($field, !$aggregate && !$distinct && !$aliased);
			}

			$this->select[] = ['field' => $field, 'alias' => $alias, 'aggregate' => $aggregate];
		} while ($this->accept(','));

		if ($this->accept('FROM')) {
			$this->from = [[['alias' => $this->readSource(), 'using' => [], 'right' => false]]];
			while (($comma = $this->accept(',')) || ($join = $this->readJoin()) !== null) {
				if ($comma) {
					$this->from[] = [];
				}

				$source = $this->readSource();
				$using = [];
				if ($this->accept('ON')) {
					$this->readPredicate();
				} elseif ($this->accept('USING')) {
					$this->expect('(');
					do {
						$column = $this->normalize($this->readIdentifier());
						$this->addField([$column], false);
						$using[] = $column;
					} while ($this->accept(','));
					$this->expect(')');
				}

				$this->from[count($this->from) - 1][] = ['alias' => $source, 'using' => $using, 'right' => !$comma && $join === 'RIGHT'];
			}
		}

		if ($this->accept('WHERE')) {
			$this->readPredicate();
		}

		if ($this->accept('GROUP')) {
			$this->expect('BY');
			do {
				$this->addField($this->readField(), false, true);
			} while ($this->accept(','));
			if ($this->accept('HAVING')) {
				$this->readPredicate();
			}
		}

		if ($this->accept('ORDER')) {
			$this->expect('BY');
			do {
				$this->addField($this->readField(), false, true);
				$this->accept('ASC') || $this->accept('DESC');
			} while ($this->accept(','));
		}

		if ($this->accept('LIMIT')) {
			$this->readInteger();
			if ($this->accept('OFFSET') || $this->accept(',')) {
				$this->readInteger();
			}
		}

		if ($this->peek() !== '') {
			self::reject();
		}
	}


	private function readSource(): string
	{
		$parts = [$this->readIdentifier()];
		while (count($parts) < 3 && $this->accept('.')) {
			$parts[] = $this->readIdentifier();
		}

		$name = $this->normalize(end($parts));
		$alias = $name;
		if ($this->accept('AS') || ($this->isIdentifier() && !in_array($this->keyword(), self::Boundaries, true))) {
			$alias = $this->normalize($this->readIdentifier());
		}

		if (isset($this->sources[$alias])) {
			self::reject();
		}

		$this->sources[$alias] = ['name' => $name, 'parts' => $parts, 'sql' => implode('.', $parts)];
		return $alias;
	}


	private function readJoin(): ?string
	{
		if (in_array($this->keyword(), ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
			$type = $this->keyword();
			$this->position++;
			$this->accept('OUTER');
			$this->expect('JOIN');
			return $type;
		}

		return $this->accept('JOIN') ? 'INNER' : null;
	}


	/**
	 * @param array<string, array<string, bool>> $columns
	 * @return list<array{name: ?string, sources: list<string>, label: ?string}>
	 */
	private function getOutputColumns(array $columns): array
	{
		$joined = [];
		foreach ($this->from as $group) {
			$groupColumns = [];
			foreach ($group as $source) {
				$next = $this->getSourceColumns($columns, $source['alias']);
				$groupColumns = $this->joinColumns($groupColumns, $next, $source['using'], $source['right']);
			}

			$joined = array_merge($joined, $groupColumns);
		}

		$output = [];
		foreach ($this->select as $projection) {
			$field = $projection['field'];
			if ($field === null || $projection['aggregate']) {
				$output[] = ['name' => null, 'sources' => [], 'label' => $projection['alias']];
				continue;
			}

			$name = end($field);
			if ($name === '*') {
				$expanded = count($field) === 1 ? $joined : $this->getSourceColumns($columns, $field[0]);
				foreach ($expanded as $column) {
					if ($column['visible'] === null) {
						self::reject();
					} elseif ($column['visible']) {
						$output[] = ['name' => $column['name'], 'sources' => $column['sources'], 'label' => null];
					}
				}
			} else {
				$aliases = count($field) === 2
					? [$field[0]]
					: array_map('strval', array_keys(array_filter($columns, fn(array $names) => isset($names[$name]))));
				$output[] = ['name' => $name, 'sources' => $aliases, 'label' => $projection['alias']];
			}
		}

		return $output;
	}


	/**
	 * @param array<string, array<string, bool>> $columns
	 * @return list<array{name: string, sources: list<string>, visible: bool}>
	 */
	private function getSourceColumns(array $columns, string $alias): array
	{
		$result = [];
		foreach ($columns[$alias] as $name => $visible) {
			$result[] = ['name' => (string) $name, 'sources' => [$alias], 'visible' => $visible];
		}

		return $result;
	}


	/**
	 * @param list<array{name: string, sources: list<string>, visible: ?bool}> $left
	 * @param list<array{name: string, sources: list<string>, visible: ?bool}> $right
	 * @param list<string> $using
	 * @return list<array{name: string, sources: list<string>, visible: ?bool}>  visible null when it depends on the server
	 */
	private function joinColumns(array $left, array $right, array $using, bool $rightJoin): array
	{
		if (!$using) {
			return array_merge($left, $right);
		}

		// MySQL normalizes RIGHT ... USING to a LEFT join, including its output order.
		if ($rightJoin && $this->platform === Connection::MySql) {
			[$left, $right] = [$right, $left];
		}

		$rightByName = array_column($right, null, 'name');
		$common = [];
		foreach ($left as &$column) {
			if (in_array($column['name'], $using, true)) {
				if (!isset($rightByName[$column['name']])) {
					self::reject();
				}

				$column['sources'] = array_values(array_unique(array_merge($column['sources'], $rightByName[$column['name']]['sources'])));
				// MySQL shows a joined invisible column in *, MariaDB does not; such a * is refused
				$column['visible'] = $column['visible'] && $rightByName[$column['name']]['visible'] ? true : null;
				$common[$column['name']] = $column;
			}
		}
		unset($column);

		$remainingRight = array_values(array_filter($right, fn(array $column) => !in_array($column['name'], $using, true)));
		if ($this->platform === Connection::Sqlite) {
			return array_merge($left, $remainingRight);
		}

		$ordered = [];
		foreach ($this->platform === Connection::PostgreSql ? $using : array_keys($common) as $name) {
			if (!isset($common[$name])) {
				self::reject();
			}

			$ordered[] = $common[$name];
		}

		$remainingLeft = array_values(array_filter($left, fn(array $column) => !in_array($column['name'], $using, true)));
		return array_merge($ordered, $remainingLeft, $remainingRight);
	}


	private function readPredicate(): void
	{
		$start = $this->position;
		$depth = 0;
		while (($token = $this->peek()) !== '') {
			$keyword = $this->keyword();
			if ($depth === 0 && ($token === ',' || in_array($keyword, self::Boundaries, true))) {
				break;
			} elseif ($token === '(') {
				$depth++;
			} elseif ($token === ')') {
				if (--$depth < 0) {
					self::reject();
				}
			} elseif ($this->isIdentifier() && $this->masking->isMaskedColumn(null, $this->normalize($token))) {
				self::reject();
			} elseif (
				!in_array($keyword, ['AND', 'OR', 'NOT', 'IN', 'IS', 'BETWEEN', 'LIKE', 'ILIKE', 'GLOB', 'ESCAPE'], true)
				&& !in_array($token, [',', '=', '<', '>', '<=', '>=', '<>', '!=', '+', '-', '*', '/', '%'], true)
			) {
				$field = $this->readValue();
				if ($field !== null) {
					if ($this->peek() === '(') {
						self::reject();
					}

					$this->addField($field, false);
				}

				continue;
			}

			$this->position++;
		}

		if ($start === $this->position || $depth !== 0) {
			self::reject();
		}
	}


	/**
	 * @return ?non-empty-list<string>  name parts of a field, null for a literal
	 */
	private function readValue(bool $wildcard = false): ?array
	{
		if (
			in_array($this->keyword(), ["''", 'NULL'], true)
			|| ($this->platform !== Connection::Sqlite && in_array($this->keyword(), ['TRUE', 'FALSE'], true))
		) {
			$this->position++;
			return null;
		}

		if ($this->accept('-') || $this->accept('+')) {
			if (!preg_match('~^\d+(?:\.\d+)?$~D', $this->peek())) {
				self::reject();
			}
		}

		if (preg_match('~^\d+(?:\.\d+)?$~D', $this->peek())) {
			$this->position++;
			return null;
		}

		return $this->readField($wildcard);
	}


	/**
	 * @return non-empty-list<string>
	 */
	private function readField(bool $wildcard = false): array
	{
		if ($wildcard && $this->accept('*')) {
			return ['*'];
		}

		$name = $this->normalize($this->readIdentifier());
		if (!$this->accept('.')) {
			return [$name];
		}

		return [$name, $wildcard && $this->accept('*') ? '*' : $this->normalize($this->readIdentifier())];
	}


	/**
	 * @param non-empty-list<string> $parts
	 */
	private function addField(array $parts, bool $direct, bool $usesOutputAlias = false): void
	{
		if (!$direct && $this->masking->isMaskedColumn(null, end($parts))) {
			self::reject();
		}

		$this->fields[] = [$parts, $direct, $usesOutputAlias];
	}


	private function readIdentifier(): string
	{
		if (!$this->isIdentifier() || in_array($this->keyword(), ['SELECT', 'WITH', 'TABLE', 'VALUES', 'NULL', 'TRUE', 'FALSE'], true)) {
			self::reject();
		}

		$identifier = $this->tokens[$this->position++];
		if ($this->platform === Connection::PostgreSql && strlen(QueryValidator::unquoteIdentifier($identifier)) > 63) {
			self::reject(); // PostgreSQL can silently truncate longer identifiers
		}

		return $identifier;
	}


	private function readInteger(): void
	{
		if (!Scalar::isDigits($this->peek())) {
			self::reject();
		}

		$this->position++;
	}


	private function isIdentifier(): bool
	{
		return preg_match('~^' . $this->identifier . '$~D', $this->peek()) === 1;
	}


	private function expect(string $token): void
	{
		if (!$this->accept($token)) {
			self::reject();
		}
	}


	private function accept(string $token): bool
	{
		if ($this->keyword() !== $token) {
			return false;
		}

		$this->position++;
		return true;
	}


	private function keyword(): string
	{
		return strtoupper($this->peek());
	}


	private function peek(): string
	{
		return $this->tokens[$this->position] ?? '';
	}


	private function normalize(string $identifier): string
	{
		$name = QueryValidator::unquoteIdentifier($identifier);
		return $this->platform === Connection::PostgreSql && $identifier[0] === '"' ? $name : strtolower($name);
	}


	/**
	 * @return never
	 */
	private static function reject(): void
	{
		throw new InspectorException(QueryValidator::SupportedQuery . ' Protected columns can only be selected directly under their original names.');
	}
}
