<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

use Etobi\Mensamax\Config\ConfigException;
use Etobi\Mensamax\Config\LlmConfig;

final class ShortenerFactory
{
    public static function create(LlmConfig $config, string $dataDir): TextShortener
    {
        $inner = match ($config->provider) {
            'none' => new NullShortener(),
            'claude' => new ClaudeShortener(
                self::requireKey($config),
                $config->model !== '' ? $config->model : ClaudeShortener::DEFAULT_MODEL,
                $config->maxLength,
            ),
            'openai' => new OpenAiShortener(
                self::requireKey($config),
                $config->model !== '' ? $config->model : OpenAiShortener::DEFAULT_MODEL,
                $config->maxLength,
            ),
            default => throw new ConfigException('Unknown LLM_PROVIDER ' . $config->provider),
        };

        if ($inner instanceof NullShortener) {
            return $inner;
        }

        return new CachedShortener($inner, $dataDir . '/short-texts.json');
    }

    private static function requireKey(LlmConfig $config): string
    {
        if ($config->apiKey === '') {
            throw new ConfigException(sprintf('LLM_API_KEY is required for LLM_PROVIDER=%s', $config->provider));
        }

        return $config->apiKey;
    }
}
