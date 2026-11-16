<?php declare(strict_types=1);

namespace Lupa;


/**
 * Hides secrets and personal data in everything a tool returns: values under secret-looking keys or configured
 * columns, and e-mails, phone numbers, birth numbers, bank accounts and card numbers found by their shape.
 * Masks keep a hint of the original (first letter, last digits) so that debugging stays possible.
 */
final class Masking
{
	public const Mask = '***';

	public const Detectors = ['email', 'phone', 'birthNumber', 'iban', 'card'];

	/**
	 * A text that begins like a JSON document; when it cannot be decoded, it is a document cut short or broken.
	 * Wiki links like [[page]] or [{ note }] are no documents.
	 */
	public const JsonStart = '~^[ \t\r\n]*(?:\{\s*"|\[\s*(?:"|\{\s*["}]|\[\s*(?:["{\[\]\d-]|true|false|null)))~';
	private const MaxDepth = 512;
	private const MaxTextBytes = 1_048_576;

	/** auth matches authorization and authentication, but not author; heslo is Czech for a password */
	private const SecretKeyPattern = '~pass|pwd|secret|token|(?:api|access)[_ -]?key|credential|private|salt|hash|dsn|cookie|sess(?:ion|id)|[cx]srf|heslo|auth(?!or(?!i[sz]))~i';

	/**
	 * Secrets recognizable by their shape, always hidden: credentials in a URL (the password may contain @ and /,
	 * the user may be missing) or before a host, the password of a MySQL client, bearer tokens, JWTs, password hashes
	 * and the whole value of HTTP headers that carry credentials.
	 */
	private const SecretShapes = [
		'~(?<=//)([^/\s:@]*):[^\s?#]+@~' => '$1:' . self::Mask . '@',
		// the option is looked for only so far behind the command, as each one would otherwise scan to the end of the line
		'~\b(?:mysql|mariadb)[\w-]*\b[^\r\n]{0,200}?\s-p\K(?:\'[^\']*+\'|"[^"]*+"|[^\s\'"]++)~' => self::Mask,
		'~\bsqlplus\S*[ \t]+(?:-\w+[ \t]+)*[\w$#.-]+/\K[^\s@/]++~' => self::Mask,
		'#\b(Bearer|Basic)\s+[\w.~+/=-]{8,}#i' => '$1 ' . self::Mask,
		'~\beyJ[\w-]{4,}\.eyJ[\w-]{4,}\.[\w-]*~' => self::Mask,
		'~\$(?:2[abxy]|[156y]|sha1)\$[^\s\'",;]{20,}~' => self::Mask,
		'~\$argon2(?:i|d|id)\$[^\s\'";]{20,}~' => self::Mask, // its parameters are separated by commas
		'~(?<![\w-])((?:Proxy-)?Authorization|(?:Set-)?Cookie)([ \t]*:[ \t]*)(?!(?:Bearer|Basic) \*{3}[ \t]*$)[^\s\'"][^\r\n]*~im' => '$1$2' . self::Mask,
	];

	/**
	 * A password before a host without a scheme, as in root:x@db.local; looked for after e-mails are masked,
	 * so the first letter left by the e-mail mask is hidden too. mailto is an e-mail URI;
	 * digits alone are no password: host:3306@db.
	 */
	private const CredentialsBeforeHost = '~(?<![\w/.:@%*-])(?!(?i:mailto):)([\w.-]+):(?!//|\d+@)[^\s:@\'"]+@(?=[\w*-])~';

	/** Possessive, because backtracking through a long string exhausts the JIT stack of PCRE. */
	private const QuotedString = '\'(?:[^\'\\\\]++|\\\\.)*+\'|"(?:[^"\\\\]++|\\\\.)*+"';

	/** @var array<string, true> lowercased extra secret keys */
	private array $keys = [];

	/** @var array<string, true> lowercased keys that are never secret, although they look so */
	private array $ignoredKeys = [];

	/** @var array<string, array<string, true>> lowercased table => column => true */
	private array $columns = [];

	/** @var list<string> */
	private array $detectors;


	/**
	 * @param list<string> $keys  additional key or column names to hide
	 * @param array<string, list<string>> $columns  table => columns to hide
	 * @param list<string> $detectors  subset of Detectors
	 * @param list<string> $ignoredKeys  key or column names that are not secret although they look so, e.g. hash
	 */
	public function __construct(
		array $keys = [],
		array $columns = [],
		array $detectors = self::Detectors,
		array $ignoredKeys = []
	) {
		foreach ($ignoredKeys as $key) {
			$this->ignoredKeys[strtolower($key)] = true;
		}

		foreach ($keys as $key) {
			$this->keys[strtolower($key)] = true;
		}

		foreach ($columns as $table => $list) {
			foreach ($list as $column) {
				$this->columns[strtolower($table)][strtolower($column)] = true;
			}
		}

		$this->detectors = $detectors;
	}


	public function isSecretKey(string $key): bool
	{
		$lower = strtolower($key);
		return isset($this->keys[$lower]) || (!isset($this->ignoredKeys[$lower]) && preg_match(self::SecretKeyPattern, $key));
	}


	/**
	 * A column is hidden when its name is secret or the configuration lists it for the table.
	 * Without a known table, a column listed for any table is hidden.
	 */
	public function isMaskedColumn(?string $table, string $column): bool
	{
		if ($this->isSecretKey($column)) {
			return true;
		} elseif ($table !== null) {
			return isset($this->columns[strtolower($table)][strtolower($column)]);
		}

		foreach ($this->columns as $list) {
			if (isset($list[strtolower($column)])) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Masks secret keys and personal data in nested arrays, strings and numbers; a number that has the shape
	 * of personal data (phone, birth number, card) becomes a masked string.
	 * @param mixed $data
	 * @return mixed
	 */
	public function maskData($data)
	{
		return $this->maskValue($data, 0, false);
	}


	/**
	 * @param array<mixed> $data
	 * @param list<string> $identifierMaps  top-level dictionaries whose keys identify roles or other public metadata
	 * @param list<string> $identifierValues  keys whose value names a thing of the code, e.g. a console command
	 *    cache:clear, and is read as one name, not as an assignment; data in it is still found by its shape
	 * @return array<mixed>
	 */
	public function maskArray(array $data, array $identifierMaps = [], array $identifierValues = []): array
	{
		$result = $this->maskValue($data, 0, false, false, $identifierMaps, $identifierValues);
		return is_array($result) ? $result : [];
	}


	/** @param mixed $data
	 * @param list<string> $identifierMaps
	 * @param list<string> $identifierValues
	 * @return mixed
	 */
	private function maskValue(
		$data,
		int $depth,
		bool $document,
		bool $identifiers = false,
		array $identifierMaps = [],
		array $identifierValues = []
	) {
		if ($depth >= self::MaxDepth) {
			return self::Mask;
		} elseif (is_string($data)) {
			return $this->maskString($data, $depth + 1);
		} elseif (is_int($data) || (is_float($data) && floor($data) === $data && abs($data) < 1e19)) {
			$text = is_int($data) ? (string) $data : sprintf('%.0F', $data);
			$masked = $this->maskShapes($text);
			return $masked === $text ? $data : $masked;
		} elseif ((is_object($data) && !$data instanceof \stdClass) || is_resource($data)) {
			return self::Mask;
		} elseif (!is_array($data) && !$data instanceof \stdClass) {
			return $data;
		}

		$isSecret = fn(string $name): bool => $document ? $this->isMaskedColumn(null, $name) : $this->isSecretKey($name);
		$fields = (array) $data;
		// a field given as a pair like ["password", "x"], or as a name and a value like {"name": "password", "value": "x"}
		// of jQuery's serializeArray(); the name is one word, as ["Password reset", "sent"] is no field
		$name = array_keys($fields) === [0, 1] ? $fields[0] : ($fields['name'] ?? $fields['field'] ?? null);
		$named = is_string($name) && !$identifiers && preg_match('~^[\w.-]+$~D', $name) && $isSecret($name);
		$result = [];
		$nextSuffix = [];
		foreach ($fields as $key => $value) {
			$childIdentifiers = $identifiers || ($depth === 0 && is_string($key) && in_array($key, $identifierMaps, true));
			$hidden = ($named && in_array($key, [1, 'value', 'val'], true))
				|| (is_string($key) && (isset($this->keys[strtolower($key)]) || (!$childIdentifiers && $isSecret($key))));
			$maskedKey = is_string($key) ? $this->maskString($key, $depth + 1) : $key;
			$baseKey = $maskedKey;
			if (array_key_exists($maskedKey, $result)) {
				$suffix = $nextSuffix[$baseKey] ?? 2;
				do {
					$maskedKey = $baseKey . ' [' . $suffix++ . ']';
				} while (array_key_exists($maskedKey, $result));
				$nextSuffix[$baseKey] = $suffix;
			}
			if ($hidden && $value !== null && $value !== '' && !is_bool($value)) {
				$result[$maskedKey] = self::Mask;
			} elseif (is_string($value) && in_array($key, $identifierValues, true) && preg_match('~^[\w:.-]{1,100}$~D', $value)) {
				$shaped = preg_replace(array_keys(self::SecretShapes), array_values(self::SecretShapes), $value);
				$result[$maskedKey] = $shaped === null ? self::Mask : $this->maskShapes($shaped);
			} else {
				$result[$maskedKey] = $this->maskValue($value, $depth + 1, $document, $childIdentifiers, [], $identifierValues);
			}
		}

		return $data instanceof \stdClass ? (object) $result : $result;
	}


	/**
	 * Replaces values assigned to secret keys (password: x, 'token' => 'x', "apiKey": "x"), secrets recognizable
	 * by their shape and personal data found by its shape.
	 */
	public function maskText(string $text): string
	{
		return $this->maskString($text, 0);
	}


	public function maskHtml(string $html): string
	{
		if (!preg_match('~<[a-z/!?]~i', $html)) {
			return $this->maskString(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0);
		}
		return $this->maskMarkup($html, 0);
	}


	private function maskMarkup(string $html, int $depth): string
	{
		if ($depth >= self::MaxDepth || strlen($html) > self::MaxTextBytes || !preg_match('~~u', $html)) {
			return self::Mask;
		}
		return (new HtmlMasker)->mask(
			$html,
			fn(string $text): string => $this->maskString($text, $depth + 1),
			fn(string $name): bool => $this->isMaskedColumn(null, $name),
		);
	}


	/**
	 * @param bool $structured  false for text whose documents have been checked, without interpreting markup or splitting query pairs again
	 */
	private function maskString(string $text, int $depth, bool $structured = true): string
	{
		if ($depth >= self::MaxDepth || strlen($text) > self::MaxTextBytes || !preg_match('~~u', $text)) {
			return self::Mask;
		}
		if ($structured && preg_match(
			'~^\\s*<!DOCTYPE|^\\s*<(?:html|body|head|div|p|span|a|table|script|style|form)(?:\\s|>)(?!/|$)|<(?:input|textarea|select|meta)\\s~i',
			$text,
		)
		) {
			return $this->maskMarkup($text, $depth + 1);
		}
		$text = $structured ? $this->maskDocuments($text, $depth) : $text;
		$uncertain = false;
		$replaced = !$structured ? $text : preg_replace_callback(
			'~(^|[?&#;])([^=&;\\s#<>"\\\']+)=([^&;\\s#<>"\\\']*)~',
			function (array $match) use ($depth, &$uncertain): string {
				$prefix = $match[1];
				$encodedKey = $match[2];
				if ($prefix === '' && strpos($encodedKey, '://') !== false) {
					$position = strcspn($encodedKey, '?#');
					if ($position === strlen($encodedKey)) {
						return $match[0];
					}
					$prefix = substr($encodedKey, 0, $position + 1);
					$encodedKey = substr($encodedKey, $position + 1);
				}
				$key = $encodedKey;
				$value = $match[3];
				for ($i = 0; $i < 8; $i++) {
					// a plus stands for a space only in the query itself, not where it was decoded from %2B
					$decodedKey = $i ? rawurldecode($key) : urldecode($key);
					$decodedValue = $i ? rawurldecode($value) : urldecode($value);
					if ($decodedKey === $key && $decodedValue === $value) {
						break;
					}
					[$key, $value] = [$decodedKey, $decodedValue];
				}
				if ($match[3] === '' || $match[3][0] === '{' || $match[3][0] === '[') {
					$uncertain = $uncertain || ($key !== $encodedKey && $this->isMaskedColumn(null, $key));
					return $match[0];
				}
				if ($this->isMaskedColumn(null, $key) || strpos($key, '%') !== false || strpos($value, '%') !== false) {
					return $prefix . $encodedKey . '=' . self::Mask;
				}
				// as text: split into pairs again, a value like a=a=a=… would be taken apart level by level, each a copy
				$masked = $this->maskString($this->maskDocuments($value, $depth + 1), $depth + 1, false);
				// the masking of a value, e.g. one that is no UTF-8, leaves an error of PCRE that the call around keeps reporting
				preg_match('~~', '');
				return $prefix
					. $encodedKey
					. '='
					. ($masked === $value ? $match[3] : ($value === $match[3] ? $masked : rawurlencode($masked)));
			},
			$text,
		);
		if ($uncertain || preg_last_error() !== PREG_NO_ERROR) {
			return self::Mask;
		}
		$text = (string) preg_replace(array_keys(self::SecretShapes), array_values(self::SecretShapes), (string) $replaced);
		if (preg_last_error() !== PREG_NO_ERROR) {
			return self::Mask;
		}
		$text = $this->maskDumps($text);
		$text = $this->maskAssignments($text);
		$text = $this->maskCalls($text);
		$text = $this->maskShapes($text);
		$text = (string) preg_replace(self::CredentialsBeforeHost, '$1:' . self::Mask . '@', $text);
		if (!$structured) {
			return $text;
		}

		// data written with escapes or Unicode spaces is recognized in its plain form, shown only when it hides something;
		// decoded entities must not invent markup; JSON documents still need their field/value relationships checked
		$plain = self::unescape($text);
		if ($plain === null) {
			return self::Mask;
		}

		// A complete JSON document was checked field by field above; unescaping its quotes can destroy its structure.
		$document = preg_match('~^[ \\t\\r\\n]*[{\\[]~', $text) ? json_decode($text) : null;
		$decoded = is_array($document) || $document instanceof \stdClass
			? $plain
			: $this->maskDocuments($plain, $depth + 1);
		$masked = $plain === $text ? $text : $this->maskString($decoded, $depth + 1, false);
		return $masked === $plain ? $text : $masked;
	}


	/**
	 * The plain form of a text: percent-encoding, quoted-printable, base64, HTML entities and JSON escapes decoded,
	 * Unicode spaces as plain ones. Null when it keeps changing after several rounds, which only a crafted text does.
	 */
	private static function unescape(string $text): ?string
	{
		for ($round = 0; $round < 8; $round++) {
			$decoded = rawurldecode($text);
			$decoded = preg_match('~=(?:[\dA-F]{2}|\r?\n)~', $decoded) ? quoted_printable_decode($decoded) : $decoded; // e.g. of a mail
			// base64 wrapped into lines, as in a mail, as a whole, then single tokens, also of a path or after =
			$decoded = (string) preg_replace_callback(
				// only from the start of a run, with nothing that can fail at the end, as each retry would read the block again
				'~(?<![A-Za-z\d+/=])(?:[A-Za-z\d+/]{16,}+\r?\n)++[A-Za-z\d+/]*+={0,2}~',
				fn(array $m): string => self::decodeBase64(str_replace(["\r", "\n"], '', $m[0])) ?? $m[0],
				$decoded,
			);
			$decoded = (string) preg_replace_callback(
				'~(?<![\w+])[\w+/-]{16,}+={0,2}(?![\w+])~',
				fn(array $m): string => self::decodeBase64($m[0]) ?? (string) preg_replace_callback(
					'~[\w+-]{16,}+={0,2}~',
					fn(array $part): string => self::decodeBase64($part[0]) ?? $part[0],
					$m[0],
				),
				$decoded,
			);
			// bytes decoded from a legacy encoding would make the whole text look binary
			$decoded = preg_match('~~u', $decoded) ? $decoded : (string) preg_replace('~[\x80-\xFF]~', '?', $decoded);
			$decoded = (string) preg_replace('~&(#\d++|#x[\da-f]++|nbsp)(?!;)~i', '&$1;', $decoded); // as browsers read them without ;
			$decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$decoded = str_replace(['\/', '\"', '\\\\'], ['/', '"', '\\'], $decoded); // before \u, which may follow an escaped \
			$decoded = (string) preg_replace_callback(
				'~\\\\u([\da-fA-F]{4})~',
				fn(array $m): string => is_string($char = json_decode('"\u' . $m[1] . '"')) ? $char : '?', // a lone surrogate is no character
				$decoded,
			);
			$decoded = (string) preg_replace('~[\x{A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]~u', ' ', $decoded);
			if ($decoded === $text) {
				return $text;
			}

			$text = $decoded;
		}

		return null;
	}


	/**
	 * Text in standard or URL-safe base64; null for anything else, which letters and digits that are no base64
	 * rarely decode to.
	 */
	private static function decodeBase64(string $token): ?string
	{
		$bytes = base64_decode(strtr($token, '-_', '+/'), true);
		return is_string($bytes) && preg_match('~^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$~u', $bytes) ? $bytes : null;
	}


	private function maskDocuments(string $text, int $depth): string
	{
		$whole = preg_match('~^[ \\t\\r\\n]*[{\\[]~', $text) ? $this->maskDocumentText($text, $depth) : null;
		if ($whole !== null) {
			return $whole;
		}

		// a text that begins like a JSON document begins with a whole one, or it is a document cut short or broken and hidden;
		// a text that only begins with a bracket, e.g. [2026-11-12] in a log, is text
		$leading = preg_match(self::JsonStart, $text) ? strspn($text, " \t\r\n") : null;
		$result = '';
		$offset = 0;
		$length = strlen($text);
		$budget = 4 * $length;
		for ($start = 0; $start < $length; $start++) {
			if (!preg_match('~\\G(?:\\{\\s*(?:"|\\})|\\[\\s*(?:["{\\[0-9-]|true|false|null))~', $text, $unused, 0, $start)) {
				continue;
			}
			$stack = [];
			$quoted = $mismatched = false;
			for ($end = $start; $end < $length; $end++) {
				// Failed balanced documents may contain valid descendants, but must share one scan budget.
				if (--$budget < 0) {
					return self::Mask;
				}

				$char = $text[$end];
				if ($quoted && $char === '\\') {
					$end++;
				} elseif ($char === '"') {
					$quoted = !$quoted;
				} elseif (!$quoted && ($char === '{' || $char === '[')) {
					$stack[] = $char === '{' ? '}' : ']';
				} elseif (!$quoted && ($char === '}' || $char === ']')) {
					$mismatched = array_pop($stack) !== $char;
					if ($mismatched || !$stack) {
						break;
					}
				}
			}
			// brackets in text that is no JSON, e.g. SQLSTATE[42S22], stay text, which the rest of the masking covers;
			// after unbalanced ones, so does the rest, as searching on from each bracket could take quadratic time
			if ($stack || $quoted || $mismatched) {
				if ($start === $leading) {
					return self::Mask;
				}

				break;

			} elseif (($document = $this->maskDocumentText(substr($text, $start, $end - $start + 1), $depth)) === null) {
				if ($start === $leading) {
					return self::Mask;
				}

				continue;
			}
			$result .= substr($text, $offset, $start - $offset) . $document;
			$offset = $end + 1;
			$start = $end;
		}
		return $result . substr($text, $offset);
	}


	/**
	 * A JSON document with its values masked by its structure, written as it was when nothing is hidden; null for no JSON.
	 */
	private function maskDocumentText(string $text, int $depth): ?string
	{
		$value = json_decode($text, false, self::MaxDepth, JSON_BIGINT_AS_STRING);
		if (json_last_error() !== JSON_ERROR_NONE || (!is_array($value) && !$value instanceof \stdClass)) {
			return null;
		}
		try {
			$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
			$masked = json_encode($this->maskValue($value, $depth + 1, true), $flags);
			return $masked === json_encode($value, $flags) ? $text : $masked;
		} catch (\JsonException $e) {
			return self::Mask;
		}
	}


	private function maskShapes(string $text): string
	{
		foreach ($this->detectors as $detector) {
			$text = Scalar::toString($this->{'mask' . ucfirst($detector)}($text));
			if (preg_last_error() !== PREG_NO_ERROR) {
				return self::Mask;
			}
		}

		return $text;
	}


	/**
	 * Masks the value assigned to a secret key. The search goes on right after a key that is not secret,
	 * so an assignment inside its value is found too, e.g. the token in http://host/reset?token=x.
	 */
	private function maskAssignments(string $text): string
	{
		// an unquoted value is read only so far; read on to its end for every key, a text like a:a:a:… took quadratic time
		$pattern = '~(?<![\w$])(?<![\w$]-)(\$?)([\'"]?)([a-z_][\w-]*)\2(\s*(?:=>|:|=)\s*)('
			. '[{\\[]|' . self::QuotedString
			. '|(?![{\[(])[^\s,;)\]}\'"]{1,256}+)~i';
		$result = '';
		$offset = 0;
		while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
			[$key, $keyStart] = $m[3];
			[$value, $valueStart] = $m[5];
			$variable = $m[1][0] !== '' || substr($text, max(0, $m[0][1] - 2), 2) === '->'; // $password, $this->password
			$variable = $variable && $value[0] !== '[' && $value[0] !== '{';
			$inQuery = $keyStart > 0 && strpos('?&#;', $text[$keyStart - 1]) !== false;
			$secret = $this->isMaskedColumn(null, $key)
				&& $value[0] !== '/' // a URL scheme like https:// is no assignment
				&& !preg_match('~^(Bearer|Basic|([\'"]?)\*{3}\2)$~i', $value) // already masked by its shape
				&& !($inQuery && strncmp($value, self::Mask . '&', 4) === 0) // a parameter of a query masked before, the next one follows
				&& (!$variable || strpos('\'"0123456789', $value[0]) !== false); // a PHP variable only when assigned a literal
			$end = $secret ? $valueStart + strlen($value) : $keyStart + strlen($key);
			if ($secret && strpos('\'"{[', $value[0]) === false) {
				$end = $valueStart + strcspn($text, " \t\n\v\f\r,;)]}'\"", $valueStart); // the whole unquoted value
			} elseif ($secret && ($value[0] === '{' || $value[0] === '[')) {
				$end = self::findContainerEnd($text, $valueStart);
				if ($end === null) {
					return self::Mask;
				}
			}
			$result .= substr($text, $offset, ($secret ? $valueStart : $end) - $offset) . ($secret ? self::Mask : '');
			$offset = $end;
		}

		return preg_last_error() === PREG_NO_ERROR ? $result . substr($text, $offset) : self::Mask;
	}


	private static function findContainerEnd(string $text, int $start): ?int
	{
		$stack = [];
		$quote = null;
		for ($i = $start; $i < strlen($text); $i++) {
			$char = $text[$i];
			if ($quote !== null) {
				if ($char === '\\') {
					$i++;
				} elseif ($char === $quote) {
					$quote = null;
				}
			} elseif ($char === '"' || $char === "'") {
				$quote = $char;
			} elseif ($char === '{' || $char === '[') {
				$stack[] = $char === '{' ? '}' : ']';
			} elseif ($char === '}' || $char === ']') {
				if (array_pop($stack) !== $char) {
					return null;
				} elseif (!$stack) {
					return $i + 1;
				}
			}
		}
		return null;
	}


	/**
	 * Masks secrets passed without an assignment: --password x on a command line, define('DB_PASSWORD', 'x'),
	 * a literal given to a setter such as setPassword('x'), a key of two words such as api key: x,
	 * and a sentence such as "password was changed to x" with a value that has a digit.
	 */
	private function maskCalls(string $text): string
	{
		$patterns = [
			'~(?<![\w-])(--([a-z][\w-]*)[ \t]+)(?![-\s])(?:' . self::QuotedString . '|[^\s\'"]+)~i',
			'~\b(define\(\s*[\'"](\w+)[\'"]\s*,\s*)(?:' . self::QuotedString . '|[^\s,)\'"]+)~i',
			'~\b((set\w+)\(\s*)(?:' . self::QuotedString . ')~i',
			'~\b(((?:api|access|auth|secret|private|refresh|client)\s++(?:key|token|secret))\s*+[:=]\s*+)(?:'
			. self::QuotedString . '|[^\s,;]+)~i',
			// a value with a letter and a digit, as a date or a number is no password
			'~\b(((?:pass(?:word)?|passwd|heslo|token|secret))\s++(?:(?:is|was|has\s++been|je|bylo|byl)\s++)?'
			. '(?:(?:set|changed|reset|nastaven[oa]?|změněn[oa]?)\s++)?(?:(?:to|na)\s++)?)(?=\S*\d)(?=\S*\pL)[^\s,;]{6,}~iu',
			// print_r() or var_dump() on one line, or after a prefix such as a time of a log
			'~(\[["\']?([\w .-]+?)["\']?(?::[^\]\s]*)?\][ \t]*=>[ \t]*(?:string\(\d+\)[ \t]*)?)(?!\*{3})'
			. '(?:"(?:[^"\\\\]++|\\\\.)*+"|[^\s\[\]()]+)~',
		];
		return (string) preg_replace_callback(
			$patterns,
			fn(array $m) => $this->isSecretKey((string) preg_replace('~\s+~', ' ', $m[2])) ? $m[1] . self::Mask : $m[0],
			$text,
		);
	}


	/**
	 * Hides the values under secret keys in the output of print_r() and var_dump(), [password:protected] => x,
	 * ["token"]=> string(1) "x" or 'token' => of Xdebug: a nested array or object as a whole, a string also over
	 * several lines. Each line is passed once, the value lines are skipped.
	 */
	private function maskDumps(string $text): string
	{
		if (strpos($text, '=>') === false) {
			return $text;
		}

		$lines = explode("\n", $text);
		$count = count($lines);
		$result = [];
		for ($i = 0; $i < $count; $i = $end) {
			$end = $i + 1; // the first line after the value
			if (!preg_match(
				'~^([ \t]*)(?:\[(["\']?)([^\]"\'\r\n]+?)\2(?::[^\]\r\n]*)?\]|\'([^\'\r\n]+)\')[ \t]*=>[ \t]*(.*?)\r?$~',
				$lines[$i],
				$m,
			)
				|| !$this->isSecretKey($m[3] !== '' ? $m[3] : $m[4])
				|| ($m[4] !== '' && $m[5] !== '') // Xdebug writes the value on the next line, else it is code like 'key' => 'x'
			) {
				$result[] = $lines[$i];
				continue;
			}

			$indent = strlen($m[1]);
			if ($m[5] !== '') { // print_r(): a nested block ends with a line less indented, a string with the next key
				$nested = preg_match('~^(?:Array|\S+ Object)$~', $m[5]);
				while ($end < $count && !($nested
					? trim($lines[$end]) !== '' && strspn($lines[$end], " \t") <= $indent
					: preg_match('~^[ \t]*(?:\[[^\]\r\n]*\][ \t]*=>|\)[ \t]*\r?$)~', $lines[$end]))
				) {
					$end++;
				}

				$result[] = (string) preg_replace('~=>[ \t]*\K.*?(\r?)$~', self::Mask . '$1', $lines[$i], 1);
				continue;
			}

			$result[] = $lines[$i];
			if ($end >= $count) {
				continue;
			}

			// var_dump(): the value on the next line, a string of its declared length or a block up to its brace
			$valueIndent = strspn($lines[$end], " \t");
			if (preg_match('~^[ \t]*string\((\d+)\) "~', $lines[$end], $string)) {
				$left = (int) $string[1] + 1 - (strlen($lines[$end]) - strlen($string[0])); // with the closing quote
				while ($left > 0 && $end + 1 < $count) {
					$end++;
					$left -= strlen($lines[$end]) + 1; // and the line break before it
				}
			} elseif (preg_match('~\{\r?$~', $lines[$end])) {
				do {
					$end++;
				} while ($end < $count && (trim($lines[$end]) === '' || strspn($lines[$end], " \t") > $valueIndent));
			}

			$result[] = str_repeat(' ', $valueIndent) . self::Mask;
			$end++;
		}

		return implode("\n", $result);
	}


	private function maskEmail(string $text): string
	{
		return (string) preg_replace('~\b([\w%+-])[\w.%+-]*@[\w-]+(?:\.[\w-]+)*(\.[a-z]{2,})\b~iu', '$1***@***$2', $text);
	}


	private function maskPhone(string $text): string
	{
		$pattern = '~(?<![\w/+.,-])(?:(?:\+|00)\d{1,3}[ -]?\d{3}[ -]?\d{3}[ -]?\d{3,4}|\d{3}[ -]\d{3}[ -]\d{3}|(?:42[01])?[67]\d{8})(?![\w/,-]|\.\d)~';
		return (string) preg_replace_callback($pattern, fn(array $m) => self::keepLastDigits($m[0], 3), $text);
	}


	private function maskBirthNumber(string $text): string
	{
		return (string) preg_replace_callback('~(?<![\w/.,-])(\d{6})/?(\d{3,4})(?![\w/,-]|\.\d)~', function (array $m): string {
			$digits = $m[1] . $m[2];
			$month = (int) substr($digits, 2, 2) % 50 % 20;
			$day = (int) substr($digits, 4, 2);
			$valid = $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31
				&& (strlen($digits) === 9 || (int) substr($digits, 0, 9) % 11 % 10 === (int) $digits[9]);
			return $valid && (strpos($m[0], '/') !== false || strlen($digits) === 10)
				? '******/****'
				: $m[0];
		}, $text);
	}


	private function maskIban(string $text): string
	{
		$text = (string) preg_replace_callback(
			'~\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b~',
			fn(array $m) => substr($m[0], 0, 2) . self::keepLastDigits(substr($m[0], 2), 3),
			$text,
		);
		return (string) preg_replace_callback( // domestic account number, prefix-number/bank
			'~(?<![\w/.,-])(?:\d{1,6}-)?\d{2,10}/\d{4}(?![\w/,-]|\.\d)~',
			fn(array $m) => self::keepLastDigits(explode('/', $m[0])[0], 2) . '/' . explode('/', $m[0])[1],
			$text,
		);
	}


	private function maskCard(string $text): string
	{
		return (string) preg_replace_callback('~(?<![\w.,-])\d(?:[ -]?\d){12,18}(?![\w,-]|\.\d)~', function (array $m): string {
			$digits = (string) preg_replace('~\D~', '', $m[0]);
			// a date and time in 14 digits, such as the version of a migration, is no card: those of 14 digits begin with 3
			$dateTime = '~^(19[89]\d|20\d\d)(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])([01]\d|2[0-3])[0-5]\d[0-5]\d$~D';
			return self::isLuhnValid($digits) && !preg_match($dateTime, $m[0]) ? self::keepLastDigits($m[0], 4) : $m[0];
		}, $text);
	}


	private static function keepLastDigits(string $value, int $count): string
	{
		$total = preg_match_all('~\d~', $value);
		$index = 0;
		return (string) preg_replace_callback('~\d~', function (array $m) use (&$index, $total, $count): string {
			return ++$index > $total - $count ? $m[0] : '*';
		}, $value);
	}


	private static function isLuhnValid(string $digits): bool
	{
		$sum = 0;
		foreach (array_reverse(str_split($digits)) as $i => $digit) {
			$value = (int) $digit * ($i % 2 ? 2 : 1);
			$sum += $value > 9 ? $value - 9 : $value;
		}

		return $sum % 10 === 0;
	}
}
