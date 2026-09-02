<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

final class NullShortener implements TextShortener
{
    public function shorten(array $texts): array
    {
        return [];
    }
}
