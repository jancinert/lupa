<?php declare(strict_types=1);

namespace Lupa\DI;

use Nette\DI\CompilerExtension;
use Nette\Schema\Processor;


/**
 * An extension with a section of lupa.neon. nette/di 3 checks the section against getConfigSchema() itself;
 * nette/di 2.4 passes it as it was written, so it is checked here.
 */
abstract class Extension extends CompilerExtension
{
	protected function getSettings(): \stdClass
	{
		$config = $this->getConfig();
		if (!$config instanceof \stdClass) {
			$config = (new Processor)->process($this->getConfigSchema(), $config);
		}

		if (!$config instanceof \stdClass) {
			throw new \LogicException('The schema of a section must be a structure.');
		}

		return $config;
	}
}
