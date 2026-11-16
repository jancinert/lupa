<?php declare(strict_types=1);

namespace Lupa\Guard;

use Lupa\Scalar;


/**
 * Where a database connection service of the application points, without credentials.
 */
final class DatabaseTarget
{
	public const
		SourceObject = 'object',
		SourceCompiled = 'compiled';

	public string $service;

	/** Class of the connection service. */
	public string $type;

	public ?string $driver = null;

	/** Null for a local socket or file database, or when it could not be determined. */
	public ?string $host = null;

	public ?int $port = null;

	public ?string $database = null;

	/** SQLite file or Unix socket: never leaves the machine. */
	public bool $local = false;

	/** False when the target could not be read from the service; the guard then refuses it. */
	public bool $resolved = true;

	/** Read from the created service object, or from the code of the compiled container. */
	public string $source;


	public function __construct(string $service, string $type, string $source)
	{
		$this->service = $service;
		$this->type = $type;
		$this->source = $source;
	}


	/**
	 * Fills the target from a PDO-style DSN, e.g. 'mysql:host=db;port=3306;dbname=app'.
	 */
	public function applyDsn(string $dsn, bool $pdoSyntax = true): void
	{
		[$driver, $rest] = array_pad(explode(':', $dsn, 2), 2, '');
		$this->driver ??= $driver;
		if (in_array($driver, ['sqlite', 'sqlite2', 'sqlite3'], true)) {
			$this->local = true;
			$this->database = $rest;
			return;
		}

		if (strpos($rest, "\0") !== false || !in_array($driver, ['mysql', 'pgsql'], true)) {
			$this->resolved = false;
			return;
		}

		$options = [];
		if ($driver === 'mysql') {
			// PDO MySQL treats doubled semicolons as part of a value
			foreach (preg_split('~(?<!;)(?:;;)*\K;(?!;)~', $rest) ?: [] as $pair) {
				if ($pair === '') {
					continue;
				} elseif (!preg_match('~^([a-z_]+)=(.*)$~sD', ltrim($pair), $match) || isset($options[$match[1]])) {
					$this->resolved = false;
					return;
				}

				$options[$match[1]] = str_replace(';;', ';', $match[2]);
			}
		} else {
			// libpq accepts space-separated options and single-quoted, backslash-escaped values
			$pattern = "~\\G\\s*([a-z_]+)\\s*=\\s*('(?:[^'\\\\]|\\\\.)*'|(?:[^\\\\\\s']|\\\\.)+)(?=\\s|$)~s";
			$rest = trim($pdoSyntax ? str_replace(';', ' ', $rest) : $rest);
			for ($offset = 0; $offset < strlen($rest); $offset += strlen($match[0])) {
				if (!preg_match($pattern, $rest, $match, 0, $offset) || isset($options[$match[1]])) {
					$this->resolved = false;
					return;
				}

				$value = $match[2];
				$options[$match[1]] = preg_replace('~\\\\(.)~s', '$1', $value[0] === "'" ? substr($value, 1, -1) : $value);
			}
		}

		$host = $options['host'] ?? '';
		if ($driver === 'pgsql') {
			$address = $options['hostaddr'] ?? (getenv('PGHOSTADDR') ?: '');
			$host = $address === '' ? $host : $address;
			if (isset($options['service']) || getenv('PGSERVICE') || strpos($host, ',') !== false) {
				$this->resolved = false;
				return;
			}
		} elseif ($host === '' && !empty($options['unix_socket'])) {
			$this->local = true;
		}

		if ($host !== '') {
			if (strncasecmp(ltrim($host), 'p:', 2) === 0) {
				$this->resolved = false;
				return;
			}

			$this->setHost($host);
		}

		$this->database = $options['dbname'] ?? ($driver === 'pgsql' ? (getenv('PGDATABASE') ?: null) : null);
		if (Scalar::isDigits($options['port'] ?? '')) {
			$this->port = (int) $options['port'];
		}
	}


	/**
	 * Accepts host names with a port ('db:3306'), IPv6 in brackets and the mysqli persistent prefix ('p:db').
	 */
	public function setHost(string $host): void
	{
		$host = trim($host);
		if (strncasecmp($host, 'p:', 2) === 0) {
			$host = substr($host, 2);
		}

		if (preg_match('~^\[([^\]]+)\](?::(\d+))?$~', $host, $m)) {
			$host = $m[1];
			$port = $m[2] ?? '';
		} elseif (substr_count($host, ':') === 1) {
			[$host, $port] = explode(':', $host);
		}

		$this->host = strtolower($host);
		if (isset($port) && Scalar::isDigits($port)) {
			$this->port = (int) $port;
		}
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'service' => $this->service,
			'type' => $this->type,
			'driver' => $this->driver,
			'host' => $this->host,
			'port' => $this->port,
			'database' => $this->database,
			'local' => $this->local,
			'resolved' => $this->resolved,
			'source' => $this->source,
		];
	}


	/**
	 * Fills the target from the configuration of a Dibi connection.
	 * @param array<mixed> $config
	 */
	public function applyDibiConfig(array $config): void
	{
		$driver = $config['driver'] ?? 'mysqli';
		$this->driver = is_object($driver) ? get_class($driver) : Scalar::toString($driver);
		$this->database = self::getString($config, 'database') ?? self::getString($config, 'dbname') ?? self::getString($config, 'file');
		$this->port = is_int($config['port'] ?? null) ? $config['port'] : null;

		$host = self::getString($config, 'host') ?? self::getString($config, 'hostname') ?? self::getString($config, 'server');
		$dsn = self::getString($config, 'dsn');
		$string = self::getString($config, 'string');

		if (isset($config['resource'])) {
			$this->resolved = false; // an existing connection object, whose target the configuration does not show
		} elseif (stripos($this->driver, 'sqlite') !== false) {
			$this->local = true;
		} elseif ($dsn !== null && stripos($this->driver, 'pdo') !== false) { // other drivers ignore a dsn key
			$this->applyDsn($dsn);
		} elseif (in_array(strtolower($this->driver), ['postgre', 'dibi\drivers\postgredriver'], true)) {
			if ($string === null) {
				$config += ['dbname' => $config['database'] ?? null, 'user' => $config['username'] ?? null];
				$options = [];
				foreach ([
					'host', 'hostaddr', 'port', 'dbname', 'user', 'password', 'connect_timeout', 'options', 'sslmode', 'service',
				] as $key) {
					if (isset($config[$key])) {
						$options[] = $key . '=' . Scalar::toString($config[$key]);
					}
				}

				$string = implode(' ', $options);
			}

			$this->applyDsn('pgsql:' . $string, false);

		} elseif ($host !== null) {
			$this->setHost($host);
		} elseif (stripos($this->driver, 'mysql') !== false) {
			$this->setHost(ini_get('mysqli.default_host') ?: 'localhost');
		} else {
			$this->resolved = false;
		}
	}


	/**
	 * @param array<mixed> $config
	 */
	private static function getString(array $config, string $key): ?string
	{
		return isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '' ? $config[$key] : null;
	}
}
