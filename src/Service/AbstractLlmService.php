<?php

namespace Etobi\MensamaxApi\Service;

abstract class AbstractLlmService implements LlmServiceInterface
{
    protected const SYSTEM_PROMPT = 'Du fasst Schul-Mensa-Bestellungen kinderfreundlich zusammen. '
        . 'Verwende einfache, alltägliche Wörter. Ersetze Fachbegriffe wie "Ratatouille" durch einfache Beschreibungen wie "Gemüsepfanne".';

    protected const SUMMARY_PROMPT = "Fasse die folgenden Mensa-Bestellungen zusammen.\n"
        . "Format: Genau eine Zeile pro Tag, max. 40 Zeichen pro Zeile.\n"
        . "Schema: \"Mo 12.03. Kurzbeschreibung\"\n"
        . "Tage ohne Bestellung: \"Di 13.03. Keine Bestellung\"\n"
        . "Nur die Zeilen ausgeben, kein weiterer Text.\n\n";

    protected const SHORT_DESCRIPTION_PROMPT = "Kürze jede Mensa-Bestellung auf max. 30 Zeichen.\n"
        . "Antwort: Nur ein JSON-Objekt mit denselben Keys und den Kurzbeschreibungen als Values.\n\n";

    public function getSummary(string $jsonData): string
    {
        return $this->request(self::SUMMARY_PROMPT . $jsonData);
    }

    public function getShortDescriptions(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $list = json_encode($items, JSON_UNESCAPED_UNICODE);
        $response = $this->request(self::SHORT_DESCRIPTION_PROMPT . $list);

        // Markdown-Codeblock entfernen falls vorhanden
        $cleaned = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        $decoded = json_decode($cleaned, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $items;
    }

    abstract protected function request(string $prompt): mixed;
}
