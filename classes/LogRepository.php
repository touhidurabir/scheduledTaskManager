<?php

/**
 * @file classes/LogRepository.php
 *
 * Copyright (c) 2026 Touhidur Rahman
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LogRepository
 *
 * @brief Reads the execution log directory, which one everyMinute task grows by 1,440 files a
 *        day. Never sorted or held whole; cost comes from stat() calls, not entries.
 *
 * Counts and per-task days are read from the filenames (`{ShortClassName}-{uniqid}-{Ymd}.log`);
 * only files that could answer the question get stat()ed. Ordering still comes from mtime, since
 * the uniqid is taken at construction and the file written at completion -- so names can run out
 * of order, as seen on DOAJInfoSender.
 */

namespace APP\plugins\generic\scheduledTaskManager\classes;

use Carbon\Carbon;
use PKP\config\Config;
use PKP\file\PrivateFileManager;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class LogRepository
{
    /** Default page size for a single task's log files. */
    public const DEFAULT_LIMIT = 20;

    /** Hard ceiling on a page size. */
    public const MAX_LIMIT = 50;

    /**
     * Ceiling on how many files one request may open: the depth an ordinary page may reach, and
     * the size of the scan a duration question is allowed to make. Past it the answer says so.
     */
    public const MAX_WINDOW = 5000;

    /**
     * How much of a log file is read to describe a run. The first line is all that is needed for
     * the start time; entries are only counted when the whole file fits inside this.
     */
    public const MAX_READ = 262144;

    /** How much of a failed run's log is read back for its reason: the end, where it is. */
    public const RUN_TAIL = 65536;

    /** Cached result of the directory-wide scan. */
    private ?array $scan = null;

    /** Resolved log directory, cached because every accessor needs it. */
    private ?string $directory = null;

    /**
     * @param ?string $directory Overrides the installation's log directory. Only for tests and
     *                           benchmarks; production callers leave it null.
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory;
    }

    /**
     * Absolute path of the log directory. It is created on demand by ScheduledTask, so it may
     * legitimately not exist yet.
     */
    public function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $fileManager = new PrivateFileManager();

        return $this->directory = (realpath($fileManager->getBasePath()) ?: $fileManager->getBasePath())
            . '/' . ScheduledTaskHelper::SCHEDULED_TASK_EXECUTION_LOG_DIR;
    }

    public function exists(): bool
    {
        return is_dir($this->directory());
    }

    /**
     * One name-only pass over the directory, serving the status card and every row's log count.
     *
     * Nothing is stat()ed, so the cost is one readdir however many files there are. Per task it
     * keeps the names from that task's newest day -- the only files that can hold its last run.
     *
     * @return array{total: int, byTask: array<string, array{count: int, day: string, names: array<int, string>}>}
     */
    public function scan(): array
    {
        if ($this->scan !== null) {
            return $this->scan;
        }

        $result = ['total' => 0, 'byTask' => []];

        if (!$this->exists()) {
            return $this->scan = $result;
        }

        $handle = @opendir($this->directory());

        if ($handle === false) {
            error_log('ScheduledTaskManager could not read the log directory: ' . $this->directory());

            return $this->scan = $result;
        }

        try {
            while (($name = readdir($handle)) !== false) {
                $parsed = static::parse($name);

                if ($parsed === null) {
                    continue;
                }

                [$task, $day] = $parsed;
                $result['total']++;

                $existing = $result['byTask'][$task] ?? ['count' => 0, 'day' => '', 'names' => []];
                $existing['count']++;

                // Only the newest day's names are worth keeping: an older file cannot be the
                // task's most recent run, and keeping every name would put the whole directory
                // back in memory.
                if ($day > $existing['day']) {
                    $existing['day'] = $day;
                    $existing['names'] = [$name];
                } elseif ($day === $existing['day']) {
                    $existing['names'][] = $name;
                }

                $result['byTask'][$task] = $existing;
            }
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not read the log directory: ' . $exception->getMessage());
        } finally {
            closedir($handle);
        }

        return $this->scan = $result;
    }

    /**
     * Most recent log timestamp for a task.
     */
    public function lastRunFor(string $taskName): ?Carbon
    {
        $newest = $this->newestFileFor($taskName);

        return $newest ? Carbon::createFromTimestamp($newest['modified']) : null;
    }

    /**
     * The newest run in a task's log files: when it finished, and how long it took by the stamp
     * on its first entry -- one 32-byte read on top of what lastRunFor() costs.
     *
     * @return ?array{finished: int, duration: ?int}
     */
    public function newestRunFor(string $taskName): ?array
    {
        $newest = $this->newestFileFor($taskName);

        if ($newest === null) {
            return null;
        }

        $started = $this->startedOf($newest['name']);

        return [
            'finished' => $newest['modified'],
            'duration' => $started === null ? null : max(0, $newest['modified'] - $started),
        ];
    }

    /**
     * The task's most recently written log file. Stats only its newest day: the day in a name is
     * the day that run started, so an older day can only hold a later finish if two runs
     * overlapped across midnight.
     *
     * @return ?array{name: string, modified: int}
     */
    private function newestFileFor(string $taskName): ?array
    {
        $names = $this->scan()['byTask'][static::shortNameOf($taskName)]['names'] ?? [];
        $newest = null;

        foreach ($names as $name) {
            $modified = @filemtime($this->directory() . '/' . $name);

            if ($modified !== false && $modified > ($newest['modified'] ?? 0)) {
                $newest = ['name' => $name, 'modified' => $modified];
            }
        }

        return $newest;
    }

    /**
     * How many log files exist for a task. Free: counted during the name-only scan.
     */
    public function countFor(string $taskName): int
    {
        return $this->scan()['byTask'][static::shortNameOf($taskName)]['count'] ?? 0;
    }

    /**
     * One page of a task's log files.
     *
     * A name-only pass buckets the task's files by the day in their name and applies the date
     * filter there, before anything is opened. How many of the survivors are then opened depends
     * on the question: a duration one needs all of them (capped at MAX_WINDOW), an ordinary page
     * only enough days to cover it.
     *
     * @return array{files: array<int, array{name: string, modified: int, started: ?int, lines: ?int, duration: ?int}>, total: int, scanned: int, truncated: bool}
     */
    public function filesFor(string $taskName, LogQuery $query): array
    {
        [$byDay, $matched] = $this->namesByDay(static::shortNameOf($taskName), $query);

        if ($matched === 0) {
            return ['files' => [], 'total' => 0, 'scanned' => 0, 'truncated' => false];
        }

        [$candidates, $truncated] = $query->needsEveryFile()
            ? $this->everyCandidate($byDay, $matched)
            : $this->candidatesForPage($byDay, min($query->offset + $query->limit, static::MAX_WINDOW), $query);

        // Ordering needs a start time and nothing else, and that is 21 bytes into the file. Only
        // a duration question forces the extra stat on every candidate rather than on the page.
        $rows = [];
        foreach ($candidates as $name) {
            $row = ['name' => $name, 'started' => $this->startedOf($name)];

            if ($query->needsEveryFile()) {
                $row = $this->timed($row);

                if (!$query->keepsDuration($row['duration'])) {
                    continue;
                }
            }

            $rows[] = $row;
        }

        // With a duration bound the survivors are the only count we can state; otherwise the
        // name-only pass already counted every match without opening anything.
        $total = $query->minDuration === null && $query->maxDuration === null
            ? $matched
            : count($rows);

        static::sort($rows, $query);

        // Only the rows actually returned pay for the whole file.
        $files = [];
        foreach (array_slice($rows, $query->offset, $query->limit) as $row) {
            $file = $this->completed($row);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        return [
            'files' => $files,
            'total' => $total,
            'scanned' => count($candidates),
            'truncated' => $truncated,
        ];
    }

    /**
     * Every matching file, up to the scan ceiling. Newest days first, so a truncated scan drops
     * the oldest files rather than an arbitrary slice.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function everyCandidate(array $byDay, int $matched): array
    {
        krsort($byDay);
        $candidates = [];

        foreach ($byDay as $names) {
            foreach ($names as $name) {
                if (count($candidates) >= static::MAX_WINDOW) {
                    return [$candidates, true];
                }

                $candidates[] = $name;
            }
        }

        return [$candidates, $matched > count($candidates)];
    }

    /**
     * Just enough whole days to cover the page. The day in the name is the day the run started,
     * so this is complete for a start-ordered page however long a run took.
     *
     * Walked in the order asked for, not always newest first: an ascending page is drawn from the
     * oldest days, and taking the newest ones would fill it with the wrong end of the set.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function candidatesForPage(array $byDay, int $window, LogQuery $query): array
    {
        $query->descending ? krsort($byDay) : ksort($byDay);

        $candidates = [];

        foreach ($byDay as $names) {
            $candidates = array_merge($candidates, $names);

            if (count($candidates) >= $window) {
                break;
            }
        }

        return [$candidates, false];
    }

    /**
     * Order the page. A file whose start could not be read has no duration either, so it sorts
     * last in both orders rather than jumping to the top of an ascending one.
     */
    private static function sort(array &$files, LogQuery $query): void
    {
        $key = $query->sort === LogQuery::SORT_DURATION ? 'duration' : 'started';
        $direction = $query->descending ? -1 : 1;

        usort($files, function (array $a, array $b) use ($key, $direction) {
            // A duration is only present on the path that reads every file, which is the only
            // path that can be asked to sort by it; ?? keeps that coupling from being fatal.
            [$left, $right] = [$a[$key] ?? null, $b[$key] ?? null];

            if ($left === null || $right === null) {
                return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
            }

            return $direction * ($left <=> $right) ?: strcmp($b['name'], $a['name']);
        });
    }

    /**
     * When a run started, from the head of its log file. The stamp is 21 bytes in, so this reads
     * a fragment: it is called for every candidate, while everything else is called for a page.
     */
    private function startedOf(string $name): ?int
    {
        $head = @file_get_contents($this->directory() . '/' . $name, false, null, 0, 32);

        return $head === false ? null : static::timestampOf($head);
    }

    /**
     * Add the finish time and the duration to a row. The finish is the mtime, since the stop
     * entry is the last write core makes.
     */
    private function timed(array $row): array
    {
        $modified = @filemtime($this->directory() . '/' . $row['name']);

        $row['modified'] = $modified === false ? null : $modified;
        $row['duration'] = $modified === false || $row['started'] === null
            ? null
            : max(0, $modified - $row['started']);

        return $row;
    }

    /**
     * A row ready to be returned, or null when the file has gone since the directory was read.
     *
     * @return ?array{name: string, modified: int, started: ?int, lines: ?int, duration: ?int}
     */
    private function completed(array $row): ?array
    {
        $row = array_key_exists('modified', $row) ? $row : $this->timed($row);

        if ($row['modified'] === null) {
            return null;
        }

        $row['lines'] = $this->countEntries($this->directory() . '/' . $row['name']);

        return $row;
    }

    /**
     * How many entries a run logged, or null when the file is too large to be worth reading.
     *
     * Whether the run succeeded is deliberately not inferred from those entries -- core writes the
     * result to a notification email, never to the log, and the `[Notice]` labels beside them are
     * translated at write time, so one directory can hold files in several languages.
     */
    private function countEntries(string $path): ?int
    {
        $chunk = @file_get_contents($path, false, null, 0, static::MAX_READ);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        // A full read comes back short of the ceiling; anything else is truncated and would
        // undercount, so no count is offered.
        return strlen($chunk) < static::MAX_READ
            ? substr_count(rtrim($chunk, "\n"), "\n") + 1
            : null;
    }

    /**
     * Delete a task's log files older than the given number of days, or all of them when null.
     *
     * Selects on the day in the filename, so nothing is stat()ed -- that day is the task's
     * construction date rather than its mtime, which differ only across midnight. Only names
     * readdir() returned here are unlinked, so a task name can never select outside this
     * directory.
     *
     * @return array{deleted: int, failed: int}
     */
    public function deleteFor(string $taskName, ?int $olderThanDays = null): array
    {
        [$byDay] = $this->namesByDay(static::shortNameOf($taskName));

        $cutoff = $olderThanDays === null
            ? null
            : Carbon::now()->subDays(max(0, $olderThanDays))->format('Ymd');

        $deleted = 0;
        $failed = 0;

        foreach ($byDay as $day => $names) {
            if ($cutoff !== null && (string) $day >= $cutoff) {
                continue;
            }

            foreach ($names as $name) {
                @unlink($this->directory() . '/' . $name) ? $deleted++ : $failed++;
            }
        }

        if ($deleted) {
            // The cached scan counted files that are gone.
            $this->scan = null;
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * What a task itself wrote during one run, oldest first: the file without the entries
     * ScheduledTask::execute() wraps every run in -- base_url and the start notice ahead of the
     * task's own, and the stop notice after them when the task returned rather than threw.
     *
     * Those are picked out by position, never by their `[Notice]` label, which is translated at
     * write time. A file too long to read whole is read from its end, where a failure's reason
     * is, and then only the stop notice can be placed.
     *
     * @return ?array{file: string, entries: array<int, string>, truncated: bool}
     */
    public function runEntries(string $name, bool $returned, int $limit): ?array
    {
        $path = $this->directory() . '/' . basename($name);
        $size = @filesize($path);

        if ($size === false) {
            return null;
        }

        $whole = $size <= static::RUN_TAIL;
        $chunk = @file_get_contents($path, false, null, $whole ? 0 : $size - static::RUN_TAIL);

        if ($chunk === false) {
            return null;
        }

        if (!$whole) {
            // Starts mid-line; the first whole entry begins after the next line break.
            $break = strpos($chunk, "\n");
            $chunk = $break === false ? '' : substr($chunk, $break + 1);
        }

        // An entry's message can hold line breaks, so a line without a stamp continues the one
        // before it.
        $entries = [];
        foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
            if ($entries === [] || static::isEntry($line)) {
                $entries[] = $line;
            } else {
                $entries[count($entries) - 1] .= "\n" . $line;
            }
        }

        if ($whole) {
            $baseUrl = (string) Config::getVar('general', 'base_url');

            // Skipped by core when empty, so it is matched rather than assumed.
            if ($baseUrl !== '' && isset($entries[0]) && static::textOf($entries[0]) === $baseUrl) {
                array_shift($entries);
            }

            array_shift($entries);
        }

        if ($returned) {
            array_pop($entries);
        }

        return [
            'file' => basename($name),
            'entries' => array_slice($entries, -$limit),
            'truncated' => !$whole || count($entries) > $limit,
        ];
    }

    /**
     * An entry without its stamp: `[2026-09-22 04:27:43] [Error] reason` reads `[Error] reason`.
     */
    public static function textOf(string $entry): string
    {
        return (string) preg_replace('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ?/', '', $entry);
    }

    private static function isEntry(string $line): bool
    {
        return (bool) preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line);
    }

    /**
     * The `[Y-m-d H:i:s]` stamp at the head of a log entry.
     *
     * Written by Core::getCurrentDate(), a plain date() in the application timezone, so it stays
     * parseable whatever language the rest of the line was written in -- unlike the `[Notice]`
     * type label beside it, which is translated at write time. It carries no offset, so a log
     * written before [general] time_zone was changed reads shifted; the caller clamps at zero.
     */
    private static function timestampOf(string $line): ?int
    {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return null;
        }

        return strtotime($matches[1]) ?: null;
    }

    /**
     * The short class name a task's log files are prefixed with.
     *
     * Core drops the namespace when naming the file, so two task classes sharing a short name
     * would share log files -- inherent to core's naming, not fixable from here.
     */
    public static function shortNameOf(string $taskName): string
    {
        $parts = explode('\\', trim($taskName, '\\'));

        return end($parts);
    }

    /**
     * One task's file names grouped by the day in their name, plus how many there are.
     *
     * The date filter is applied here rather than later: it is the one filter answerable from a
     * name, so applying it during the readdir keeps everything it excludes from ever being opened.
     *
     * @return array{0: array<string, array<int, string>>, 1: int}
     */
    private function namesByDay(string $shortName, ?LogQuery $query = null): array
    {
        $byDay = [];
        $total = 0;

        if (!$this->exists()) {
            return [$byDay, $total];
        }

        $handle = @opendir($this->directory());

        if ($handle === false) {
            return [$byDay, $total];
        }

        try {
            while (($name = readdir($handle)) !== false) {
                $parsed = static::parse($name);

                if ($parsed === null || $parsed[0] !== $shortName) {
                    continue;
                }

                if ($query !== null && !$query->keepsDay($parsed[1])) {
                    continue;
                }

                $total++;
                $byDay[$parsed[1]][] = $name;
            }
        } catch (Throwable $exception) {
            error_log('ScheduledTaskManager could not list log files: ' . $exception->getMessage());
        } finally {
            closedir($handle);
        }

        return [$byDay, $total];
    }

    /**
     * Split a log filename into its task and its day, or null when it is not one of ours.
     *
     * `{ShortClassName}-{uniqid}-{Ymd}.log`: neither part contains a dash, so the first and last
     * dashes bound both without a regex per directory entry.
     *
     * @return ?array{0: string, 1: string}
     */
    private static function parse(string $filename): ?array
    {
        if (!str_ends_with($filename, '.log')) {
            return null;
        }

        $firstDash = strpos($filename, '-');
        $lastDash = strrpos($filename, '-');

        if ($firstDash === false || $firstDash === 0 || $lastDash === $firstDash) {
            return null;
        }

        $day = substr($filename, $lastDash + 1, -4);

        return [substr($filename, 0, $firstDash), $day];
    }
}
