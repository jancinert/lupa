<?php declare(strict_types=1);

/**
 * What Composer installed for an application, read from its metadata alone.
 */

use Lupa\ComposerMetadata;
use Lupa\Inspector;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/**
 * @param list<array<string, mixed>> $packages
 */
function writeInstalled(string $vendor, array $packages): void
{
	@mkdir("$vendor/composer", 0777, true); // @ may exist
	file_put_contents("$vendor/composer/installed.json", json_encode(['packages' => $packages]));
}


// an application told by its own metadata, though the test process has registered Lupa's vendor with Nette
$plain = TempDir . '/plain';
writeInstalled("$plain/vendor", [['name' => 'dibi/dibi']]);
Assert::same('none', Inspector::detectFramework("$plain/vendor/autoload.php", $plain));
writeInstalled(TempDir . '/nette/vendor', [['name' => 'nette/bootstrap']]);
Assert::same('nette', Inspector::detectFramework(TempDir . '/nette/vendor/autoload.php'));

// an application without any package is still one Composer installed, not one without metadata
$empty = TempDir . '/empty';
writeInstalled("$empty/vendor", []);
Assert::same([], ComposerMetadata::readInstalled("$empty/vendor/autoload.php"));
Assert::null(ComposerMetadata::readInstalled(TempDir . '/nothing/autoload.php'));

// the vendor directory of composer.json, relative or absolute, when the autoloader is elsewhere
$relative = TempDir . '/relative';
writeInstalled("$relative/libs", [['name' => 'nette/nette']]);
file_put_contents("$relative/composer.json", json_encode(['config' => ['vendor-dir' => 'libs']]));
Assert::same('nette', Inspector::detectFramework("$relative/app/autoload.php", $relative));
$absolute = TempDir . '/absolute';
writeInstalled(TempDir . '/shared', [['name' => 'nette/bootstrap']]);
@mkdir($absolute); // @ may exist
file_put_contents("$absolute/composer.json", json_encode(['config' => ['vendor-dir' => TempDir . '/shared']]));
Assert::same('nette', Inspector::detectFramework("$absolute/app/autoload.php", $absolute));

// provided and replaced names, and the names of Composer 1, which writes a list without install paths
writeInstalled(TempDir . '/meta/vendor', [['name' => 'acme/all', 'replace' => ['Nette/DI' => '*'], 'provide' => ['ext-mbstring' => '*']]]);
Assert::same(
	['acme/all' => true, 'ext-mbstring' => true, 'nette/di' => true],
	ComposerMetadata::readInstalled(TempDir . '/meta/vendor/autoload.php'),
);
@mkdir(TempDir . '/composer1/vendor/composer', 0777, true); // @ may exist
file_put_contents(TempDir . '/composer1/vendor/composer/installed.json', json_encode([['name' => 'Nette/Bootstrap']]));
Assert::same('nette', Inspector::detectFramework(TempDir . '/composer1/vendor/autoload.php'));
