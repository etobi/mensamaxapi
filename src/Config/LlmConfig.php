<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Config;

final readonly class LlmConfig
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $apiKey,
        public int $maxLength,
    ) {
    }
}
