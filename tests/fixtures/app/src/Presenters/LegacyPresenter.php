<?php declare(strict_types=1);

namespace App\Presenters;

use Dibi\Connection;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Mail\IMailer;
use Nette\Mail\Message;


/**
 * A presenter of an application on Nette 2.4, for the render tool: it reads, writes, sends an e-mail and asks who is logged in.
 */
final class LegacyPresenter extends Presenter
{
	// dresscode:disable phpdoc.annotation.nativeTypeOnly, phpdoc.property.lineSpan -- nette/di 2.4 reads the type from @var

	/**
	 * @inject
	 * @var Connection
	 */
	public Connection $database;

	/**
	 * @inject
	 * @var IMailer
	 */
	public IMailer $mailer;


	// dresscode:enable


	public function renderDefault(): void
	{
		$this->template->email = $this->database->fetchSingle('SELECT email FROM customers WHERE id = 1');
	}


	public function actionWrite(): void
	{
		$this->database->query('INSERT INTO orders (customer_id, total) VALUES (1, 1)');
		$this->sendResponse(new TextResponse('written'));
	}


	public function actionMail(): void
	{
		$this->mailer->send((new Message)->setFrom('shop@example.com')->addTo('jan@example.com')->setSubject('Order received'));
		$this->sendResponse(new TextResponse('sent'));
	}


	public function actionWho(): void
	{
		$user = $this->getUser();
		$this->sendResponse(new TextResponse($user->isLoggedIn() ? $user->getId() . ':' . implode(',', $user->getRoles()) : 'nobody'));
	}
}
