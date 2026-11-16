<?php declare(strict_types=1);

namespace Lupa\Nette;

use Nette\DI\Container;
use Nette\Security\Permission;


/**
 * Supplies the access control list for the ACL tools. A project whose Permission is built outside the container,
 * e.g. in a presenter from database tables, registers an implementation as a service in lupa.neon.
 */
interface PermissionProvider
{
	function getPermission(Container $container): Permission;
}
