<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Command;

use Etobi\Mensamax\Config\AppConfig;
use Etobi\Mensamax\HomeAssistant\EntityCatalog;
use Etobi\Mensamax\HomeAssistant\MqttPublisher;
use Etobi\Mensamax\HomeAssistant\PublishException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'remove', description: 'Remove the Home Assistant entities of an account (clears the MQTT discovery topics)')]
final class RemoveCommand extends Command
{
    public function __construct(
        private readonly AppConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED, 'Account id as listed in MENSAMAX_ACCOUNTS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = strtolower((string) $input->getArgument('account'));
        foreach ($this->config->accounts as $account) {
            if ($account->id !== $id) {
                continue;
            }
            $publisher = new MqttPublisher($this->config->mqtt, new EntityCatalog($this->config->mqtt), new ConsoleLogger($output));
            try {
                $publisher->removeAccount($account);
                $publisher->disconnect();
            } catch (PublishException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
            $output->writeln(sprintf('Removed Home Assistant entities of account "%s".', $id));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>Unknown account "%s".</error>', $id));

        return Command::INVALID;
    }
}
