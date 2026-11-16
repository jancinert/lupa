<?php declare(strict_types=1);

namespace App\Presenters;

use Dibi\Connection;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Mail\Mailer;
use Nette\Mail\Message;


/**
 * Actions covering what the render tool must report: JSON, redirect, HTML, a write, an e-mail, roles, 404, an error in a template.
 */
final class StatusPresenter extends Presenter
{
	// dresscode:disable phpdoc.annotation.nativeTypeOnly -- nette/di 3.0 reads the type of an injected property from @var

	/**
	 * @inject
	 * @var Connection
	 */
	public Connection $database;

	/**
	 * @inject
	 * @var Mailer
	 */
	public Mailer $mailer;


	// dresscode:enable


	public function actionDefault(): void
	{
		$this->sendJson(['ok' => true, 'contact' => 'jan@example.com']);
	}


	public function actionAway(): void
	{
		$this->redirectUrl('http://example.test/elsewhere?token=fixture-redirect-value');
	}


	public function actionSensitive(): void
	{
		$this->sendResponse(
			new TextResponse('<div><input name="api_key" value="synthetic-render-secret"><p>person&#64;example.test</p><p>Public content</p></div>'),
		);
	}


	public function actionWrite(): void
	{
		$this->database->query('INSERT INTO customers (name) VALUES (?)', 'written by render');
		$this->sendJson(['written' => true]);
	}


	public function actionMail(): void
	{
		$message = new Message;
		$message->setFrom('shop@example.com')->addTo('jan@example.com')->setSubject('Order confirmed')->setBody('Thanks.');
		$this->mailer->send($message);
		$this->sendJson(['sent' => true]);
	}


	public function actionWho(): void
	{
		$this->sendJson(['loggedIn' => $this->getUser()->isLoggedIn(), 'roles' => $this->getUser()->getRoles()]);
	}


	public function actionMissing(): void
	{
		$this->error('No such order.');
	}


	public function renderPage(): void
	{
		$this->template->contact = 'jan@example.com';
	}


	public function renderBroken(): void
	{
		$this->template->order = null;
	}
}
