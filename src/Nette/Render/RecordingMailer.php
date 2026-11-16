<?php declare(strict_types=1);

namespace Lupa\Nette\Render;

use Nette\Mail\Mailer;
use Nette\Mail\Message;


/**
 * Takes the place of the mailers of a rendered request: messages are recorded, never sent (nette/mail 3.1+).
 */
final class RecordingMailer implements Mailer
{
	/** @var list<array{to: list<string>, subject: ?string}> */
	public array $messages = [];


	public function send(Message $mail): void
	{
		$this->messages[] = ['to' => array_map('strval', array_keys((array) $mail->getHeader('To'))), 'subject' => $mail->getSubject()];
	}
}
