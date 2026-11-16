<?php declare(strict_types=1);

namespace App\Presenters;

use App\Model\OrderFacade;
use Nette\Application\UI\Presenter;
use Nette\ComponentModel\Container;
use Nette\ComponentModel\IComponent;


final class OrderPresenter extends Presenter
{
	/** @persistent */
	public string $filter = 'open';

	/** @persistent */
	public string $token = 'fixture-persistent-default';

	// dresscode:ignore phpdoc.annotation.nativeTypeOnly, phpdoc.property.lineSpan -- nette/di 2.4 reads the type from @var
	/**
	 * @inject
	 * @var OrderFacade
	 */
	public OrderFacade $orders;


	protected function startup(): void
	{
		parent::startup();
		$password = 'fixture-presenter-literal';
		if (!$this->getUser()->isAllowed('orders')) {
			$this->error('Forbidden', 403);
		}
	}


	public function actionDefault(): void
	{
	}


	public function renderDetail(int $id): void
	{
	}


	public function handleDelete(int $id): void
	{
	}


	protected function createComponentGrid(): IComponent
	{
		return new Container;
	}
}
