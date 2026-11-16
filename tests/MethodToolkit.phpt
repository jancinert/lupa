<?php declare(strict_types=1);

use Lupa\Nette\NetteApplication;
use Lupa\Tools\AppContext;
use Lupa\Tools\MethodToolkit;
use Lupa\Tools\ToolCatalog;
use Lupa\Tools\ToolResult;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


final class OrderToolkit extends MethodToolkit
{
	public function __construct()
	{
	}


	/**
	 * Finds orders of a customer,
	 * newest first.
	 * @param string $customer  name of the customer
	 * @param array<string> $states
	 * @param int $limit  at most this many
	 */
	public function findOrders(string $customer, AppContext $context, array $states = [], int $limit = 10, ?float $minimum = null): array
	{
		return ['customer' => $customer, 'states' => $states, 'limit' => $limit, 'minimum' => $minimum, 'root' => $context->project->root];
	}


	/**
	 * Cancels an order.
	 * @active
	 * @untrusted
	 */
	public function cancel(bool $notify): ToolResult
	{
		return new ToolResult(['notify' => $notify], 'cancelled');
	}


	public static function create(): self
	{
		return new self;
	}


	private function help(): void
	{
	}
}


final class Order
{
	public int $id = 1;
	public ?string $note = null;

	/** @var list<OrderItem> */
	public array $items = [];

	/** @var string[] */
	public array $tags = ['new'];
	public array $extra = ['key' => 'value'];
	public ?Order $previous = null;
	public $anything;
	public static int $count = 0;
	private string $hidden = 'x';
}


final class OrderItem
{
	public string $name;
	public float $price;


	public function __construct(string $name, float $price)
	{
		$this->name = $name;
		$this->price = $price;
	}
}


final class OrderReader extends MethodToolkit
{
	/** Shows an order. */
	public function show(): Order
	{
		$order = new Order;
		$order->items = [new OrderItem('pen', 1.5)];
		return $order;
	}
}


final class UndocumentedToolkit extends MethodToolkit
{
	public function ping(): array
	{
		return [];
	}
}


final class UntypedToolkit extends MethodToolkit
{
	/** Pings. */
	public function ping($target): array
	{
		return [];
	}
}


final class ScalarToolkit extends MethodToolkit
{
	/** Pings. */
	public function ping(): string
	{
		return 'pong';
	}
}


$toolkit = new OrderToolkit;
[$find, $cancel] = $toolkit->getTools();

Assert::same('find_orders', $find->name);
Assert::same('Find orders', $find->title);
Assert::same('Finds orders of a customer, newest first.', $find->description);
Assert::same([
	'type' => 'object',
	'properties' => [
		'customer' => ['type' => 'string', 'description' => 'name of the customer'],
		'states' => ['type' => 'array'],
		'limit' => ['type' => 'integer', 'description' => 'at most this many'],
		'minimum' => ['type' => 'number'],
	],
	'required' => ['customer'],
	'additionalProperties' => false,
], $find->inputSchema);
Assert::true($find->readOnly);

Assert::same('cancel', $cancel->name);
Assert::same('Cancels an order.', $cancel->description);
Assert::same(['notify'], $cancel->inputSchema['required']);
Assert::false($cancel->readOnly);
Assert::same([false, true], [$find->untrusted, $cancel->untrusted]);

Assert::same('Pings.', (new ScalarToolkit)->getTools()[0]->description);

$context = new AppContext(new NetteApplication(new Nette\DI\Container), createFixtureProject(), new ToolCatalog([$toolkit]), []);
$result = $toolkit->call('find_orders', ['customer' => 'Example', 'minimum' => 5], $context);
Assert::same(['customer' => 'Example', 'states' => [], 'limit' => 10, 'minimum' => 5.0, 'root' => FixtureDir], $result->data);
Assert::contains('"customer": "Example"', $result->text);
Assert::same('cancelled', $toolkit->call('cancel', ['notify' => true], $context)->text);

// a returned object gives the schema of the result and becomes an array
$show = (new OrderReader)->getTools()[0];
$item = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number']]];
Assert::same(['type' => 'integer'], $show->outputSchema['properties']['id']);
Assert::same(['type' => ['string', 'null']], $show->outputSchema['properties']['note']);
Assert::same(['type' => 'array', 'items' => $item], $show->outputSchema['properties']['items']);
Assert::same(['type' => 'array', 'items' => ['type' => 'string']], $show->outputSchema['properties']['tags']);
Assert::same(['type' => ['array', 'object']], $show->outputSchema['properties']['extra']);
Assert::same(['object', 'null'], $show->outputSchema['properties']['previous']['type']);
Assert::same([], $show->outputSchema['properties']['anything']);
Assert::same(['id', 'note', 'items', 'tags', 'extra', 'previous', 'anything'], array_keys($show->outputSchema['properties']));
Assert::null($find->outputSchema);

$data = (new OrderReader)->call('show', [], $context)->data;
Assert::same([['name' => 'pen', 'price' => 1.5]], $data['items']);
Assert::false(array_key_exists('hidden', $data));
Assert::same([], findSchemaErrors($show->outputSchema, $data, 'show'));

Assert::exception(fn() => $toolkit->call('create', [], $context), Lupa\InspectorException::class, "Unknown tool 'create'.");
Assert::exception(
	fn() => (new UndocumentedToolkit)->getTools(),
	Lupa\InspectorException::class,
	"Tool 'ping' needs a doc comment with its description.",
);
Assert::exception(
	fn() => (new UntypedToolkit)->getTools(),
	Lupa\InspectorException::class,
	"Parameter \$target of tool 'ping' must be a string, int, float, bool or array.",
);
Assert::exception(
	fn() => (new ScalarToolkit)->call('ping', [], $context),
	Lupa\InspectorException::class,
	"Tool 'ping' must return an array, an object or a ToolResult.",
);
