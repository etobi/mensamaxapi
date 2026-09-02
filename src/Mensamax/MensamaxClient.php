<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Mensamax;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Thin client for the Mensamax GraphQL API. One instance holds one login session.
 */
final class MensamaxClient
{
    private const string MENU_PLAN_QUERY = <<<'GRAPHQL'
        query MenuPlan($von: DateTime!, $bis: DateTime!, $personId: Int!) {
            meinSpeiseplan(von: $von, bis: $bis) {
                datum
                message
                menues {
                    id
                    reihenfolge
                    meinSpeiseplanShow
                    preis(personId: $personId)
                    menuegruppe { bezeichnung }
                    fristen { bestellungBis abbestellungBis }
                    meineBestellung { anzahl }
                    vorspeisen { bezeichnung beschreibung }
                    hauptspeisen { bezeichnung beschreibung vegetarisch }
                    nachspeisen { bezeichnung beschreibung }
                }
            }
        }
        GRAPHQL;

    private ClientInterface $http;

    public function __construct(string $baseUrl, ?HandlerStack $handler = null, int $timeout = 30)
    {
        $options = [
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'cookies' => true,
            'timeout' => $timeout,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($handler !== null) {
            $options['handler'] = $handler;
        }
        $this->http = new Client($options);
    }

    public function login(string $project, string $username, string $password): void
    {
        $body = $this->post('graphql/auth/login', [
            'projekt' => $project,
            'benutzername' => $username,
            'passwort' => $password,
        ]);

        if (($body['status'] ?? null) !== 0 || empty($body['text'])) {
            throw new MensamaxException('Mensamax login failed: ' . ($body['text'] ?? json_encode($body)));
        }
    }

    public function personId(): int
    {
        $data = $this->query('{ meineDaten { personId } }');
        $id = $data['meineDaten']['personId'] ?? null;
        if (!is_numeric($id)) {
            throw new MensamaxException('Mensamax did not return a person id');
        }

        return (int) $id;
    }

    /**
     * @return array{current: float, future: ?float}
     */
    public function balance(): array
    {
        $data = $this->query('{ meinKontostand { gesamtKontostandAktuell gesamtKontostandZukunft } }');
        $balance = $data['meinKontostand'] ?? null;
        if (!is_array($balance) || !isset($balance['gesamtKontostandAktuell'])) {
            throw new MensamaxException('Mensamax did not return a balance');
        }

        return [
            'current' => (float) $balance['gesamtKontostandAktuell'],
            'future' => isset($balance['gesamtKontostandZukunft']) ? (float) $balance['gesamtKontostandZukunft'] : null,
        ];
    }

    /**
     * Raw menu plan days as returned by Mensamax (meinSpeiseplan).
     *
     * @return list<array<string, mixed>>
     */
    public function menuPlan(DateTimeImmutable $from, DateTimeImmutable $to, int $personId): array
    {
        $data = $this->query(self::MENU_PLAN_QUERY, [
            'von' => $from->format(DATE_ATOM),
            'bis' => $to->format(DATE_ATOM),
            'personId' => $personId,
        ]);

        $days = $data['meinSpeiseplan'] ?? null;
        if (!is_array($days)) {
            throw new MensamaxException('Mensamax did not return a menu plan');
        }

        return array_values($days);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function query(string $query, array $variables = []): array
    {
        $body = $this->post('graphql/', ['query' => $query, 'variables' => (object) $variables]);

        if (!empty($body['errors'])) {
            $messages = array_map(fn (array $e) => $e['message'] ?? 'unknown error', $body['errors']);
            throw new MensamaxException('Mensamax GraphQL error: ' . implode('; ', $messages));
        }
        if (!isset($body['data']) || !is_array($body['data'])) {
            throw new MensamaxException('Mensamax GraphQL response without data');
        }

        return $body['data'];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function post(string $path, array $json): array
    {
        try {
            $response = $this->http->request('POST', $path, ['json' => $json]);
        } catch (GuzzleException $e) {
            throw new MensamaxException('Mensamax request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MensamaxException(sprintf('Mensamax returned HTTP %d with non-JSON body: %s', $status, mb_substr($raw, 0, 200)));
        }
        if ($status >= 400 && empty($decoded['errors'])) {
            throw new MensamaxException(sprintf('Mensamax returned HTTP %d: %s', $status, mb_substr($raw, 0, 200)));
        }

        return $decoded;
    }
}
