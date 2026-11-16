<?php declare(strict_types=1);

/**
 * A checkout of Lupa lends its libraries to an application all or none, never a package only for its development,
 * and meets the requirement of an extension by the PHP, the application or a package of the checkout providing it.
 * Each case runs in a process of its own over a made-up checkout and application.
 */

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/**
 * @param array<string, array{require?: array<string, string>, provide?: array<string, string>, class?: string, files?: string}> $packages
 * @param list<string> $requirements  of the composer.json of the checkout
 */
function createCheckout(string $dir, array $packages, array $requirements): string
{
	$installed = $classMap = $files = [];
	foreach ($packages as $name => $package) {
		$path = "$dir/vendor/$name";
		mkdir($path, 0777, true);
		$installed[] = ['name' => $name, 'install-path' => "../$name"] + array_intersect_key($package, ['require' => 1, 'provide' => 1]);
		if (isset($package['class'])) {
			$short = substr($package['class'], strrpos($package['class'], '\\') + 1);
			file_put_contents("$path/$short.php", "<?php\nnamespace Probe;\nclass $short { const FROM = 'checkout'; }\n");
			$classMap[] = var_export($package['class'], true) . " => \$vendorDir . '/$name/$short.php'";
		}
		if (isset($package['files'])) {
			file_put_contents("$path/bootstrap.php", "<?php\nfunction {$package['files']}() {}\n");
			$files[] = var_export(md5($name), true) . " => \$vendorDir . '/$name/bootstrap.php'";
		}
	}

	mkdir("$dir/vendor/composer", 0777, true);
	copy(dirname(VendorAutoload) . '/composer/ClassLoader.php', "$dir/vendor/composer/ClassLoader.php");
	file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => $installed]));
	$map = static fn(array $items): string => "<?php\n\$vendorDir = dirname(__DIR__);\nreturn [" . implode(', ', $items) . "];\n";
	file_put_contents("$dir/vendor/composer/autoload_classmap.php", $map($classMap));
	file_put_contents("$dir/vendor/composer/autoload_files.php", $map($files));
	file_put_contents("$dir/vendor/composer/autoload_psr4.php", $map([]));
	file_put_contents("$dir/vendor/composer/autoload_namespaces.php", $map([]));
	file_put_contents("$dir/composer.json", json_encode(['require' => ['php' => '>=7.4'] + array_fill_keys($requirements, '*')]));
	return "$dir/vendor";
}


/**
 * Registers the libraries of the checkout for an application and reports what then loads.
 * @param ?list<array<string, mixed>> $metadata  packages Composer installed for the application, as in installed.json
 * @param string $where  'beside' the autoloader, 'registered' in a vendor directory a Composer class loader of
 *    the application names, or 'composer1' beside the autoloader in the list of Composer 1
 * @return array<string, mixed>
 */
function lend(string $case, string $vendor, ?array $metadata = null, string $where = 'beside', string $autoload = ''): array
{
	$application = TempDir . "/$case-application";
	$metadataDir = $where === 'registered' ? "$application/elsewhere/composer" : "$application/composer";
	mkdir($metadataDir, 0777, true);
	if ($metadata !== null) {
		file_put_contents("$metadataDir/installed.json", json_encode($where === 'composer1' ? $metadata : ['packages' => $metadata]));
	}
	if ($where === 'registered') {
		$autoload .= 'require ' . var_export(dirname(VendorAutoload) . '/composer/ClassLoader.php', true) . ';'
			. '(new Composer\Autoload\ClassLoader(__DIR__ . "/elsewhere"))->register();';
	}

	file_put_contents("$application/autoload.php", "<?php\n$autoload");
	file_put_contents("$application/run.php", '<?php
		require ' . var_export(dirname(__DIR__) . '/src/autoload.php', true) . ';
		require __DIR__ . "/autoload.php";
		try {
			Lupa\CheckoutLibraries::register(' . var_export($vendor, true) . ', __DIR__ . "/autoload.php");
			$loaders = class_exists(Composer\Autoload\ClassLoader::class, false) ? Composer\Autoload\ClassLoader::getRegisteredLoaders() : [];
			echo json_encode([
				"lib" => class_exists("Probe\\Lib") ? Probe\Lib::FROM : null,
				"other" => class_exists("Probe\\Other"),
				"devtool" => class_exists("Probe\\DevTool"),
				"polyfill" => function_exists("probe_polyfill"),
				"listed" => array_map("basename", array_keys($loaders)),
			]);
		} catch (Lupa\InspectorException $e) {
			echo json_encode(["error" => $e->getMessage()]);
		}
	');
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$application/run.php") . ' 2>&1', $output);
	$result = json_decode(implode("\n", $output), true);
	Assert::type('array', $result, implode("\n", $output));
	return $result;
}


/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function pick(array $result, string ...$keys): array
{
	return array_intersect_key($result, array_flip($keys));
}


$lib = ['class' => 'Probe\Lib', 'require' => ['php' => '>=7.4']];
$needsExtension = ['class' => 'Probe\Lib', 'require' => ['ext-lupa_absent' => '*']];
$polyfill = ['files' => 'probe_polyfill', 'provide' => ['ext-lupa_absent' => '*']];
$other = ['class' => 'Probe\Other'];
$devtool = ['class' => 'Probe\DevTool'];
$refusal = "The application has %s but not %s, which Lupa needs, and Lupa does not mix its own versions with those of the application. Add the missing packages to the application, or install Lupa into it with 'composer require --dev jancinert/lupa', which picks versions that fit.";
$instructions = "Update the dependencies of the checkout on the PHP of the application with 'composer update --no-dev', so that Composer picks versions it supports.";


// an application with none of the libraries gets them all, but not a package the checkout has for its development
$vendor = createCheckout(TempDir . '/plain', ['probe/lib' => $lib, 'probe/devtool' => $devtool], ['probe/lib']);
Assert::same(['lib' => 'checkout', 'devtool' => false], pick(lend('plain', $vendor), 'lib', 'devtool'));

// the application found by the vendor directory of its registered class loader, which keeps the only one listed
Assert::same(
	['lib' => 'checkout', 'listed' => ['elsewhere']],
	pick(lend('registered', $vendor, [['name' => 'probe/unrelated']], 'registered'), 'lib', 'listed'),
);

// a missing extension is provided by a package of the checkout, which is lent too
$vendor = createCheckout(TempDir . '/provided', ['probe/lib' => $needsExtension, 'probe/polyfill' => $polyfill], ['probe/lib']);
Assert::same(['lib' => 'checkout', 'polyfill' => true], pick(lend('provided', $vendor), 'lib', 'polyfill'));

// ... or by the application, and then the checkout lends no polyfill
$itsPolyfill = [['name' => 'probe/its-polyfill', 'provide' => ['ext-lupa_absent' => '*']]];
Assert::same(['lib' => 'checkout', 'polyfill' => false], pick(lend('application-provides', $vendor, $itsPolyfill), 'lib', 'polyfill'));

// ... and without either, the checkout says how to install versions this PHP supports
$vendor = createCheckout(TempDir . '/unmet', ['probe/lib' => $needsExtension], ['probe/lib']);
Assert::same(
	['error' => "Package probe/lib of the Lupa checkout needs the PHP extension lupa_absent. $instructions"],
	lend('unmet', $vendor),
);

// a polyfill that requires an extension itself is checked as well
$vendor = createCheckout(
	TempDir . '/chained',
	['probe/lib' => $needsExtension, 'probe/polyfill' => $polyfill + ['require' => ['ext-lupa_other' => '*']]],
	['probe/lib'],
);
Assert::same(
	['error' => "Package probe/polyfill of the Lupa checkout needs the PHP extension lupa_other. $instructions"],
	lend('chained', $vendor),
);

// an application with some of the libraries gets none, as versions resolved apart need not fit together,
// also when it shares only a package they require, which the message names with the one that needs it
$vendor = createCheckout(
	TempDir . '/partial',
	['probe/lib' => ['require' => ['probe/dep' => '*']] + $lib, 'probe/dep' => ['class' => 'Probe\Dep'], 'probe/other' => $other],
	['probe/lib', 'probe/other'],
);
Assert::same(['error' => sprintf($refusal, 'probe/lib', 'probe/other')], lend('partial', $vendor, [['name' => 'probe/lib']]));
Assert::same(
	['error' => sprintf($refusal, 'probe/dep (needed by probe/lib)', 'probe/lib, probe/other')],
	lend('shared', $vendor, [['name' => 'probe/dep']]),
);
Assert::same(
	['error' => sprintf($refusal, 'probe/lib', 'probe/other')],
	lend('composer1', $vendor, [['name' => 'probe/lib']], 'composer1'),
);

// an application with all of them gets nothing, also when its packages replace them
Assert::same(
	['lib' => null, 'other' => false],
	pick(lend('complete', $vendor, [['name' => 'probe/lib'], ['name' => 'probe/other']]), 'lib', 'other'),
);
Assert::same(
	['lib' => null, 'other' => false],
	pick(
		lend('replaced', $vendor, [['name' => 'probe/all', 'replace' => ['probe/lib' => '*', 'probe/other' => '*']]], 'registered'),
		'lib',
		'other',
	),
);

// without the metadata of Composer, the classes of the application tell what it has
$vendor = createCheckout(TempDir . '/classes', ['probe/lib' => $lib], ['probe/lib']);
$ownLib = 'spl_autoload_register(function ($class) { if ($class === "Probe\\\\Lib") { eval("namespace Probe; class Lib { const FROM = \'application\'; }"); } });';
Assert::same('application', lend('classes', $vendor, null, 'beside', $ownLib)['lib']);

// a checkout installed for a newer PHP says so; its platform check, which older Composer ends by exit(), never runs
$vendor = createCheckout(TempDir . '/platform', ['probe/lib' => $lib], ['probe/lib']);
file_put_contents(
	"$vendor/composer/platform_check.php",
	"<?php\nif (!(PHP_VERSION_ID >= 990000)) { echo 'Composer detected issues'; exit(104); }\n",
);
Assert::same(
	['error' => 'The Lupa checkout was installed for PHP 99.0.0 or newer, this is PHP ' . PHP_VERSION . ". $instructions"],
	lend('platform', $vendor),
);
