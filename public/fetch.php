<?php

use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$client = new GuzzleHttp\Client(
    [
        'base_uri' => $_ENV['MENSAMAXAPI_baseurl'],
        'allow_redirects' => true,
        'cookies' => true,
    ]
);

$response = $client->post(
    '/graphql/auth/login',
    [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'projekt' => $_ENV['MENSAMAXAPI_project'],
            'benutzername' => $_ENV['MENSAMAXAPI_username'],
            'passwort' => $_ENV['MENSAMAXAPI_password'],
        ]
    ]
);

$response = $client->post(
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

$today = new \DateTime('now');
$result = [];
$result['updated'] = $today->format('c');
$result['kontostand'] = [
    'aktuell' => $json['data']['meinKontostand']['gesamtKontostandAktuell'],
    'zukunft' => $json['data']['meinKontostand']['gesamtKontostandZukunft'],
];


$result['bestellungen'] = [];

$week = (int)($today->format('W'));
foreach (range($week, $week + 4) as $weekNumber) {
    $response = $client->post(
        '/graphql/',
        [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'operationName' => 'Bestelluebersicht',
                'variables' => [
                    'kw' => $weekNumber,
                    'year' => (int)$today->format('Y'),
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

    foreach ($json['data']['bestelluebersicht'] ?? [] as $item) {
        if (empty($item['date'])) {
            continue;
        }
        $date = \DateTime::createFromFormat(
            DateTimeInterface::RFC3339_EXTENDED,
            $item['date']
        );
        if (!$date) {
            continue;
        }
        if (count($item['bestellungen']) > 0) {
            foreach (['vorspeisen', 'hauptspeisen', 'nachspeisen'] as $key) {
                $result['bestellungen'][$date->format('Ymd')][] = trim(
                    ($item['bestellungen'][0]['menue'][$key][0]['bezeichnung'] ?? '')
                    . ' '
                    . ($item['bestellungen'][0]['menue'][$key][0]['beschreibung'] ?? '')
                );
            }
        }
    }
}

$result['today'] = $result['bestellungen'][$today->format('Ymd')] ?? '';
$result['tomorrow'] = $result['bestellungen'][(clone $today)->add(new \DateInterval('P1D'))->format('Ymd')] ?? '';

file_put_contents('public/get.json', json_encode($result));
