<?php declare(strict_types=1);

namespace Lupa\Database;

use Lupa\InspectorException;


/**
 * Rejects multiple statements and operations that a read-only transaction alone cannot contain.
 * MaskedQuery checks the supported SELECT syntax and its column references.
 */
final class QueryValidator
{
	public const SupportedQuery = 'Only direct SELECTs over verifiable base tables are supported. Use db_table for schema details and db_explain for query plans.';

	public const Identifier = '(?:[a-z_\x80-\xff][\w$\x80-\xff]*|"(?:[^"]|"")*+"|`(?:[^`]|``)*+`)';
	public const SqliteIdentifier = '(?:' . self::Identifier . '|' . self::BracketIdentifier . ')';

	private const BracketIdentifier = '\[[^\]]+\]';

	private const ForbiddenWords = [
		'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'UPSERT', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME',
		'GRANT', 'REVOKE', 'CALL', 'HANDLER', 'LOCK', 'UNLOCK', 'LOAD', 'INTO', 'OUTFILE', 'DUMPFILE', 'ATTACH',
		'DETACH', 'PRAGMA', 'VACUUM', 'REINDEX', 'COPY', 'NOTIFY', 'LISTEN', 'PREPARE', 'EXECUTE', 'DEALLOCATE',
		'SHUTDOWN', 'KILL', 'FLUSH', 'RESET', 'PURGE', 'INSTALL', 'UNINSTALL', 'SET',
	];

	private const ForbiddenFunctions = [
		'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK',
		'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET', 'PG_SLEEP', 'PG_READ_FILE',
		'PG_READ_BINARY_FILE', 'PG_LS_DIR', 'LO_IMPORT', 'LO_EXPORT', 'DBLINK', 'DBLINK_EXEC', 'NEXTVAL', 'SETVAL',
		'SET_CONFIG', 'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND', 'PG_RELOAD_CONF', 'PG_ADVISORY_LOCK',
		'SYS_EXEC', 'SYS_EVAL', 'LOAD_EXTENSION', 'READFILE', 'WRITEFILE',
	];


	/**
	 * Returns the statement without a trailing semicolon, or throws an exception saying why it is refused.
	 */
	public function validate(string $sql, ?string $platform = null): string
	{
		$sql = trim($sql);
		if (preg_match('~/\*M?!~i', $sql)) {
			throw new InspectorException('Executable comments /*! … */ and /*M! … */ are not allowed.');
		}

		// Whether a backslash escapes a quote depends on the server (NO_BACKSLASH_ESCAPES in MySQL,
		// standard_conforming_strings in PostgreSQL), so the query must pass under both readings.
		self::check(self::strip($sql, true, $platform));
		self::check(self::strip($sql, false, $platform));
		return (string) preg_replace('~;\s*$~', '', $sql);
	}


	public function stripLiteralsAndComments(string $sql, bool $backslashEscapes, string $platform): string
	{
		return self::strip($sql, $backslashEscapes, $platform, true);
	}


	/**
	 * Puts SQL literals in place of the ? placeholders that stand outside literals, comments and quoted names.
	 * The placeholders must be the same whether or not a backslash escapes a quote, as the server decides that.
	 * @param list<string> $literals
	 */
	public function bind(string $sql, array $literals, string $platform): string
	{
		$offsets = self::findPlaceholders($sql, false, $platform);
		if ($offsets !== self::findPlaceholders($sql, true, $platform)) {
			throw new InspectorException('The query must have the same structure with and without backslash escapes.');
		} elseif (count($offsets) !== count($literals)) {
			throw new InspectorException(sprintf(
				'The query has %d placeholder(s) but %d parameter(s) were given.',
				count($offsets),
				count($literals),
			));
		}

		foreach (array_reverse($offsets, true) as $i => $offset) {
			$sql = substr_replace($sql, $literals[$i], $offset, 1);
		}

		return $sql;
	}


	public static function unquoteIdentifier(string $name): string
	{
		if ($name[0] === '[') {
			return substr($name, 1, -1);
		} elseif ($name[0] === '"' || $name[0] === '`') {
			return str_replace($name[0] . $name[0], $name[0], substr($name, 1, -1));
		}

		return $name;
	}


	private static function check(string $stripped): void
	{
		$stripped = (string) preg_replace('~;\s*$~', '', $stripped);
		if (strpos($stripped, ';') !== false) {
			throw new InspectorException('Only a single statement is allowed.');
		} elseif (!preg_match('~^\s*SELECT\b~i', $stripped)) {
			throw new InspectorException(self::SupportedQuery);
		} elseif (preg_match('~\b(' . implode('|', self::ForbiddenWords) . ')\b~i', $stripped, $m)) {
			throw new InspectorException("The keyword $m[1] is not allowed in a read-only query.");
		} elseif (preg_match('~\b(' . implode('|', self::ForbiddenFunctions) . ')\s*\(~i', $stripped, $m)) {
			throw new InspectorException("The function $m[1]() is not allowed.");
		} elseif (preg_match('~:=|\bFOR\s+(UPDATE|SHARE|NO\s+KEY|KEY)\b|\bANALYZE\b~i', $stripped, $m)) {
			throw new InspectorException("'$m[0]' is not allowed in a read-only query.");
		}
	}


	/**
	 * Removes literals and comments, preserving quoted names as needed for function or column checks.
	 */
	private static function strip(string $sql, bool $backslashEscapes, ?string $platform, bool $keepIdentifiers = false): string
	{
		$result = preg_replace_callback(
			'~' . self::getTokenPattern($backslashEscapes, $platform, $keepIdentifiers) . '~s',
			static function (array $match) use ($keepIdentifiers, $platform): string {
				$token = $match[0];
				$first = $token[0];
				if (isset($match['qualified'])) {
					// SQLite also accepts single quotes around a qualified column name
					$name = str_replace("''", "'", substr($match['qualified'], 1, -1));
					return '."' . str_replace('"', '""', $name) . '"';
				} elseif ($first === '/') {
					if (strpos($token, '/*', 2) !== false) {
						throw new InspectorException('Nested SQL comments are not allowed.');
					}

					return ' ';

				} elseif ($first === '-') {
					if ($platform === null && isset($token[2]) && strpos(" \t\n\r\v\f", $token[2]) === false) {
						throw new InspectorException('A -- comment without following whitespace requires a known database platform.');
					}

					return ' ';

				} elseif ($first === '#') {
					throw new InspectorException('SQL comments starting with # are not allowed.');
				} elseif ($first === 'u' || $first === 'U') {
					throw new InspectorException('Unicode escape syntax in quoted identifiers is not allowed.');
				} elseif ($first === '$') {
					throw new InspectorException('Dollar-quoted strings are not allowed; use single quotes.');
				} elseif ($first === "'" || $first === 'e' || $first === 'E') {
					return "''";
				} elseif ($keepIdentifiers) {
					return $token;
				}

				$name = self::unquoteIdentifier($token);
				return in_array(strtoupper($name), self::ForbiddenFunctions, true)
					? ' ' . $name . ' '
					: ($first === '[' ? $token : $first . $first);
			},
			$sql,
		);
		if ($result === null || (!$keepIdentifiers && preg_match('~[\'"`]|/\*~', (string) preg_replace('~\'\'|""|``~', '', $result)))) {
			throw new InspectorException('The query has an unterminated string, identifier or comment.');
		}

		return $result;
	}


	/**
	 * Offsets of the ? placeholders that stand outside literals, comments and quoted names.
	 * @return list<int>
	 */
	private static function findPlaceholders(string $sql, bool $backslashEscapes, string $platform): array
	{
		$pattern = '~' . self::getTokenPattern($backslashEscapes, $platform, true) . '|(?<placeholder>\?)~s';
		preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$offsets = [];
		foreach ($matches as $match) {
			if (($match['placeholder'][0] ?? '') === '?') {
				$offsets[] = $match['placeholder'][1];
			}
		}

		return $offsets;
	}


	/**
	 * A pattern matching string literals, quoted names and comments.
	 */
	private static function getTokenPattern(bool $backslashEscapes, ?string $platform, bool $keepIdentifiers): string
	{
		$single = $backslashEscapes ? '\'(?:[^\'\\\\]|\\\\.|\'\')*+\'' : '\'(?:[^\']|\'\')*+\'';
		$double = $backslashEscapes ? '"(?:[^"\\\\]|\\\\.|"")*+"' : '"(?:[^"]|"")*+"';
		$lineComment = $platform === Connection::MySql ? '--(?=\s|$)[^\r\n]*' : '--[^\r\n]*';
		$hashComment = $platform === null || $platform === Connection::MySql ? '|#[^\r\n]*' : '';
		// PostgreSQL: E'…' always takes backslash escapes, and $$…$$ or $tag$…$tag$ quotes a string
		$postgreSql = $platform !== Connection::MySql && $platform !== Connection::Sqlite
			? '(?<![\w$\x80-\xff])[eE]\'(?:[^\'\\\\]|\\\\.|\'\')*+\'|\$(?:[a-zA-Z_\x80-\xff][\w\x80-\xff]*)?\$|'
			: '';
		$qualified = $keepIdentifiers ? '\.(?:\s|/\*.*?\*/|' . $lineComment . ')*+(?<qualified>' . $single . ')|' : '';
		$brackets = $platform === Connection::Sqlite || $platform === null
			? '|' . ($keepIdentifiers ? self::BracketIdentifier : '\[[\w$]+\]')
			: '';
		return $qualified
			. '\b[uU]&(?=")|'
			. $postgreSql
			. $single
			. '|'
			. $double
			. '|`(?:[^`]|``)*+`'
			. $brackets
			. '|'
			. $lineComment
			. $hashComment
			. '|/\*.*?\*/';
	}
}
