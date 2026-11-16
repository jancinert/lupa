<?php declare(strict_types=1);

namespace Lupa\DI;

use Lupa\Masking;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Section 'masking': what is hidden in results besides the secret keys masked always.
 */
final class MaskingExtension extends Extension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'keys' => Expect::listOf('string'),
			'ignoreKeys' => Expect::listOf('string'), // names that only look secret, e.g. hash of a file
			'columns' => Expect::arrayOf(Expect::listOf('string')),
			'detectors' => Expect::listOf(Expect::anyOf(...Masking::Detectors))->nullable()->default(null), // null means all
		]);
	}


	public function loadConfiguration(): void
	{
		$config = $this->getSettings();
		$this->getContainerBuilder()->addDefinition($this->prefix('masking'))
			->setFactory(Masking::class, [
				$config->keys,
				$config->columns,
				$config->detectors ?? Masking::Detectors,
				$config->ignoreKeys,
			]);
	}
}
