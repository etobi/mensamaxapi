<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Config;

final readonly class MqttConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public bool $tls,
        public string $discoveryPrefix,
        public string $topicPrefix,
        public string $clientId,
    ) {
    }
}
