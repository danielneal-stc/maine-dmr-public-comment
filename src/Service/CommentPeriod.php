<?php

declare(strict_types=1);

namespace Drupal\dmr_public_comment\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * The single authority on when a comment period is open.
 *
 * Every part of the module that has an opinion about a comment period asks this
 * service: the autofill endpoints decide whether to return case data, and the
 * validation handler decides whether to accept a submission. Those two answers
 * have to agree. When they were implemented separately, a drift between them
 * would have let a case pre-populate the form and then refuse the comment the
 * visitor typed into it, or the reverse, and neither symptom points at the
 * cause. Keeping the timezone, the closing hour, the date parsing and the
 * comparison in one class is what makes that impossible.
 *
 * The rules, unchanged from the two implementations this replaces:
 *   - A period opens at midnight Eastern on its start date.
 *   - It closes at 4:00 PM Eastern on its end date.
 *   - America/New_York is used throughout, so EST and EDT are handled by PHP
 *     rather than by an offset this module would have to maintain.
 *   - A date that cannot be read is not treated as permissive. Every caller
 *     turns STATUS_UNKNOWN into a refusal.
 */
class CommentPeriod {

  /**
   * The timezone every comment period is expressed in.
   *
   * Comment periods are set by Maine DMR staff in Eastern time and published to
   * the public in Eastern time, so the boundaries are evaluated there rather
   * than in the site's or the server's timezone.
   */
  public const TIMEZONE = 'America/New_York';

  /**
   * The hour, Eastern, at which a period closes on its end date.
   */
  public const CLOSE_HOUR = 16;

  /**
   * The current Eastern time falls inside the period.
   */
  public const STATUS_OPEN = 'open';

  /**
   * The period has a valid start date that has not been reached yet.
   */
  public const STATUS_NOT_YET_OPEN = 'not_yet_open';

  /**
   * The period's closing time has passed.
   */
  public const STATUS_CLOSED = 'closed';

  /**
   * One of the dates could not be read, so the period cannot be evaluated.
   *
   * Callers must treat this exactly as they treat a closed period. It means the
   * source data is wrong, not that the period is open.
   */
  public const STATUS_UNKNOWN = 'unknown';

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the timezone comment periods are evaluated in.
   */
  public function timezone(): \DateTimeZone {
    return new \DateTimeZone(self::TIMEZONE);
  }

  /**
   * Parses a Pega or Drupal date string into a date in the period timezone.
   *
   * Accepts "YYYYMMDD", which is what Pega's older integrations produce, and
   * "YYYY-MM-DD", which is what the dev tenant and Drupal's own date fields
   * return. Drupal datetime fields hold "YYYY-MM-DDTHH:MM:SS", so callers pass
   * the first ten characters; see dateValue() on PublicCommentNodeSource.
   *
   * The parsed value is verified by reformatting it, so an out-of-range date
   * such as "20261345" is rejected instead of silently rolling over into the
   * following month, which would extend a comment period without anyone
   * noticing.
   *
   * @param string $date
   *   The date string to parse.
   *
   * @return \DateTimeImmutable|null
   *   The parsed date, or NULL if the string is not a date this module accepts.
   *   The time of day on the returned value is unspecified: use statusAt() or
   *   set it explicitly.
   */
  public function parse(string $date): ?\DateTimeImmutable {
    $normalized = str_replace('-', '', trim($date));
    if (strlen($normalized) !== 8 || !ctype_digit($normalized)) {
      return NULL;
    }

    $parsed = \DateTimeImmutable::createFromFormat('Ymd', $normalized, $this->timezone());
    if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Ymd') !== $normalized) {
      return NULL;
    }

    return $parsed;
  }

  /**
   * Returns the status of a comment period as of now.
   *
   * @param string $start
   *   Start date in "YYYYMMDD" or "YYYY-MM-DD" format.
   * @param string $end
   *   End date in the same formats.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function status(string $start, string $end): string {
    return $this->statusAt($start, $end, $this->now());
  }

  /**
   * Returns the status of a comment period at a given moment.
   *
   * The moment is a parameter so that the boundaries, including the 4:00 PM
   * close and the EST/EDT change, can be tested without waiting for them.
   * Production code calls status() instead.
   *
   * @param string $start
   *   Start date in "YYYYMMDD" or "YYYY-MM-DD" format.
   * @param string $end
   *   End date in the same formats.
   * @param \DateTimeInterface $now
   *   The moment to evaluate the period against.
   *
   * @return string
   *   One of the STATUS_* constants.
   */
  public function statusAt(string $start, string $end, \DateTimeInterface $now): string {
    $startDate = $this->parse($start);
    $endDate   = $this->parse($end);

    if ($startDate === NULL || $endDate === NULL) {
      return self::STATUS_UNKNOWN;
    }

    $opens  = $startDate->setTime(0, 0, 0);
    $closes = $endDate->setTime(self::CLOSE_HOUR, 0, 0);

    if ($now < $opens) {
      return self::STATUS_NOT_YET_OPEN;
    }
    if ($now >= $closes) {
      return self::STATUS_CLOSED;
    }

    return self::STATUS_OPEN;
  }

  /**
   * Determines whether a comment period is open right now.
   *
   * @param string $start
   *   Start date in "YYYYMMDD" or "YYYY-MM-DD" format.
   * @param string $end
   *   End date in the same formats.
   *
   * @return bool
   *   TRUE only when the period is open. Missing and unreadable dates return
   *   FALSE, so a caller that only asks this question still fails closed.
   */
  public function isOpen(string $start, string $end): bool {
    return $this->status($start, $end) === self::STATUS_OPEN;
  }

  /**
   * Formats one date for display on the form.
   *
   * @param string $date
   *   Date string in "YYYYMMDD" or "YYYY-MM-DD" format.
   *
   * @return string
   *   The date as "M/D/YYYY" (e.g. "6/15/2026"), or the string as supplied if
   *   it cannot be parsed. Returning the original keeps a readable value in
   *   front of the visitor rather than blanking the field; nothing is decided
   *   on the basis of this value.
   */
  public function format(string $date): string {
    return $this->parse($date)?->format('n/j/Y') ?? $date;
  }

  /**
   * Formats a comment period as the range shown on the form.
   *
   * @param string $start
   *   Start date in "YYYYMMDD" or "YYYY-MM-DD" format.
   * @param string $end
   *   End date in the same formats.
   *
   * @return string
   *   The range, e.g. "6/1/2026 - 6/15/2026".
   */
  public function formatRange(string $start, string $end): string {
    return $this->format($start) . ' - ' . $this->format($end);
  }

  /**
   * Returns the current moment in the period timezone.
   */
  public function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($this->timezone());
  }

}
