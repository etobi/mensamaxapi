<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\Llm;

use Etobi\Mensamax\Llm\CachedShortener;
use Etobi\Mensamax\Llm\TextShortener;
use PHPUnit\Framework\TestCase;

final class CachedShortenerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/mensamax-test-' . bin2hex(random_bytes(4)) . '/cache.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
            rmdir(dirname($this->file));
        }
    }

    public function testAsksInnerOnlyForUnknownTextsAndPersists(): void
    {
        $calls = [];
        $inner = new class($calls) implements TextShortener {
            public function __construct(private array &$calls)
            {
            }

            public function shorten(array $texts): array
            {
                $this->calls[] = $texts;

                return array_combine($texts, array_map(fn ($t) => 'short:' . $t, $texts));
            }
        };

        $shortener = new CachedShortener($inner, $this->file);
        self::assertSame(['a' => 'short:a', 'b' => 'short:b'], $shortener->shorten(['a', 'b', 'a']));
        self::assertSame(['b' => 'short:b', 'c' => 'short:c'], $shortener->shorten(['b', 'c']));
        self::assertSame([['a', 'b'], ['c']], $calls);

        $fresh = new CachedShortener($inner, $this->file);
        self::assertSame(['c' => 'short:c'], $fresh->shorten(['c']));
        self::assertCount(2, $calls, 'cache file served the third call');
    }
}
