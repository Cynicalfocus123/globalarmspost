<?php
declare(strict_types=1);

// Dates are stored in UTC ('Y-m-d H:i:s') and shown in the site's time zone (setting 'timezone').

function site_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        try {
            $tz = new DateTimeZone(setting('timezone', 'UTC'));
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }
    }
    return $tz;
}

function local_dt(string $utc): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(site_tz());
}

/** "3 Nov 2026" */
function fmt_date(string $utc): string
{
    return local_dt($utc)->format('j M Y');
}

/** "3:57 PM GMT+7" (zone only when asked) */
function fmt_time(string $utc, bool $zone = false): string
{
    $d = local_dt($utc);
    return $d->format('g:i A') . ($zone ? ' ' . gmt_label($d) : '');
}

/** "GMT+7", "GMT+5:30", "GMT" */
function gmt_label(DateTimeImmutable $d): string
{
    $off = $d->getOffset();
    if ($off === 0) {
        return 'GMT';
    }
    $sign = $off < 0 ? '-' : '+';
    $off = abs($off);
    $h = intdiv($off, 3600);
    $m = intdiv($off % 3600, 60);
    return 'GMT' . $sign . $h . ($m ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
}

/** "November 2, 2026 3:57 PM GMT+7" for article bylines. */
function fmt_full(string $utc): string
{
    $d = local_dt($utc);
    return $d->format('F j, Y g:i A') . ' ' . gmt_label($d);
}

/** Local day key "2026-11-03" for grouping. */
function day_key(string $utc): string
{
    return local_dt($utc)->format('Y-m-d');
}

/**
 * Category page day heading parts: label "Today" / "Yesterday" / "" and the date text.
 * @return array{label: string, date: string, short: string}
 */
function day_heading(string $utc, ?DateTimeImmutable $now = null): array
{
    $d = local_dt($utc);
    $today = ($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz())->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($today . ' 12:00:00', site_tz()))->modify('-1 day')->format('Y-m-d');
    $key = $d->format('Y-m-d');
    return [
        'label' => $key === $today ? 'Today' : ($key === $yesterday ? 'Yesterday' : ''),
        'date' => $d->format('l, j F Y'),
        'short' => $d->format('D, j M Y'),
    ];
}
