<?php declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


final class ReportCommand extends Command
{
	protected function configure(): void
	{
		$this->setName('app:report')
			->setDescription('Prints the daily report.')
			->addArgument('date', InputArgument::REQUIRED, 'Day of the report.')
			->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Do not send anything.')
			->addOption('api-key', null, InputOption::VALUE_OPTIONAL, 'API key.', 'synthetic-console-secret');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		throw new \LogicException('The inspector must never run a command.');
	}
}
