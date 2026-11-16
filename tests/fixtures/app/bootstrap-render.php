<?php declare(strict_types=1);

// Fixture application with the Nette stack a presenter needs to run: http, session, security, routing,
// application, latte and mail. The runtime directory is given by the test in FIXTURE_RUNTIME_DIR.

use Nette\Bridges;
use Nette\DI;

$runtimeDir = (string) getenv('FIXTURE_RUNTIME_DIR');
$databaseFile = $runtimeDir . (getenv('FIXTURE_OFFLINE_DATABASE') ? '/missing/app.sqlite' : '/app.sqlite');

$loader = new DI\ContainerLoader($runtimeDir . '/container-render', true);
$class = $loader->load(function (DI\Compiler $compiler) use ($runtimeDir, $databaseFile): void {
	$compiler->addConfig(['parameters' => [
		'appDir' => __DIR__ . '/src',
		'tempDir' => $runtimeDir . '/temp',
		'logDir' => $runtimeDir . '/log',
		'databaseFile' => $databaseFile,
	]]);
	$compiler->addExtension('extensions', new DI\Extensions\ExtensionsExtension);
	$compiler->addExtension('inject', new DI\Extensions\InjectExtension);
	$compiler->addExtension('http', new Bridges\HttpDI\HttpExtension(true));
	$compiler->addExtension('session', new Bridges\HttpDI\SessionExtension(false, true));
	$compiler->addExtension('security', new Bridges\SecurityDI\SecurityExtension(false));
	$compiler->addExtension('routing', new Bridges\ApplicationDI\RoutingExtension(false));
	$compiler->addExtension('application', new Bridges\ApplicationDI\ApplicationExtension(false, [], $runtimeDir . '/temp'));
	$compiler->addExtension('latte', new Bridges\ApplicationDI\LatteExtension($runtimeDir . '/temp/cache/latte', false));
	$compiler->addExtension('mail', new Bridges\MailDI\MailExtension);
	$compiler->addExtension('renderProbe', new class extends DI\CompilerExtension {
		public function afterCompile(Nette\PhpGenerator\ClassType $class): void
		{
			$class->getMethod('initialize')->addBody(
				<<<'PHP'
					if (getenv('FIXTURE_WRITE_ON_INITIALIZE')) {
						$this->getService('database')->nativeQuery("INSERT INTO customers VALUES (1, 'initialize')");
					}
					if (getenv('FIXTURE_DATABASE_APPEARS')) {
						$file = $this->parameters['databaseFile'];
						@mkdir(dirname($file)); // @ may exist
						copy(dirname($file, 2) . '/app.sqlite', $file);
					}
					if (getenv('FIXTURE_MAIL_ON_INITIALIZE')) {
						$mailer = $this->getService('mail.mailer');
						if (!$mailer instanceof \Lupa\Nette\Render\RecordingMailer) {
							throw new \LogicException('The application mailer was not replaced before initialization.');
						}
						$mailer->send((new \Nette\Mail\Message)->addTo('fixture@example.test')->setSubject('Initialization probe'));
					}
					$this->getService('routing.router')->addRoute('guarded', [
						'presenter' => 'Status',
						'action' => 'default',
						'' => ['filterIn' => function (array $parameters): array {
							$this->getService('database')->nativeQuery("INSERT INTO customers VALUES (2, 'routing')");
							return $parameters;
						}],
					]);
					PHP,
			);
		}
	});
	$compiler->loadConfig(__DIR__ . '/config-render.neon');
}, [$runtimeDir, $databaseFile, __FILE__]);

if (getenv('FIXTURE_RETURN_CONTAINER')) {
	return new $class;
}

return new class ($class) {
	protected array $dynamicParameters = [];
	protected array $services = [];
	private string $class;


	public function __construct(string $class)
	{
		$this->class = $class;
	}


	public function setDebugMode(bool $value): void
	{
		// the container above is rebuilt in any mode
	}


	public function loadContainer(): string
	{
		return $this->class;
	}
};
