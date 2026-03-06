<?php

use Etobi\MensamaxApi\Service\ChatgptService;
use Etobi\MensamaxApi\Service\ClaudeService;
use Etobi\MensamaxApi\Service\LlmException;
use Etobi\MensamaxApi\Service\LlmServiceInterface;
use Etobi\MensamaxApi\Service\MensamaxApiService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');
$config = [
    'baseurl' => $_ENV['MENSAMAXAPI_baseurl'],
    'project' => $_ENV['MENSAMAXAPI_project'],
    'username' => $_ENV['MENSAMAXAPI_username'],
    'password' => $_ENV['MENSAMAXAPI_password'],
];

$llmProvider = $_ENV['LLM_PROVIDER'] ?? 'claude';
$llmModel = $_ENV['LLM_MODEL'] ?? '';
$llmApiKey = $_ENV['LLM_API_KEY'] ?? '';

$llmService = match ($llmProvider) {
    'openai' => new ChatgptService($llmApiKey, $llmModel ?: 'gpt-4'),
    'claude' => new ClaudeService($llmApiKey, $llmModel ?: 'claude-sonnet-4-6'),
    default => throw new \RuntimeException("Unknown LLM_PROVIDER: $llmProvider"),
};

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world!");
    return $response;
});

$app->get('/get', function (Request $request, Response $response, $args) {
    $response->getBody()->write(
        file_get_contents(__DIR__ . '/../storage/fetch.json')
    );
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/summary', function (Request $request, Response $response, $args) use ($llmService) {
    try {
        $response->getBody()->write(
            $llmService->getSummary(
                file_get_contents(__DIR__ . '/../storage/fetch.json')
            )
        );
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    } catch (LlmException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
    }
});

$app->post('/fetch', function (Request $request, Response $response, $args) use ($config, $llmService) {
    try {
        $mensamaxApiService = new MensamaxApiService($config, $llmService);
        $result = $mensamaxApiService->fetch(4);

        file_put_contents(__DIR__ . '/../storage/fetch.json', json_encode($result));

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (LlmException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
    }
});

$app->run();