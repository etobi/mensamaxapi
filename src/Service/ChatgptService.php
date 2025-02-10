<?php

namespace Etobi\MensamaxApi\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ChatgptService
{

    public function __construct(
        protected string $apiKey = ''
    ) {
    }

    public function getSummary(string $jsonData): string
    {
        $prompt = "Erstelle eine kompakte Anzeige, die maximal 4 Zeilen mit je ca. 40 Zeichen enthält. "
            . "Jede Zeile soll den abgekürzten Wochentag, das Datum (Tag und Monat) und eine kurze, für Kinder verständliche Zusammenfassung der bestellten Hauptspeise anzeigen. "
            . "Verwende einfache, alltägliche Wörter und vermeide komplizierte Begriffe wie 'Ratatouille'. "
            . "Falls an einem Dienstag oder Donnerstag keine Bestellung vorliegt, soll in der entsprechenden Zeile 'Keine Bestellung vorhanden' stehen. "
            . "Hier ist das JSON mit den Bestellungen:\n\n$jsonData";

        $summary = $this->request($prompt);

        return $summary;
    }

    public function getShortDescription(string $string)
    {
        $prompt = 'Erstelle für die folgende Mensa-Bestellung eine kurze Zusammenfassung mit 
        maximal 30 Zeichen. 
        Hier ist die Bestellung:

        ' . $string;

        return $this->request($prompt);
    }

    /**
     * @param string $prompt
     * @return mixed|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function request(string $prompt): mixed
    {
        $apiUrl = "https://api.openai.com/v1/chat/completions";

        $client = new Client();

        try {
            $response = $client->post($apiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => [
                    'model' => 'gpt-4',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Du bist ein hilfreicher Assistent.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 200,
                    'temperature' => 0.5
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            $summary = $result["choices"][0]["message"]["content"] ?? "Fehler bei der API-Antwort";
        } catch (RequestException $e) {
            $summary = "API-Fehler: " . $e->getMessage();
        }
        return $summary;
    }
}