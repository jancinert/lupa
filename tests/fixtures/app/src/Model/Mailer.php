<?php declare(strict_types=1);

namespace App\Model;


final class Mailer
{
	/** @var array<string, string> */
	private array $config;

	private ?OrderFacade $orders = null;


	/**
	 * @param array<string, string> $config
	 */
	public function __construct(array $config)
	{
		$this->config = $config;
	}


	public function injectOrders(OrderFacade $orders): void
	{
		$this->orders = $orders;
	}
}
