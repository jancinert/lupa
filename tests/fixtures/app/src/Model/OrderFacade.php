<?php declare(strict_types=1);

namespace App\Model;

use Dibi\Connection;
use Dibi\Row;


final class OrderFacade
{
	private Connection $database;


	public function __construct(Connection $database)
	{
		$this->database = $database;
	}


	/**
	 * @return list<Row>
	 */
	public function findByCustomerEmail(string $email): array
	{
		return $this->database->query('SELECT o.* FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = ?', $email)
			->fetchAll();
	}
}
