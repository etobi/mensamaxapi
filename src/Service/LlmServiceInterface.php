<?php

namespace Etobi\MensamaxApi\Service;

interface LlmServiceInterface
{
    public function getSummary(string $jsonData): string;

    public function getShortDescriptions(array $items): array;
}
