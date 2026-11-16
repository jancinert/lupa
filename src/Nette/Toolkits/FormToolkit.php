<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\Database\Connections;
use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Scalar;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Nette\ComponentModel\IComponent;
use Nette\DI\Container;
use Nette\Forms\Control;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;
use Nette\Forms\Rules;


/**
 * Structure of a Nette form: controls, rules and conditions, default values and items.
 * The form is created the way the application creates it, but never submitted or rendered.
 */
final class FormToolkit implements Toolkit
{
	private const Results = [
		'form' => [
			'class' => '?string',
			'method' => '?string',
			'containers' => 'string[]',
			'controls' => [[
				'name' => 'string',
				'type' => 'string',
				'caption' => '?string',
				'required' => 'boolean',
				'disabled' => 'boolean',
				'omitted' => 'boolean',
				'value' => 'mixed',
				'rules' => 'object[]', // validator, negative, argument, message; a condition has nested rules
				'items' => 'object|array',
				'itemCount' => 'integer',
			]],
		],
	];

	private const ItemLimit = 50;

	private Masking $masking;

	private Connections $connections;


	public function __construct(Masking $masking, Connections $connections)
	{
		$this->masking = $masking;
		$this->connections = $connections;
	}


	public function getTools(): array
	{
		return Tool::withResults([new Tool(
			'form',
			'Form structure',
			'Creates a form and lists its controls with type, caption, required flag, validation rules and conditions,'
			. ' default values and choice items. Give either a presenter and the component name (createComponent<Name>),'
			. ' or a service and its factory method. Construction code of the application runs; the form is not submitted.',
			Schema::object([
				'presenter' => Schema::string('Presenter name, e.g. "Admin:Orders".'),
				'component' => Schema::string('Component name in the presenter, nested names separated by "-", e.g. "orderForm".'),
				'service' => Schema::string('Name of a service creating the form, e.g. a form factory.'),
				'method' => Schema::string('Method of the service returning the form, default "create".'),
				'arguments' => ['type' => 'array', 'description' => 'Arguments of the factory method.'],
			]),
		)], self::Results);
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		if ($tool !== 'form') {
			throw new InspectorException("Unknown tool '$tool'.");
		} elseif (!class_exists(Form::class)) {
			throw new InspectorException('The application does not use nette/forms.');
		}

		$container = NetteApplication::getContainerOf($context->application);
		$this->connections->lockSessions($context); // the factory is code of the application and must not write
		try {
			$form = $this->createForm($container, $arguments);
		} catch (InspectorException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new InspectorException('Creating the form failed: ' . get_class($e) . ': ' . $this->masking->maskText($e->getMessage())
				. ' in ' . $context->project->relativize($e->getFile()) . ':' . $e->getLine());
		}

		$controls = [];
		foreach (self::getComponentTree($form) as $component) {
			if ($component instanceof BaseControl) {
				$controls[] = $this->describeControl($component, $form);
			}
		}

		$data = [
			'class' => get_class($form),
			'method' => $form instanceof Form ? $form->getMethod() : null,
			'containers' => $this->listContainers($form),
			'controls' => $controls,
		];
		return new ToolResult($data, sprintf('%s with %d control(s).', get_class($form), count($controls)));
	}


	private function createForm(Container $container, Arguments $arguments): \Nette\Forms\Container
	{
		if ($arguments->has('presenter') && $arguments->has('component')) {
			$factory = $container->getByType('Nette\Application\IPresenterFactory');
			$presenter = $factory->createPresenter($arguments->string('presenter'));
			$form = $presenter->getComponent($arguments->string('component'));
		} elseif ($arguments->has('service')) {
			$method = $arguments->string('method', 'create');
			$service = $container->getService($arguments->string('service'));
			if (!method_exists($service, $method)) {
				throw new InspectorException("Service '{$arguments->string('service')}' has no method $method().");
			}

			// only a factory method declared to return a form may be called; any other method could change data
			$returnType = (new \ReflectionMethod($service, $method))->getReturnType();
			if (
				!$returnType instanceof \ReflectionNamedType
				|| $returnType->isBuiltin()
				|| !is_a($returnType->getName(), \Nette\Forms\Container::class, true)
			) {
				throw new InspectorException("Method $method() does not declare a form as its return type, so it is not called.");
			}

			$form = $service->$method(...array_values($arguments->items('arguments')));

		} else {
			throw new InspectorException("Give 'presenter' and 'component', or 'service' (and 'method').");
		}

		if (!$form instanceof \Nette\Forms\Container) {
			throw new InspectorException('The component is ' . (is_object($form) ? get_class($form) : gettype($form)) . ', not a form.');
		}

		return $form;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function describeControl(BaseControl $control, \Nette\Forms\Container $form): array
	{
		$name = self::getPath($control, $form);
		$value = $control instanceof TextInput && $control->getControlPrototype()->type === 'password'
			? null
			: $control->getValue();
		$data = [
			'name' => $name,
			'type' => (new \ReflectionClass($control))->getShortName(),
			'caption' => self::describeCaption(method_exists($control, 'getCaption') ? $control->getCaption() : $control->caption), // nette/forms 2.4
			'required' => (bool) $control->isRequired(),
			'disabled' => (bool) $control->isDisabled(),
			'omitted' => $control->isOmitted(),
			'value' => current((array) $this->masking->maskData([(string) $control->getName() => self::normalize($value)])),
			'rules' => $this->describeRules($control->getRules()),
		];
		if (method_exists($control, 'getItems')) {
			$items = $control->getItems();
			$data['items'] = $this->masking->maskData(array_slice((array) self::normalize($items), 0, self::ItemLimit, true));
			$data['itemCount'] = count((array) $items);
		}

		return $data;
	}


	/**
	 * @return list<array<string, mixed>>
	 */
	private function describeRules(Rules $rules): array
	{
		$result = [];
		foreach ($rules as $rule) {
			$control = $rule->control;
			$hidden = $this->masking->isSecretKey(Scalar::toString($control->getName()))
				|| ($control instanceof TextInput && $control->getControlPrototype()->type === 'password');
			$entry = [
				'validator' => self::describeValidator($rule->validator),
				'negative' => (bool) $rule->isNegative,
				'argument' => $hidden && $rule->arg !== null ? Masking::Mask : $this->masking->maskData(self::normalize($rule->arg)),
			];
			if ($rule->branch) {
				$entry['condition'] = true;
				$entry['control'] = $rule->control->getName();
				$entry['rules'] = $this->describeRules($rule->branch);
			} else {
				$entry['message'] = $this->masking->maskData(self::normalize($rule->message));
			}

			$result[] = $entry;
		}

		return $result;
	}


	/**
	 * @return list<string>
	 */
	private function listContainers(\Nette\Forms\Container $form): array
	{
		$result = [];
		foreach (self::getComponentTree($form) as $component) {
			if ($component instanceof \Nette\Forms\Container) {
				$result[] = self::getPath($component, $form);
			}
		}

		return $result;
	}


	/**
	 * Names from the inspected form down to the component, joined by "-" as in the HTML names of nested controls.
	 */
	private static function getPath(IComponent $component, \Nette\Forms\Container $root): string
	{
		$names = [];
		for ($current = $component; $current !== null && $current !== $root; $current = $current->getParent()) {
			array_unshift($names, (string) $current->getName());
		}

		return implode('-', $names);
	}


	/**
	 * All components below the container; component-model 4 replaced getComponents(true) by getComponentTree().
	 * @return iterable<IComponent>
	 */
	private static function getComponentTree(\Nette\Forms\Container $container): iterable
	{
		return method_exists($container, 'getComponentTree')
			? $container->getComponentTree()
			: $container->getComponents(true);
	}


	/**
	 * @param mixed $validator
	 */
	private static function describeValidator($validator): string
	{
		if (is_string($validator)) {
			return $validator;
		} elseif (is_array($validator) && count($validator) === 2) {
			return (is_object($validator[0]) ? get_class($validator[0]) : Scalar::toString($validator[0]))
				. '::'
				. Scalar::toString($validator[1]);
		}

		return $validator instanceof \Closure ? 'closure' : gettype($validator);
	}


	/**
	 * @param mixed $caption
	 */
	private static function describeCaption($caption): ?string
	{
		if ($caption === null || is_scalar($caption)) {
			return $caption === null ? null : (string) $caption;
		}

		if (!is_object($caption)) {
			return gettype($caption);
		}

		return method_exists($caption, '__toString') ? strip_tags((string) $caption) : get_class($caption);
	}


	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function normalize($value)
	{
		if (is_array($value)) {
			return array_map([self::class, 'normalize'], $value);
		} elseif ($value instanceof Control) {
			return 'control ' . Scalar::toString($value->getName());
		} elseif ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		} elseif (is_object($value)) {
			return method_exists($value, '__toString') ? (string) $value : get_class($value);
		}

		return $value;
	}
}
