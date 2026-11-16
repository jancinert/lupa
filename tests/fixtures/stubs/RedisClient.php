<?php declare(strict_types=1);

namespace Kdyby\Redis;


/**
 * Constructor of the Kdyby Redis client, enough for the guard to read where a compiled container points it.
 */
class RedisClient
{
	/**
	 * @param ?int $port
	 */
	public function __construct(string $host = '127.0.0.1', $port = null, int $database = 0)
	{
	}
}
