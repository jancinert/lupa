<?php declare(strict_types=1);

namespace Lupa\Nette;

use Lupa\Masking;


/**
 * Hides secrets in the code of a generated service factory. Besides values under secret keys and personal data,
 * string literals passed to a constructor or a method of the service are hidden when the parameter they fill
 * has a secret name, e.g. the password of new Nette\Database\Connection($dsn, $user, $password).
 * nette/di 3.2 marks such values with sensitive comments, which are honoured too.
 */
final class FactoryCodeMasker
{
	private Masking $masking;


	public function __construct(Masking $masking)
	{
		$this->masking = $masking;
	}


	/**
	 * @param ?string $serviceType  class of $service in the code, for its method calls
	 */
	public function mask(string $code, ?string $serviceType): string
	{
		$code = (string) preg_replace('~/\*sensitive\{\*/.*?/\*\}\*/~s', "'" . Masking::Mask . "'", $code);
		$tokens = token_get_all('<?php ' . $code);
		array_shift($tokens);
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$callee = $this->findCallee($tokens, $i, $serviceType);
			if ($callee !== null) {
				$this->maskArguments($tokens, $i, $callee); // the search goes on inside the arguments, for a nested new
			}
		}

		$result = '';
		foreach ($tokens as $token) {
			$result .= is_array($token) ? $token[1] : $token;
		}

		return $this->masking->maskText($result);
	}


	/**
	 * At "new Class(" or "$service->method(", the reflection of the called function; $i moves to the "(".
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private function findCallee(array $tokens, int &$i, ?string $serviceType): ?\ReflectionFunctionAbstract
	{
		if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW) {
			$j = self::skipWhitespace($tokens, $i + 1);
			$class = '';
			while (isset($tokens[$j]) && is_array($tokens[$j]) && self::isNamePart($tokens[$j][0])) {
				$class .= $tokens[$j++][1];
			}

			$class = ltrim($class, '\\');
			if ($class === '' || ($tokens[$j] ?? null) !== '(' || !class_exists($class)) {
				return null;
			}

			$i = $j;
			return (new \ReflectionClass($class))->getConstructor();

		} elseif ($serviceType !== null && is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE && $tokens[$i][1] === '$service'
			&& is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_OBJECT_OPERATOR
			&& is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING && ($tokens[$i + 3] ?? null) === '('
			&& method_exists($serviceType, $tokens[$i + 2][1])
		) {
			$method = new \ReflectionMethod($serviceType, $tokens[$i + 2][1]);
			$i += 3;
			return $method;
		}

		return null;
	}


	/**
	 * Hides complete expressions passed to sensitive parameters; returns the index of the call's ")".
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private function maskArguments(array &$tokens, int $i, \ReflectionFunctionAbstract $callee): int
	{
		$parameters = $callee->getParameters();
		$lastParameter = $parameters ? $parameters[count($parameters) - 1] : null;
		$depth = 0;
		$position = 0;
		$name = null; // of a named argument
		$argumentStart = $i + 1;
		for ($j = $i; $j < count($tokens); $j++) {
			$token = $tokens[$j];
			$text = is_array($token) ? $token[1] : $token;
			if ((!is_array($token) && in_array($text, ['(', '[', '{'], true))
				|| (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
			) {
				$depth++;
				if ($depth === 1) {
					continue;
				}
			} elseif (!is_array($token) && in_array($text, [')', ']', '}'], true)) {
				$depth--;
			}

			if ($depth === 0 || ($depth === 1 && !is_array($token) && $text === ',')) {
				$parameter = $name ?? (isset($parameters[$position]) ? $parameters[$position]->getName() : null);
				if ($parameter === null && $lastParameter !== null && $lastParameter->isVariadic()) {
					$parameter = $lastParameter->getName();
				}
				if ($parameter !== null && $this->masking->isSecretKey($parameter) && $j > $argumentStart) {
					for ($k = $argumentStart; $k < $j; $k++) {
						$tokens[$k] = '';
					}
					$tokens[$argumentStart] = [T_CONSTANT_ENCAPSED_STRING, "'" . Masking::Mask . "'", 0];
				}

				if ($depth === 0) {
					return $j;
				}

				[$position, $name] = [$position + 1, null];
				$argumentStart = $j + 1;

			} elseif ($depth === 1 && is_array($token) && $token[0] === T_STRING && self::nextIs($tokens, $j, ':')) {
				$name = $token[1];
				$j = self::skipWhitespace($tokens, $j + 1); // the colon
				$argumentStart = $j + 1;
			}
		}

		return $j;
	}


	private static function isNamePart(int $type): bool
	{
		return $type === T_STRING || $type === T_NS_SEPARATOR
			|| (defined('T_NAME_QUALIFIED') && in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true));
	}


	/**
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private static function skipWhitespace(array $tokens, int $i): int
	{
		while (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
			$i++;
		}

		return $i;
	}


	/**
	 * @param array<int, array{int, string, int}|string> $tokens
	 */
	private static function nextIs(array $tokens, int $i, string $text): bool
	{
		$next = self::skipWhitespace($tokens, $i + 1);
		return ($tokens[$next] ?? null) === $text;
	}
}
