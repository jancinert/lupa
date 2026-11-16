<?php declare(strict_types=1);

namespace App\Security;

use Nette\Security\Permission;


final class AuthorizatorFactory
{
	public static function create(): Permission
	{
		$acl = new Permission;
		$acl->addRole('guest');
		$acl->addRole('editor', 'guest');
		$acl->addRole('admin', 'editor');
		$acl->addResource('orders');
		$acl->addResource('invoices', 'orders');
		$acl->allow('guest', 'orders', 'read');
		$acl->allow('editor', 'orders', 'edit');
		$acl->deny('editor', 'invoices');
		$acl->allow('admin');
		return $acl;
	}
}
