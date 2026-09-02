<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;

final class ClaudeShortener extends AbstractLlmShortener
{
    public const string DEFAULT_MODEL = 'claude-opus-5';

    private Client $client;

    public function __construct(
        string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        int $maxLength = 30,
        ?Client $client = null,
    ) {
        parent::__construct($maxLength);
        $this->client = $client ?? new Client(apiKey: $apiKey);
    }

    protected function complete(string $system, string $user): string
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: 4096,
                messages: [['role' => 'user', 'content' => $user]],
                model: $this->model,
                system: $system,
            );
        } catch (AnthropicException $e) {
            throw new LlmException('Claude API error: ' . $e->getMessage(), 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new LlmException('Claude refused the request');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new LlmException('Claude answer contains no text block');
    }
}
