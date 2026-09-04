<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Command;

use Etobi\Mensamax\AccountProcessor;
use Etobi\Mensamax\Config\AppConfig;
use Etobi\Mensamax\HomeAssistant\EntityCatalog;
use Etobi\Mensamax\HomeAssistant\MqttPublisher;
use Etobi\Mensamax\HomeAssistant\PublishException;
use Etobi\Mensamax\Llm\ShortenerFactory;
use Etobi\Mensamax\Mensamax\MensamaxException;
use PhpMqtt\Client\Exceptions\MqttClientException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'publish', description: 'Fetch Mensamax data for all accounts and publish it to Home Assistant via MQTT')]
final class PublishCommand extends Command
{
    public function __construct(
        private readonly AppConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('account', 'a', InputOption::VALUE_REQUIRED, 'Only process this account id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not publish, print the overview JSON instead')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Also print the overview JSON when publishing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output);
        $dryRun = (bool) $input->getOption('dry-run');
        $printJson = $dryRun || (bool) $input->getOption('json');
        $onlyAccount = $input->getOption('account');

        $processor = new AccountProcessor($this->config, ShortenerFactory::create($this->config->llm, $this->config->dataDir), $logger);
        $publisher = $dryRun ? null : new MqttPublisher($this->config->mqtt, new EntityCatalog($this->config->mqtt), $logger);

        $failed = 0;
        $processed = 0;
        foreach ($this->config->accounts as $account) {
            if ($onlyAccount !== null && $account->id !== strtolower((string) $onlyAccount)) {
                continue;
            }
            $processed++;

            try {
                $overview = $processor->process($account);
            } catch (MensamaxException $e) {
                $failed++;
                $logger->error(sprintf('Account "%s": %s', $account->id, $e->getMessage()));
                try {
                    $publisher?->publishUnavailable($account);
                } catch (PublishException|MqttClientException $pe) {
                    $logger->error($pe->getMessage());
                }
                continue;
            }

            if ($printJson) {
                $output->writeln(json_encode($overview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }

            try {
                $publisher?->publishAccount($account, $overview);
            } catch (PublishException|MqttClientException $e) {
                $failed++;
                $logger->error(sprintf('Account "%s": %s', $account->id, $e->getMessage()));
            }
        }
        $publisher?->disconnect();

        if ($processed === 0) {
            $output->writeln(sprintf('<error>No account matches "%s". Configured: %s</error>', (string) $onlyAccount, implode(', ', array_map(fn ($a) => $a->id, $this->config->accounts))));

            return Command::INVALID;
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
