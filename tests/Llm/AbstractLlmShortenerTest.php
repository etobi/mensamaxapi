<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\Llm;

use Etobi\Mensamax\Llm\AbstractLlmShortener;
use Etobi\Mensamax\Llm\LlmException;
use PHPUnit\Framework\TestCase;

final class AbstractLlmShortenerTest extends TestCase
{
    private function shortener(string $answer): AbstractLlmShortener
    {
        return new class($answer) extends AbstractLlmShortener {
            public string $lastUserPrompt = '';

            public function __construct(private string $answer)
            {
                parent::__construct(30);
            }

            protected function complete(string $system, string $user): string
            {
                $this->lastUserPrompt = $user;

                return $this->answer;
            }
        };
    }

    public function testMapsNumberedAnswerBackToTexts(): void
    {
        $shortener = $this->shortener("```json\n{\"1\": \"Nudeln\", \"2\": \"Fisch\"}\n```");

        $result = $shortener->shorten(['Gabelspaghetti mit Tomatensauce', 'Fischstäbchen mit Kartoffeln']);

        self::assertSame([
            'Gabelspaghetti mit Tomatensauce' => 'Nudeln',
            'Fischstäbchen mit Kartoffeln' => 'Fisch',
        ], $result);
        self::assertStringContainsString('"1": "Gabelspaghetti mit Tomatensauce"', $shortener->lastUserPrompt);
    }

    public function testMissingKeysAreOmitted(): void
    {
        $result = $this->shortener('{"2": "Fisch"}')->shorten(['a', 'b']);

        self::assertSame(['b' => 'Fisch'], $result);
    }

    public function testInvalidAnswerThrows(): void
    {
        $this->expectException(LlmException::class);
        $this->shortener('Sorry, I cannot help.')->shorten(['a']);
    }

    public function testEmptyInputSkipsLlm(): void
    {
        self::assertSame([], $this->shortener('never')->shorten(['', ' ']));
    }
}
