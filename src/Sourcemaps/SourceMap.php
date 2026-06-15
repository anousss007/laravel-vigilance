<?php

namespace Vigilance\Sourcemaps;

/**
 * A minimal Source Map v3 decoder: parses the "mappings" VLQ stream and resolves
 * a generated (line, column) back to its original source file / line / symbol.
 * Enough to turn a minified RUM stack frame into a readable one — no external
 * dependency, pure PHP.
 */
class SourceMap
{
    private const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var list<string> */
    protected array $sources;

    /** @var list<string> */
    protected array $names;

    /**
     * Per generated line (0-based): a list of segments
     * [genCol, sourceIndex, sourceLine, sourceCol, nameIndex|null], in genCol order.
     *
     * @var array<int, list<array{int, int, int, int, int|null}>>
     */
    protected array $lines = [];

    /**
     * @param  array<string, mixed>  $map
     */
    public function __construct(array $map)
    {
        $this->sources = array_values(array_map('strval', (array) ($map['sources'] ?? [])));
        $this->names = array_values(array_map('strval', (array) ($map['names'] ?? [])));
        $this->parse((string) ($map['mappings'] ?? ''));
    }

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? new self($decoded) : null;
    }

    /**
     * Resolve a generated position (1-based line and column, as they appear in a
     * browser stack frame) to its original location.
     *
     * @return array{source: string, line: int, column: int, name: ?string}|null
     */
    public function originalPositionFor(int $line, int $column): ?array
    {
        $genLine = $line - 1;
        $genCol = max(0, $column - 1);

        if ($genLine < 0 || ! isset($this->lines[$genLine])) {
            return null;
        }

        // The matching segment is the last one whose generated column is <= ours.
        $match = null;
        foreach ($this->lines[$genLine] as $segment) {
            if ($segment[0] <= $genCol) {
                $match = $segment;
            } else {
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        [, $sourceIndex, $sourceLine, $sourceCol, $nameIndex] = $match;

        return [
            'source' => $this->sources[$sourceIndex] ?? '?',
            'line' => $sourceLine + 1,
            'column' => $sourceCol + 1,
            'name' => $nameIndex !== null ? ($this->names[$nameIndex] ?? null) : null,
        ];
    }

    protected function parse(string $mappings): void
    {
        if ($mappings === '') {
            return;
        }

        $sourceIndex = 0;
        $sourceLine = 0;
        $sourceCol = 0;
        $nameIndex = 0;

        foreach (explode(';', $mappings) as $lineNo => $lineMappings) {
            $genCol = 0;

            if ($lineMappings === '') {
                continue;
            }

            foreach (explode(',', $lineMappings) as $segment) {
                if ($segment === '') {
                    continue;
                }

                $fields = $this->decodeVlq($segment);

                if ($fields === []) {
                    continue;
                }

                $genCol += $fields[0];

                if (count($fields) >= 4) {
                    $sourceIndex += $fields[1];
                    $sourceLine += $fields[2];
                    $sourceCol += $fields[3];

                    $name = null;
                    if (count($fields) >= 5) {
                        $nameIndex += $fields[4];
                        $name = $nameIndex;
                    }

                    $this->lines[$lineNo][] = [$genCol, $sourceIndex, $sourceLine, $sourceCol, $name];
                }
            }
        }
    }

    /**
     * Decode a Base64 VLQ segment into its list of signed integers.
     *
     * @return list<int>
     */
    protected function decodeVlq(string $segment): array
    {
        $values = [];
        $value = 0;
        $shift = 0;

        foreach (str_split($segment) as $char) {
            $digit = strpos(self::BASE64, $char);

            if ($digit === false) {
                return $values;
            }

            $continuation = $digit & 32;
            $digit &= 31;
            $value += $digit << $shift;

            if ($continuation) {
                $shift += 5;
            } else {
                $negate = $value & 1;
                $value >>= 1;
                $values[] = $negate ? -$value : $value;
                $value = 0;
                $shift = 0;
            }
        }

        return $values;
    }
}
