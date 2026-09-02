<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Llm;

/**
 * Remembers short texts in a JSON file so the LLM is only asked for dishes it has not seen yet.
 */
final class CachedShortener implements TextShortener
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly TextShortener $inner,
        private readonly string $cacheFile,
    ) {
    }

    public function shorten(array $texts): array
    {
        $cache = $this->load();
        $result = [];
        $missing = [];
        foreach (array_unique($texts) as $text) {
            if (isset($cache[$text])) {
                $result[$text] = $cache[$text];
            } else {
                $missing[] = $text;
            }
        }

        if ($missing !== []) {
            $fresh = $this->inner->shorten($missing);
            if ($fresh !== []) {
                $this->cache = $cache + $fresh;
                $this->save();
                $result += $fresh;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        if (is_file($this->cacheFile)) {
            $decoded = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($decoded)) {
                $this->cache = array_filter($decoded, fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
            }
        }

        return $this->cache;
    }

    private function save(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->cacheFile . '.tmp';
        file_put_contents($tmp, json_encode($this->cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($tmp, $this->cacheFile);
    }
}
