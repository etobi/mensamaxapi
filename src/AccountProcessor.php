<?php

declare(strict_types=1);

namespace Etobi\Mensamax;

use DateTimeImmutable;
use DateTimeZone;
use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Config\AppConfig;
use Etobi\Mensamax\Domain\AccountSnapshot;
use Etobi\Mensamax\Llm\LlmException;
use Etobi\Mensamax\Llm\TextShortener;
use Etobi\Mensamax\Mensamax\MensamaxClient;
use Etobi\Mensamax\Mensamax\SnapshotFetcher;
use Etobi\Mensamax\Overview\OverviewBuilder;
use Psr\Log\LoggerInterface;

/**
 * Fetch -> shorten -> build overview for one account.
 */
final readonly class AccountProcessor
{
    public function __construct(
        private AppConfig $config,
        private TextShortener $shortener,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed> the overview structure
     *
     * @throws \Etobi\Mensamax\Mensamax\MensamaxException
     */
    public function process(AccountConfig $account, ?DateTimeImmutable $now = null): array
    {
        $timezone = new DateTimeZone($this->config->timezone);
        $now ??= new DateTimeImmutable('now', $timezone);
        $now = $now->setTimezone($timezone);

        $this->logger->info(sprintf('Fetching Mensamax data for "%s"', $account->id));
        $fetcher = new SnapshotFetcher(new MensamaxClient($this->config->mensamaxBaseUrl), $timezone);
        $snapshot = $fetcher->fetch($account, $now);
        $this->logger->info(sprintf('Got %d days, balance %.2f', count($snapshot->days), $snapshot->balanceCurrent));

        $llmError = null;
        $shortTexts = [];
        try {
            $shortTexts = $this->shortener->shorten(self::mainTexts($snapshot));
        } catch (LlmException $e) {
            $llmError = $e->getMessage();
            $this->logger->error('Shortening dish names failed: ' . $llmError);
        }

        return (new OverviewBuilder($shortTexts))->build($snapshot, $account, $now, $llmError);
    }

    /**
     * @return list<string>
     */
    private static function mainTexts(AccountSnapshot $snapshot): array
    {
        $texts = [];
        foreach ($snapshot->days as $day) {
            foreach ($day->offeredMenus() as $menu) {
                if ($menu->main !== '') {
                    $texts[$menu->main] = true;
                }
            }
        }

        return array_keys($texts);
    }
}
