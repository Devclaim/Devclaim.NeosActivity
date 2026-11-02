<?php

declare(strict_types=1);

namespace Devclaim\NeosActivity\Domain;

/**
 * The position of the last entry of a page: the next page has the entries after it. The entries of all sources are in
 * one order, newest first: by time, then by source (ascending), then by key within the source (descending). Times
 * alone would not do: a publish writes many entries in the same second.
 */
final readonly class ActivityCursor
{
    private const SEPARATOR = '|';

    public function __construct(
        public \DateTimeImmutable $time,
        public string $source,
        public string $key,
    ) {
    }

    public static function afterEntry(ActivityEntry $entry): self
    {
        return new self($entry->time, $entry->source, $entry->key);
    }

    public static function tryFromString(string $value): ?self
    {
        $parts = explode(self::SEPARATOR, $value, 3);
        if (count($parts) !== 3) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        return $time === false ? null : new self($time, $parts[1], $parts[2]);
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, [$this->time->format(\DateTimeInterface::ATOM), $this->source, $this->key]);
    }

    /**
     * Whether the entries of a source at exactly the time of the cursor come after it: those of a later source do
     * (all of them), those of the same source only below the key, those of an earlier source none.
     *
     * @return 'all'|'belowKey'|'none'
     */
    public function sameTimeFor(string $source): string
    {
        return match (true) {
            $source > $this->source => 'all',
            $source === $this->source => 'belowKey',
            default => 'none',
        };
    }

    /**
     * Whether an entry comes after the cursor in the order of the log
     */
    public function precedes(ActivityEntry $entry): bool
    {
        if ($entry->time != $this->time) {
            return $entry->time < $this->time;
        }
        return match ($this->sameTimeFor($entry->source)) {
            'all' => true,
            'belowKey' => strcmp($entry->key, $this->key) < 0,
            'none' => false,
        };
    }
}
