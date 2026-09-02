<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class OpenAiShortener extends AbstractLlmShortener
{
    public const string DEFAULT_MODEL = 'gpt-4o-mini';

    private ClientInterface $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        int $maxLength = 30,
        ?ClientInterface $http = null,
    ) {
        parent::__construct($maxLength);
        $this->http = $http ?? new Client(['base_uri' => 'https://api.openai.com/v1/', 'timeout' => 60]);
    }

    protected function complete(string $system, string $user): string
    {
        try {
            $response = $this->http->request('POST', 'chat/completions', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.3,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new LlmException('OpenAI API error: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($text)) {
            throw new LlmException('Unexpected OpenAI response: ' . mb_substr((string) $response->getBody(), 0, 200));
        }

        return $text;
    }
}
