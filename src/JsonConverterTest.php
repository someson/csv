<?php

/**
 * League.Csv (https://csv.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Csv;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const JSON_FORCE_OBJECT;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

#[Group('converter')]
final class JsonConverterTest extends TestCase
{
    #[Test]
    public function it_will_convert_a_tabular_data_reader_into_a_json(): void
    {
        $csv = Reader::createFromPath(__DIR__.'/../test_files/prenoms.csv');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        CharsetConverter::addTo($csv, 'iso-8859-15', 'utf-8');
        $converter = JsonConverter::create()
            ->addFlags(JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES, JSON_FORCE_OBJECT)
            ->removeFlags(JSON_FORCE_OBJECT)
            ->depth(24);

        $records = Statement::create()->offset(3)->limit(5)->process($csv);

        $tmp = tmpfile();
        $converter->save($records, $tmp);
        rewind($tmp);

        $nativeJson = json_encode($records, $converter->flags, $converter->depth);

        self::assertSame(stream_get_contents($tmp), $nativeJson);
        self::assertSame($converter->encode($records), $nativeJson);
        self::assertSame(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT, $converter->flags);
        self::assertSame(24, $converter->depth);
        self::assertSame('    ', $converter->indentation);
        self::assertTrue($converter->isPrettyPrint);
        self::assertFalse($converter->isForceObject);
    }

    #[Test]
    public function it_has_default_values(): void
    {
        $converter = JsonConverter::create();

        self::assertSame(
            $converter,
            $converter
                ->indentSize(4)
                ->addFlags(0)
                ->removeFlags(0)
                ->depth(512)
        );
    }

    #[Test]
    public function it_fails_if_the_depth_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonConverter::create()->depth(-1); /* @phpstan-ignore-line */
    }

    #[Test]
    public function it_fails_if_the_indentation_size_is_invalud(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonConverter::create()->indentSize(0); /* @phpstan-ignore-line */
    }

    #[Test]
    public function it_only_uses_indentation_if_pretty_print_is_present(): void
    {
        self::assertSame(
            json_encode([['foo' => 'bar']]),
            JsonConverter::create()->indentSize(23)->encode([['foo' => 'bar']]),
        );
    }

    #[Test]
    public function it_returns_a_null_object_if_the_collection_is_empty(): void
    {
        $converter = JsonConverter::create();

        self::assertSame('[]', $converter->encode([]));
        self::assertSame('{}', $converter->addFlags(JSON_FORCE_OBJECT)->encode([]));
    }

    #[Test]
    public function it_can_manipulate_the_record_prior_to_json_encode(): void
    {
        $converter = JsonConverter::create()
            ->formatter(fn (array $value) => array_map(strtoupper(...), $value));

        self::assertSame('[{"foo":"BAR"}]', $converter->encode([['foo' => 'bar']]));
    }
}
