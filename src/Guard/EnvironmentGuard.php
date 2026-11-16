<?php declare(strict_types=1);

namespace Lupa\Guard;

use Lupa\GuardException;


/**
 * Refuses to work with an application whose database connections leave the development environment.
 */
final class EnvironmentGuard
{
	/** @var list<string> */
	private array $allowedHosts;

	/** @var list<string> */
	private array $deniedDatabases;


	/**
	 * @param list<string> $allowedHosts  host names or fnmatch() patterns
	 * @param list<string> $deniedDatabases  database names or fnmatch() patterns
	 */
	public function __construct(array $allowedHosts, array $deniedDatabases)
	{
		$this->allowedHosts = $allowedHosts;
		$this->deniedDatabases = $deniedDatabases;
	}


	/**
	 * @param list<DatabaseTarget> $targets
	 */
	public function check(array $targets): void
	{
		$violations = array_filter(array_map([$this, 'findViolation'], $targets));
		if ($violations) {
			throw new GuardException(
				"The application is not connected to a development environment:\n- " . implode("\n- ", $violations)
				. "\nChange guard.allowedHosts or guard.deniedDatabases only if the target really is a development environment.",
			);
		}
	}


	public function findViolation(DatabaseTarget $target): ?string
	{
		$service = "service '$target->service' ($target->type)";
		if (!$target->resolved) {
			return "cannot determine where $service connects";
		} elseif (!$target->local && ($target->host === null || !self::matchesAny($target->host, $this->allowedHosts))) {
			return "$service connects to host '$target->host', which is not in guard.allowedHosts";
		} elseif ($target->database !== null && ($pattern = self::findMatch($target->database, $this->deniedDatabases)) !== null) {
			return "$service uses database '$target->database', denied by guard.deniedDatabases pattern '$pattern'";
		}

		return null;
	}


	/**
	 * @param list<string> $patterns
	 */
	private static function matchesAny(string $value, array $patterns): bool
	{
		return self::findMatch($value, $patterns) !== null;
	}


	/**
	 * @param list<string> $patterns
	 */
	private static function findMatch(string $value, array $patterns): ?string
	{
		foreach ($patterns as $pattern) {
			if (fnmatch(strtolower($pattern), strtolower($value), FNM_NOESCAPE)) {
				return $pattern;
			}
		}

		return null;
	}
}
