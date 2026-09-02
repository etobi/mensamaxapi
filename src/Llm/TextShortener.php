<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

interface TextShortener
{
    /**
     * @param list<string> $texts full dish descriptions
     * @return array<string, string> full text => short text; texts that could not be shortened are omitted
     *
     * @throws LlmException
     */
    public function shorten(array $texts): array;
}
