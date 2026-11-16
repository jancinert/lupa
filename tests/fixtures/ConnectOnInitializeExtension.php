<?php declare(strict_types=1);

namespace Lupa\Tests\Fixtures;

use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;


/**
 * Creates the database service while the container initializes, as a Tracy panel registration does.
 */
final class ConnectOnInitializeExtension extends CompilerExtension
{
	public function afterCompile(ClassType $class): void
	{
		$class->getMethod('initialize')->addBody('$this->getService(?);', ['database']);
	}
}
