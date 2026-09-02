<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

/**
 * Shared prompt handling: numbered list in, JSON object out.
 */
abstract class AbstractLlmShortener implements TextShortener
{
    public function __construct(
        protected readonly int $maxLength = 30,
    ) {
    }

    public function shorten(array $texts): array
    {
        $texts = array_values(array_unique(array_filter($texts, fn (string $t) => trim($t) !== '')));
        if ($texts === []) {
            return [];
        }

        $items = [];
        foreach ($texts as $index => $text) {
            $items[(string) ($index + 1)] = $text;
        }

        $answer = $this->complete($this->systemPrompt(), $this->userPrompt($items));
        $decoded = self::decodeJsonObject($answer);

        $result = [];
        foreach ($items as $key => $text) {
            $short = $decoded[$key] ?? null;
            if (is_string($short) && trim($short) !== '') {
                $result[$text] = trim($short);
            }
        }

        return $result;
    }

    /**
     * @throws LlmException
     */
    abstract protected function complete(string $system, string $user): string;

    protected function systemPrompt(): string
    {
        return 'Du fasst Gerichte einer Schul-Mensa kindgerecht zusammen. '
            . 'Verwende einfache, alltägliche Wörter. Ersetze Fachbegriffe wie "Ratatouille" durch einfache '
            . 'Beschreibungen wie "Gemüsepfanne". Lass Zutatenlisten in Klammern und Herkunftsangaben weg. '
            . 'Antworte ausschließlich mit einem JSON-Objekt, ohne Erklärung und ohne Markdown.';
    }

    /**
     * @param array<string, string> $items
     */
    protected function userPrompt(array $items): string
    {
        return sprintf(
            "Kürze jedes Gericht auf maximal %d Zeichen. Antworte mit einem JSON-Objekt, das dieselben Schlüssel hat "
            . "und als Werte die Kurzfassungen.\n\n%s",
            $this->maxLength,
            json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodeJsonObject(string $answer): array
    {
        $cleaned = trim($answer);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new LlmException('LLM answer contains no JSON object: ' . mb_substr($answer, 0, 200));
        }
        $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            throw new LlmException('LLM answer is not valid JSON: ' . mb_substr($answer, 0, 200));
        }

        return $decoded;
    }
}
