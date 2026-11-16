<?php declare(strict_types=1);

namespace Lupa\Nette;

use Dibi\Connection;
use Lupa\Guard\DatabaseTarget;
use Nette\DI\Container;


/**
 * Finds where the database connection services of the application point, and the Redis clients of Kdyby.
 *
 * A service that has not been created yet is not created here: creating a connection may connect.
 * Its target is read from the code of the compiled container instead, which holds the configuration
 * as literals. When the code does not reveal it, the target stays unresolved and the guard refuses it.
 */
final class DatabaseTargetFinder
{
	public const
		DibiConnection = 'Dibi\Connection',
		NetteConnection = 'Nette\Database\Connection',
		Pdo = 'PDO',
		RedisClient = 'Kdyby\Redis\RedisClient';

	private const DibiTargetKeys = [
		'host', 'hostname', 'server', 'hostaddr', 'database', 'dbname', 'dsn', 'driver', 'string', 'file', 'port', 'resource', 'service',
	];

	/** Single-quoted PHP string literal; group 1 is its escaped content. */
	private const StringLiteral = "'((?:[^'\\\\]|\\\\.)*)'";

	/** After a literal argument: the end of the argument, so that 'prefix' . $computed is not taken for the whole value. */
	private const ArgumentEnd = '(?=\s*[,)])';


	/**
	 * @return list<DatabaseTarget>
	 */
	public function find(Container $container): array
	{
		$reader = new ContainerReader($container);
		$targets = [];
		foreach ([self::DibiConnection, self::NetteConnection, self::Pdo, self::RedisClient] as $type) {
			$names = $reader->findByType($type);
			foreach ($names as $name) {
				if ($container->isCreated($name)) {
					$targets[] = $this->readObject($name, $type, $container->getService($name));
				} elseif (!in_array(self::getForwardedService($reader, $name), $names, true)) {
					$targets[] = $this->readCompiledCode($name, $type, $reader);
				} // a service that only returns another one of the list is checked as that one
			}
		}

		return $targets;
	}


	/**
	 * Name of the service the factory merely returns, e.g. a default client that is another client.
	 */
	private static function getForwardedService(ContainerReader $reader, string $name): ?string
	{
		$code = $reader->getFactoryCode($name)['code'] ?? '';
		return preg_match("~\\{\\s*return \\\$this->getService\\('([^']+)'\\);\\s*\\}\\s*$~", $code, $m) ? $m[1] : null;
	}


	private function readObject(string $name, string $type, object $service): DatabaseTarget
	{
		$target = new DatabaseTarget($name, $type, DatabaseTarget::SourceObject);
		if ($service instanceof Connection) {
			$target->applyDibiConfig((array) $service->getConfig());
		} elseif ($service instanceof \Nette\Database\Connection) {
			$target->applyDsn($service->getDsn());
		} else {
			$target->resolved = false;
		}

		return $target;
	}


	private function readCompiledCode(string $name, string $type, ContainerReader $reader): DatabaseTarget
	{
		$target = new DatabaseTarget($name, $type, DatabaseTarget::SourceCompiled);
		$code = $reader->getFactoryCode($name)['code'] ?? null;

		if ($code === null) {
			$target->resolved = false;

		} elseif ($type === self::NetteConnection || $type === self::Pdo) {
			if (preg_match('~new\s+\\\\?[\w\\\\]+\(\s*' . self::StringLiteral . self::ArgumentEnd . '~', $code, $m)) {
				$target->applyDsn(self::unescape($m[1]));
			} else {
				$target->resolved = false;
			}

		} elseif ($type === self::RedisClient) { // new RedisClient($host = '127.0.0.1', $port = 6379, $database = 0, ...)
			$target->driver = 'redis';
			if (preg_match('~new\s+\\\\?[\w\\\\]+(\(\s*\))?\s*;~', $code)) {
				$target->setHost('127.0.0.1');
			} elseif (preg_match(
				'~new\s+\\\\?[\w\\\\]+\(\s*' . self::StringLiteral . self::ArgumentEnd . '(?:\s*,\s*(\d+)(?:\s*,\s*(\d+))?)?~',
				$code,
				$m,
			)) {
				$host = self::unescape($m[1]);
				$target->local = strncmp($host, '/', 1) === 0;
				$target->setHost($target->local ? 'localhost' : $host);
				$target->port = isset($m[2]) ? (int) $m[2] : 6379;
				$target->database = $m[3] ?? '0';
			} else {
				$target->resolved = false;
			}

		} elseif (
			!preg_match('~new\s+\\\\?[\w\\\\]+\(\s*\[~', $code)
			|| preg_match("~'(" . implode('|', self::DibiTargetKeys) . ")'\\s*=>\\s*+(?!['\\d])~", $code)
			|| preg_match(
				"~'(" . implode('|', self::DibiTargetKeys) . ")'\\s*=>\\s*(?:" . self::StringLiteral . '|\d++)\s*+[^\s,\]]~',
				$code,
			)
		) {
			// created by a factory call, or a target key holds a computed value such as 'dev_' . $name
			$target->resolved = false;

		} else {
			preg_match_all("~'(\\w+)'\\s*=>\\s*(?:" . self::StringLiteral . '|(\d+)\b)~', $code, $matches, PREG_SET_ORDER);
			$config = [];
			foreach ($matches as $m) {
				$config[$m[1]] = isset($m[3]) ? (int) $m[3] : self::unescape($m[2] ?? '');
			}

			$target->applyDibiConfig($config);
		}

		return $target;
	}


	private static function unescape(string $literal): string
	{
		return strtr($literal, ["\\'" => "'", '\\\\' => '\\']);
	}
}
