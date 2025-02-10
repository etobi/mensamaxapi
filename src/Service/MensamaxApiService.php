<?php

namespace Etobi\MensamaxApi\Service;

use GuzzleHttp\Client;

class MensamaxApiService
{

    protected Client $client;

    public function __construct(
        protected readonly array $config = []
    ) {
    }

    public function fetch(int $weeks): array
    {
        $this->initializeClient();
        $this->login();
        $kontostand = $this->getKontostand();

        $today = new \DateTime('now');
        $week = (int)($today->format('W'));
        $bestellungen = [];
        foreach (range($week, $week + $weeks) as $queryWeekNumber) {
            $bestellungenOfWeek = $this->getBestellungen(
                (int)$today->format('Y'),
                (int)$queryWeekNumber
            );
            if (count($bestellungenOfWeek) > 0) {
                $bestellungen = $bestellungen + $bestellungenOfWeek;
            }
        }
        $helper = $this->getHelper($bestellungen);

        $next = [];
        foreach ($bestellungen as $bestellung) {
            $next[] = $bestellung;
        }

        $bestellungen['next'] = $next;

        $bestellungen['today'] = $bestellungen[$today->format('Ymd')] ?? [];
        $bestellungen['tomorrow'] = $bestellungen[(clone $today)->add(new \DateInterval('P1D'))->format('Ymd')] ?? [];
        $bestellungen['monday'] = $bestellungen[(new \DateTime('next monday'))->format('Ymd')] ?? [];
        $bestellungen['tuesday'] = $bestellungen[(new \DateTime('next tuesday'))->format('Ymd')] ?? [];
        $bestellungen['wednesday'] = $bestellungen[(new \DateTime('next wednesday'))->format('Ymd')] ?? [];
        $bestellungen['thursday'] = $bestellungen[(new \DateTime('next thursday'))->format('Ymd')] ?? [];
        $bestellungen['friday'] = $bestellungen[(new \DateTime('next friday'))->format('Ymd')] ?? [];

        return [
            'kontostand' => $kontostand,
            'bestellungen' => $bestellungen,
            'helper' => $helper,
            'meta' => [
                'updated' => $today->format('c')
            ]
        ];
    }

    protected function login()
    {
        $response = $this->client->post(
            '/graphql/auth/login',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'projekt' => $this->config['project'],
                    'benutzername' => $this->config['username'],
                    'passwort' => $this->config['password'],
                ]
            ]
        );
    }

    protected function initializeClient(): void
    {
        $this->client = new Client(
            [
                'base_uri' => $this->config['baseurl'],
                'allow_redirects' => true,
                'cookies' => true,
            ]
        );
    }

    protected function getKontostand()
    {
        $response = $this->client->post(
            '/graphql/',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'operationName' => 'kontostand',
                    'query' => 'query kontostand { meinKontostand { gesamtKontostandAktuell gesamtKontostandZukunft } }'
                ]
            ]
        );

        $json = json_decode(
            (string)$response->getBody(),
            true
        );

        return [
            'aktuell' => $json['data']['meinKontostand']['gesamtKontostandAktuell'],
            'zukunft' => $json['data']['meinKontostand']['gesamtKontostandZukunft'],
        ];
    }

    protected function getBestellungen(int $year, int $week): array
    {
        $today = new \DateTime('yesterday');
        $response = $this->client->post(
            '/graphql/',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'operationName' => 'Bestelluebersicht',
                    'variables' => [
                        'kw' => $week,
                        'year' => $year,
                    ],
                    'query' => 'query
            Bestelluebersicht($kw: Int!, $year: Int!) {
                bestelluebersicht(kw: $kw, year: $year) {
                    date
                    bestellungen {
                        menue {
                            vorspeisen {
                                ...Speise
                            }
                            hauptspeisen {
                                ...Speise
                            }
                            nachspeisen {
                                ...Speise
                            }
                        }
                    }
                }
            }
            fragment Speise on TblxxSpeise {
                bezeichnung
                beschreibung
            }
            '
                ]
            ]
        );

        $json = json_decode(
            (string)$response->getBody(),
            true
        );

        $bestellungen = [];
        foreach ($json['data']['bestelluebersicht'] ?? [] as $item) {
            if (empty($item['date'])) {
                continue;
            }
            $date = \DateTime::createFromFormat(
                \DateTimeInterface::RFC3339_EXTENDED,
                $item['date']
            );
            if (
                !$date
                || $date < $today
            ) {
                continue;
            }
            if (count($item['bestellungen']) > 0) {
                $bestellungForDay = [
                    'day' => $date->format('Y-m-d'),
                    'week' => (int)$date->format('W'),
                    'bestellungen' => []
                ];
                $chatgptService = new ChatgptService($this->config['openai_apikey']);
                foreach (['vorspeisen', 'hauptspeisen', 'nachspeisen'] as $key) {
                    $bestellungForDay['bestellungen'][$key] = trim(
                        ($item['bestellungen'][0]['menue'][$key][0]['bezeichnung'] ?? '')
                        . ' '
                        . ($item['bestellungen'][0]['menue'][$key][0]['beschreibung'] ?? '')
                    );
                    $shortDescription = (string)$bestellungForDay['bestellungen'][$key];
                    if (
                        $bestellungForDay['bestellungen'][$key]
                        && strlen($bestellungForDay['bestellungen'][$key]) > 10
                    ) {
                        $shortDescription = $chatgptService->getShortDescription(
                            (string)$bestellungForDay['bestellungen'][$key]
                        );
                    }
                    $bestellungForDay['shortDescription'][$key] = $shortDescription;
                }
                $bestellungen[$date->format('Ymd')] = $bestellungForDay;
            }
        }
        return $bestellungen;
    }

    protected function getHelper(array $bestellungen)
    {
        $counter = [];
        foreach ($bestellungen as $bestellung) {
            if (empty($bestellung)) {
                continue;
            }
            $counter[(int)$bestellung['week']] = 1 + ($counter[$bestellung['week']] ?? 0);
        }

        $today = new \DateTime('now');
        $counter['thisweek'] = $counter[(int)$today->format('W')] ?? 0;
        $counter['nextweek'] = $counter[(int)$today->format('W') + 1] ?? 0;
        $counter['intwoweeks'] = $counter[(int)$today->format('W') + 2] ?? 0;
        $counter['inthreeweeks'] = $counter[(int)$today->format('W') + 3] ?? 0;
        $counter['infourweeks'] = $counter[(int)$today->format('W') + 4] ?? 0;
        return [
            'countByWeek' => $counter,
        ];
    }
}