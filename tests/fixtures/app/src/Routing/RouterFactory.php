<?php declare(strict_types=1);

namespace App\Routing;

use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	public static function create(): RouteList
	{
		$router = new RouteList;
		$router->addRoute('index.php', 'Order:default', 1);
		$router->withModule('Admin')
			->addRoute('admin/<presenter>/<action>[/<id>]', 'Dashboard:default');
		$router->addRoute('order/<id \d+>', 'Order:detail');
		$router->addRoute('<presenter>/<action>', 'Order:default');
		$router->withPath('shop/')
			->addRoute('item/<id \d+>', 'Order:detail');
		return $router;
	}
}
