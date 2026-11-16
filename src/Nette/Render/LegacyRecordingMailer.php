<?php declare(strict_types=1);

namespace Lupa\Nette\Render;

use Nette\Mail\IMailer;
use Nette\Mail\Message;


/**
 * The recording mailer for nette/mail before 3.1, where the interface is IMailer.
 */
final class LegacyRecordingMailer implements IMailer
{
	/** @var list<array{to: list<string>, subject: ?string}> */
	public array $messages = [];


	public function send(Message $mail): void
	{
		$this->messages[] = ['to' => array_map('strval', array_keys((array) $mail->getHeader('To'))), 'subject' => $mail->getSubject()];
	}
}
