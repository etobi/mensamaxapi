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
        $bestellungen = [];
        $currentDate = clone $today;
        for ($i = 0; $i <= $weeks; $i++) {
            $bestellungenOfWeek = $this->getBestellungen(
                (int)$currentDate->format('o'),
                (int)$currentDate->format('W')
            );
            if (count($bestellungenOfWeek) > 0) {
                $bestellungen = $bestellungen + $bestellungenOfWeek;
            }
            $currentDate->modify('+1 week');
        }
        $helper = $this->getHelper($bestellungen);

        $shortcuts = [
            'next' => array_values($bestellungen),
            'today' => $bestellungen[$today->format('Ymd')] ?? [],
            'tomorrow' => $bestellungen[(clone $today)->add(new \DateInterval('P1D'))->format('Ymd')] ?? [],
            'monday' => $bestellungen[(new \DateTime('next monday'))->format('Ymd')] ?? [],
            'tuesday' => $bestellungen[(new \DateTime('next tuesday'))->format('Ymd')] ?? [],
            'wednesday' => $bestellungen[(new \DateTime('next wednesday'))->format('Ymd')] ?? [],
            'thursday' => $bestellungen[(new \DateTime('next thursday'))->format('Ymd')] ?? [],
            'friday' => $bestellungen[(new \DateTime('next friday'))->format('Ymd')] ?? [],
        ];

        return [
            'kontostand' => $kontostand,
            'bestellungen' => $bestellungen,
            'shortcuts' => $shortcuts,
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
        $cutoffDate = new \DateTime('yesterday');
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
                || $date < $cutoffDate
            ) {
                continue;
            }
            if (count($item['bestellungen']) > 0) {
                $bestellungForDay = [
                    'day' => $date->format('Y-m-d'),
                    'week' => (int)$date->format('W'),
                    'bestellungen' => []
                ];
                foreach (['vorspeisen', 'hauptspeisen', 'nachspeisen'] as $key) {
                    $bestellungForDay['bestellungen'][$key] = trim(
                        ($item['bestellungen'][0]['menue'][$key][0]['bezeichnung'] ?? '')
                        . ' '
                        . ($item['bestellungen'][0]['menue'][$key][0]['beschreibung'] ?? '')
                    );
                }
                $bestellungen[$date->format('Ymd')] = $bestellungForDay;
            }
        }

        // Alle Beschreibungen sammeln und in einem einzigen API-Call kürzen
        $descriptionsToShorten = [];
        foreach ($bestellungen as $dateKey => $bestellung) {
            foreach (['vorspeisen', 'hauptspeisen', 'nachspeisen'] as $key) {
                $text = $bestellung['bestellungen'][$key] ?? '';
                if ($text && strlen($text) > 10) {
                    $descriptionsToShorten[$dateKey . '_' . $key] = $text;
                }
            }
        }

        $chatgptService = new ChatgptService($this->config['openai_apikey']);
        $shortDescriptions = $chatgptService->getShortDescriptions($descriptionsToShorten);

        foreach ($bestellungen as $dateKey => &$bestellung) {
            foreach (['vorspeisen', 'hauptspeisen', 'nachspeisen'] as $key) {
                $lookupKey = $dateKey . '_' . $key;
                $bestellung['shortDescription'][$key] = $shortDescriptions[$lookupKey]
                    ?? ($bestellung['bestellungen'][$key] ?? '');
            }
        }
        unset($bestellung);

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

        $date = new \DateTime('now');
        $counter['thisweek'] = $counter[(int)$date->format('W')] ?? 0;
        $date->modify('+1 week');
        $counter['nextweek'] = $counter[(int)$date->format('W')] ?? 0;
        $date->modify('+1 week');
        $counter['intwoweeks'] = $counter[(int)$date->format('W')] ?? 0;
        $date->modify('+1 week');
        $counter['inthreeweeks'] = $counter[(int)$date->format('W')] ?? 0;
        $date->modify('+1 week');
        $counter['infourweeks'] = $counter[(int)$date->format('W')] ?? 0;
        return [
            'countByWeek' => $counter,
        ];
    }
}