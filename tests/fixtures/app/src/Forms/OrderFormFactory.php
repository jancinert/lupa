<?php declare(strict_types=1);

namespace App\Forms;

use Nette\Forms\Form;


final class OrderFormFactory
{
	public function create(): Form
	{
		$form = new Form;
		$form->addText('name', 'Name')
			->setRequired('Fill in the name.')
			->addRule(Form::MAX_LENGTH, 'At most %d characters.', 100);
		$form->addEmail('email', 'E-mail')
			->setDefaultValue('jan@example.com');
		$form->addPassword('password', 'Password');
		$form->addSelect('status', 'Status', ['open' => 'Open', 'other' => 'Other']);
		$form->addTextArea('note', 'Note')
			->addConditionOn($form['status'], Form::EQUAL, 'other')
				->setRequired('Explain the status.');
		$address = $form->addContainer('address');
		$address->addText('city', 'City');
		$form->addSubmit('save', 'Save');
		return $form;
	}
}
