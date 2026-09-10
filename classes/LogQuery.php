<?php

/**
 * @file classes/LogQuery.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class LogQuery
 *
 * @brief What the log listing was asked for: one page, in an order, within optional date and
 *        duration bounds. Every value arrives from the client, so every value is clamped here.
 *
 * needsEveryFile() is the one that costs money: a duration cannot be known without opening the
 * file, so ordering or filtering by it has to read the whole matching set rather than one page.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use Illuminate\Http\Request;

final class LogQuery
{
    public const SORT_STARTED = 'started';
    public const SORT_DURATION = 'duration';

    /** @param ?string $from Inclusive lower bound as Ymd; $to is the inclusive upper bound. */
    public function __construct(
        public readonly int $limit = LogRepository::DEFAULT_LIMIT,
        public readonly int $offset = 0,
        public readonly string $sort = self::SORT_STARTED,
        public readonly bool $descending = true,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $minDuration = null,
        public readonly ?int $maxDuration = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $sort = (string) $request->query('sort', self::SORT_STARTED);
        $from = static::day($request->query('from'));
        $to = static::day($request->query('to'));
        $min = static::seconds($request->query('minDuration'));
        $max = static::seconds($request->query('maxDuration'));

        return new self(
            limit: max(1, min((int) $request->query('limit', (string) LogRepository::DEFAULT_LIMIT), LogRepository::MAX_LIMIT)),
            offset: max(0, (int) $request->query('offset', '0')),
            sort: $sort === self::SORT_DURATION ? self::SORT_DURATION : self::SORT_STARTED,
            descending: $request->query('direction', 'descending') !== 'ascending',
            // Swapped rather than rejected: a reversed range is a slip, not an attack.
            from: $from !== null && $to !== null && $from > $to ? $to : $from,
            to: $from !== null && $to !== null && $from > $to ? $from : $to,
            minDuration: $min !== null && $max !== null && $min > $max ? $max : $min,
            maxDuration: $min !== null && $max !== null && $min > $max ? $min : $max,
        );
    }

    /**
     * Must the whole matching set be opened, rather than just enough of it to fill the page?
     */
    public function needsEveryFile(): bool
    {
        return $this->sort === self::SORT_DURATION
            || $this->minDuration !== null
            || $this->maxDuration !== null;
    }

    public function hasFilters(): bool
    {
        return $this->from !== null
            || $this->to !== null
            || $this->minDuration !== null
            || $this->maxDuration !== null;
    }

    /**
     * Is a log file's day inside the requested range? The day comes from the filename, which core
     * stamps at construction -- so it is the day the run started, which is what a date filter means.
     */
    public function keepsDay(string $day): bool
    {
        return ($this->from === null || $day >= $this->from)
            && ($this->to === null || $day <= $this->to);
    }

    /**
     * A run whose duration could not be read cannot satisfy a duration bound, so it is excluded
     * rather than passed through.
     */
    public function keepsDuration(?int $duration): bool
    {
        if ($this->minDuration === null && $this->maxDuration === null) {
            return true;
        }

        return $duration !== null
            && ($this->minDuration === null || $duration >= $this->minDuration)
            && ($this->maxDuration === null || $duration <= $this->maxDuration);
    }

    /**
     * Echoed back to the client so the pager and the filter form follow what was applied rather
     * than what was asked for.
     */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'offset' => $this->offset,
            'sort' => $this->sort,
            'direction' => $this->descending ? 'descending' : 'ascending',
            'from' => static::isoDay($this->from),
            'to' => static::isoDay($this->to),
            'minDuration' => $this->minDuration,
            'maxDuration' => $this->maxDuration,
        ];
    }

    /** A `Y-m-d` from the client as the `Ymd` the filenames use, or null when it is not a date. */
    private static function day(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            ? $m[1] . $m[2] . $m[3]
            : null;
    }

    private static function isoDay(?string $day): ?string
    {
        return $day === null
            ? null
            : substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2);
    }

    private static function seconds(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' || !is_numeric($value)
            ? null
            : max(0, (int) $value);
    }
}
