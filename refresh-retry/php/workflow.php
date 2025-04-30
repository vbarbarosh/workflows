<?php

use Carbon\Carbon;
use RRule\RRule;

const REFRESH_RETRY_START = 'start';
const REFRESH_RETRY_SUCCESS = 'success';
const REFRESH_RETRY_FAILURE = 'failure';

class RefreshAttempt
{
    // TODO Rename scheduled_refresh_at → planned_at
    public ?Carbon $scheduled_refresh_at = null;
    public ?Carbon $retry_at = null;

    public ?Carbon $refresh_at;
    public ?Carbon $deadline_at;
    public int $attempt_no;
    public bool $retries_exhausted;

    public function __construct($params)
    {
        $this->scheduled_refresh_at = $params['scheduled_refresh_at'];
        $this->retry_at = $params['retry_at'];

        $this->refresh_at = $params['refresh_at'];
        $this->deadline_at = $params['deadline_at'];
        $this->attempt_no = $params['attempt_no'];
        $this->retries_exhausted = $params['retries_exhausted'];
    }
}

class RetryStrategy
{
    /**
     * Strategy 1: retry_align_planned
     * In case of failure, perform retries but prefer the next scheduled
     * refresh if it comes before the next retry, or if the next retry can
     * complete before the next scheduled refresh.
     */
    public static function retry_align_planned(?Carbon $retry_at, ?Carbon $planned_at, DateInterval $timeout): ?Carbon
    {
        if (!$retry_at || !$planned_at) {
            return $retry_at;
        }
        $retry_end = $retry_at->copy()->add($timeout);
        if ($retry_end->lt($planned_at)) {
            return $retry_at;
        }
        return $planned_at;
    }

    /**
     * Strategy 1: retry_at
     * In case of failure, perform retries until success.
     * Scheduled refresh could follow only after a successful attempt.
     */
    public static function retry_at(?Carbon $retry_at): ?Carbon
    {
        return $retry_at;
    }

    /**
     * Strategy 2: planned_at
     * Each attempt should perform at scheduled time.
     */
    public static function planned_at(?Carbon $retry_start, ?Carbon $planned_at): ?Carbon
    {
        return $planned_at;
    }

    /**
     * Strategy 3: whichever_first
     * In case of failure, prefer scheduled refresh if it comes before the next retry.
     */
    public static function whichever_first(?Carbon $retry_at, ?Carbon $planned_at): ?Carbon
    {
        if ($retry_at === null) {
            return $planned_at;
        }
        if ($planned_at === null) {
            return $retry_at;
        }
        return $retry_at->lt($planned_at) ? $retry_at : $planned_at;
    }

//    /**
//     * Strategy 5: retry_between_planned
//     * In case of failure, perform retries as many as possible, until next scheduled refresh.
//     * Each scheduled refresh is treated as a new base attempt (attempt_no = 0).
//     */
//    public static function retry_between_planned(Carbon $retry_start, Carbon $retry_end, Carbon $planned_start, Carbon $planned_end): Carbon
//    {
//        if ($retry_start->lt($planned_start)) {
//            return $retry_start;
//        }
//        return $planned_start;
//    }
}

/**
 * A workflow for refreshing models with support for retry.
 *
 * The minimum number of variables:
 *   - refresh_at: when to start the next refresh
 *   - deadline_at: when to fail the current refresh process due to a timeout
 *   - attempt_no: increases with each new refresh and resets only on success
 *
 * Transitions diagram:
 *   start → success|failure|retries_exhausted
 *   success → start
 *   failure → start|retries_exhausted
 *
 * Parameters:
 *   - $now | A reference time to calculate the next refresh and deadline periods. | Instance of Carbon (or null to use the current time).
 *   - $rrule | An RRULE expression representing refresh periodicity. | Valid RRULE expression or null if no recurring refresh is needed.
 *   - $timeout | Expected time to complete the refresh process. | A valid, non-inverted, non-zero DateInterval expression (or null for the default 10-minute timeout).
 *   - $attempt_no | Current attempt number. | Non-negative integer.
 *   - $retry_intervals | List of backoff retry delays. | An array (or null) of DateInterval expressions (empty for immediate retry).
 *   - $retry_strategy | Either a `function (?Carbon $retry_at, ?Carbon $planned_at, DateInterval $timeout): ?Carbon`
 *     which should return a time for the next retry attempt, or one of:
 *         - retry_align_planned DEFAULT
 *         - retry_at
 *         - planned_at
 *         - whichever_first
 *   - $action | One of: start, success, failure.
 *   - $fn | A function to save refresh_at, deadline_at, and attempt_no | A Callable which the following signature: function (RefreshAttempt $attempt) { ... }
 *
 * @author Vladimir Barbaros (vladimir.barbarosh@gmail.com)
 * @link https://github.com/vbarbarosh/workflows/tree/main/refresh-retry
 */
function refresh_retry(array $params): void
{
    $now = $params['now'] ?? Carbon::now();

    $error_details = null;
    try {
        $rrule = null;
        if (isset($params['rrule'])) {
            // DTSTART:20250101T000000Z\nRRULE:FREQ=HOURLY;INTERVAL=2
            $rrule = RRule::createFromRfcString(preg_replace('/^RRULE:/', "DTSTART:{$now->format('Ymd\THis\Z')}\nRRULE:", $params['rrule']));
        }
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$rrule must be a valid RRULE expression (or null)\n\n$error_details"));
    }

    $error_details = null;
    try {
        if (!isset($params['timeout'])) {
            // Default timeout is 10 minutes.
            $timeout = new DateInterval('PT10M');
        }
        else if ($params['timeout'] instanceof DateInterval) {
            $timeout = $params['timeout'];
        }
        else {
            $timeout = new DateInterval($params['timeout']);
        }
        if ($timeout->invert || $timeout->format('%y-%m-%d %h:%i:%s.%f') === '0-0-0 0:0:0.0') {
            $error_details = "\n";
        }
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$timeout must be a valid DateInterval expression (or instance) with an interval greater than zero\n\n$error_details"));
    }

    $attempt_no = $params['attempt_no'] ?? 0;
    if (!is_integer($attempt_no) || $attempt_no < 0) {
        throw new InvalidArgumentException("\$attempt_no must be a non-negative integer: $attempt_no");
    }

    $error_details = null;
    try {
        $retry_intervals = $params['retry_intervals'] ?? []; // [null, 'PT0M', 'PT1M', 'PT5M', 'PT15M', 'PT30M', 'PT1H']
        if (!is_array($retry_intervals)) {
            throw new RuntimeException('Not an array');
        }
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$retry_intervals Must be an array (or null) of DateInterval expressions (empty values for immediate retry)\n\n$error_details"));
    }

    $error_details = null;
    try {
        $action = $params['action'] ?? null;
        if (!in_array($action, [REFRESH_RETRY_START, REFRESH_RETRY_SUCCESS, REFRESH_RETRY_FAILURE])) {
            $error_details = "\n";
        }
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$action Must be one of: start, success, failure\n\n$error_details"));
    }

    $error_details = null;
    try {
        $fn = $params['fn'] ?? null;
        if (!is_callable($fn)) {
            $error_details = 'Not a callable';
        }
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$fn Must be a callable\n\n$error_details"));
    }

    unset($params['now']);
    unset($params['rrule']);
    unset($params['timeout']);
    unset($params['attempt_no']);
    unset($params['retry_intervals']);
    unset($params['action']);
    unset($params['fn']);

    if (!empty($params)) {
        $keys = implode(', ', array_keys($params));
        throw new InvalidArgumentException("Invalid parameters: $keys");
    }

    $deadline_at = $now->copy()->add($timeout);

    // Calculate the next scheduled refresh, and the next scheduled refresh for
    // the worst-case scenario (when the job died and no success or failure message was sent)
    $scheduled_refresh_at = empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($now, 1));
    $scheduled_refresh2_at = empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($deadline_at, 1));;

    // ⚠️ retries_exhausted → retry_at IS NULL, refresh_at IS NULL
    // ⚠️ retries_exhausted → we did our best, no more attempts to refresh

    // ❔ Rename $attempt_no → $start_counter
    // ❔ Rename $attempt_no → $attempts_made
    // ❔ Rename $attempt_no → $attempts_counter

    // 🧩 Edge case: All responses were lost
    // This is definitely an issue with the configuration or internal infrastructure.
    //
    // 🧩 Edge case: The last response was lost
    // Errors occur. Although another mechanism should be responsible for this,
    // two options are available:
    // 1. Completely rely on another mechanism to cover this edge case.
    //    It should fire REFRESH_RETRY_FAILURE.
    // 2. On the last retry, instead of returning an empty `refresh_at`,
    //    return one immediately after the delay. This way, the next time
    //    REFRESH_RETRY_START fires, `$retries_exhausted` will be set to `true`
    //    and `$refresh_at` will be `null`. ⚠️ Special care must be taken
    //    not to start the actual job.
    //
    // 🧩 Edge case: Request to start after last retry was already performed
    //     When a start request followed
    //     - request to start
    //     - retry number is greater than no of available retries
    //     -  `retries_exhausted = true`

    // What to do when a start is requested with an attempt number greater than the
    // number of retry intervals declared? This might indicate that the job process died
    // prematurely.
    // - Either retry one more time,
    // - or ignore it.

    // REFRESH_RETRY_START
    // -------------------
    //
    // $attempt_no === 0 && count($retry_intervals) === 0:
    //     start refresh; no retries to perform in case of failure
    //
    // $attempt_no === 0 && count($retry_intervals) === 1:
    //     start refresh; 1 retry to perform in case of failure
    //
    // $attempt_no === 0 && count($retry_intervals) === 2:
    //     start refresh; 2 retries to perform in case of failure
    //
    // $attempt_no === 1 && count($retry_intervals) === 1:
    //     first retry; no more retries to perform in case of failure
    //
    // $attempt_no === 2 && count($retry_intervals) === 1:
    //     second retry, but only one retry was specified to perform.
    //     that is an exception, no refresh should be started! ⚠️
    //
    // $attempt_no === 1 && count($retry_intervals) === 2:
    //     first retry; 1 more retry to perform in case of failure
    //
    // $attempt_no === 2 && count($retry_intervals) === 2:
    //     second retry; no more retry to perform in case of failure
    //
    // $attempt_no === 2 && count($retry_intervals) === 2:
    //     third retry, but only 2 retries was specified.
    //     this is an exception, no refresh should be started! ⚠️

    $retry_no = $attempt_no - 1;

    // Calculate next retry
    switch ($action) {
    case REFRESH_RETRY_START:
        // 🧩 Edge case: Request to start after last retry was already performed
        $retries_exhausted = $retry_no >= count($retry_intervals);
        if ($retries_exhausted) {
            $retry_at = null;
        }
        else if (($retry_no + 1) >= count($retry_intervals)) {
            $retry_at = null;
        }
        else {
            $retry_at = $now->copy()->add($timeout)->add(new DateInterval($retry_intervals[$retry_no + 1] ?? 0 ?: 'PT0M'));
        }
        break;
    case REFRESH_RETRY_FAILURE:
        if ($retry_no >= count($retry_intervals)) {
            $retry_at = null;
            $retries_exhausted = true;
            break;
        }
        $retry_at = empty($retry_intervals[$retry_no]) ? $now : $now->copy()->add(new DateInterval($retry_intervals[$retry_no] ?: 'PT0M'));
        $retries_exhausted = false;
        break;
    case REFRESH_RETRY_SUCCESS:
        $retry_at = null;
        $retries_exhausted = false;
        break;
    }

    // Choose when the next refresh should start.
    switch ($action) {
    case REFRESH_RETRY_START:
        $refresh_at = $retry_at
            ? RetryStrategy::retry_align_planned($retry_at, $scheduled_refresh2_at, $timeout)
            : $scheduled_refresh2_at;
        break;
    case REFRESH_RETRY_SUCCESS:
        $refresh_at = $scheduled_refresh_at;
        break;
    case REFRESH_RETRY_FAILURE:
        $refresh_at = RetryStrategy::retry_align_planned($retry_at, $scheduled_refresh_at, $timeout);
        break;
    }

    if ($retries_exhausted) {
        $refresh_at = null;
    }

    switch ($action) {
    case REFRESH_RETRY_START:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'retry_at' => $retry_at,
            'refresh_at' => $refresh_at, // Next refresh_at after the current refresh times out.
            'deadline_at' => $deadline_at,
            'attempt_no' => $attempt_no + 1,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
    case REFRESH_RETRY_SUCCESS:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'retry_at' => $retry_at,
            'refresh_at' => $refresh_at,
            'deadline_at' => null,
            'attempt_no' => 0,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
    case REFRESH_RETRY_FAILURE:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'retry_at' => $retry_at,
            'refresh_at' => $refresh_at,
            'deadline_at' => null,
            'attempt_no' => $attempt_no,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
    default:
        throw new DomainException("Invalid action: $action");
    }
}
