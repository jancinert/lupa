<?php declare(strict_types=1);

namespace App\Routing;

use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;


/**
 * The router as an application on Nette 2.4 builds it.
 */
final class LegacyRouterFactory
{
	public static function create(): RouteList
	{
		$router = new RouteList;
		$router[] = new Route('index.php', 'Order:default', Route::ONE_WAY);
		$router[] = $admin = new RouteList('Admin');
		$admin[] = new Route('admin/<presenter>/<action>[/<id>]', 'Dashboard:default');
		$router[] = new Route('order/<id \d+>', 'Order:detail');
		$router[] = new Route('<presenter>/<action>', 'Order:default');
		return $router;
	}
}
