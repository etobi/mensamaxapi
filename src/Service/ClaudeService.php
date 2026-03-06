<?php

namespace Etobi\MensamaxApi\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ClaudeService extends AbstractLlmService
{

    public function __construct(
        protected string $apiKey = '',
        protected string $model = 'claude-sonnet-4-6'
    ) {
    }

    protected function request(string $prompt): mixed
    {
        $client = new Client();

        try {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => 200,
                    'system' => self::SYSTEM_PROMPT,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return $result['content'][0]['text']
                ?? throw new LlmException('Unerwartetes API-Antwortformat von Claude');
        } catch (RequestException $e) {
            throw new LlmException('Claude-API-Fehler: ' . $e->getMessage(), 0, $e);
        }
    }
}
