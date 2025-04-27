<?php

use Carbon\Carbon;
use RRule\RRule;

const REFRESH_RETRY_START = 'start';
const REFRESH_RETRY_SUCCESS = 'success';
const REFRESH_RETRY_FAILURE = 'failure';

class RefreshAttempt
{
    public ?Carbon $scheduled_refresh_at = null;
    public ?Carbon $refresh_at;
    public ?Carbon $deadline_at;
    public int $attempt_no;
    public bool $retries_exhausted;

    public function __construct($params)
    {
        $this->scheduled_refresh_at = $params['scheduled_refresh_at'];
        $this->refresh_at = $params['refresh_at'];
        $this->deadline_at = $params['deadline_at'];
        $this->attempt_no = $params['attempt_no'];
        $this->retries_exhausted = $params['retries_exhausted'];
    }
}

/**
 * A workflow for refreshing models with retry support.
 *
 * The minimum number of variables:
 *   - refresh_at: when to start the next refresh
 *   - deadline_at: when to fail the current refresh due to a timeout
 *   - attempt_no: increases with each new refresh and resets only on success
 *
 * Transitions diagram:
 *   start → success|failure,
 *   failure → success|retries_exhausted
 *
 * Parameters:
 *   - $now | A reference time to calculate the next refresh and deadline periods. | Instance of Carbon (or null to use the current time).
 *   - $rrule | An RRULE expression representing refresh periodicity. | Valid RRULE expression or null if no recurring refresh is needed.
 *   - $timeout | Expected time to complete the refresh process. | A valid, non-inverted, non-zero DateInterval expression (or null for the default 10-minute timeout).
 *   - $attempt_no | Current attempt number. | Non-negative integer.
 *   - $retry_intervals | List of backoff retry delays. | An array (or null) of DateInterval expressions (empty for immediate retry).
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
    $scheduled2_refresh_at = empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($deadline_at, 1));;

    // ⚠️ retries_exhausted → retry_at IS NULL, refresh_at IS NULL
    // ⚠️ retries_exhausted → we did our best, no more attempts to refresh

    // ❔ Rename $attempt_no → $start_counter

    // Edge case: start after last retry
    //     How should it behave?

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
        // Handle Edge Case: The response from the job was lost.
        // Handle Edge Case: Start after last retry
        $retry_at = $now->copy()->add($timeout)->add(new DateInterval($retry_intervals[$retry_no + 1] ?? 0 ?: 'PT0M'));
        $retries_exhausted = $retry_no >= count($retry_intervals);
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
        $refresh_at = $scheduled2_refresh_at;
        break;
    case REFRESH_RETRY_SUCCESS:
        $refresh_at = $scheduled_refresh_at;
        break;
    case REFRESH_RETRY_FAILURE:
        $refresh_at = RefreshRetryStrategy::retry_align_planned($retry_at, $scheduled_refresh_at, $timeout);
        break;
    }

    switch ($action) {
    case REFRESH_RETRY_START:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'refresh_at' => $refresh_at,
            'deadline_at' => $deadline_at,
            'attempt_no' => $attempt_no + 1,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
    case REFRESH_RETRY_SUCCESS:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'refresh_at' => $refresh_at,
            'deadline_at' => null,
            'attempt_no' => 0,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
    case REFRESH_RETRY_FAILURE:
        call_user_func($fn, new RefreshAttempt([
            'scheduled_refresh_at' => $scheduled_refresh_at,
            'refresh_at' => $refresh_at,
            'deadline_at' => null,
            'attempt_no' => $attempt_no,
            'retries_exhausted' => $retries_exhausted,
        ]));
        break;
//        if (count($retry_intervals) <= $attempt_no) {
//            // Several attempts were made, but all failed
//            call_user_func($fn, new RefreshAttempt([
//                'scheduled_refresh_at' => $scheduled_refresh_at,
//                'refresh_at' => null,
//                'deadline_at' => null,
//                'attempt_no' => $attempt_no,
//                'retries_exhausted' => $retries_exhausted,
//            ]));
//            break;
//        }
//        $retry_start_at = $retry_at;
////        // Schedule a retry
////        if (empty($retry_intervals[$attempt_no])) {
////            // When there is no delay, reuse $now
////            $retry_start_at = $now;
////        }
////        else {
////            $delay = new DateInterval($retry_intervals[$attempt_no]);
////            if ($delay->format('%y-%m-%d %h:%i:%s.%f') === '0-0-0 0:0:0.0') {
////                // When there is no delay, reuse $now
////                $retry_start_at = $now;
////            }
////            else {
////                $retry_start_at = $now->copy()->add($delay);
////            }
////        }
//        $deadline_at = $retry_start_at->copy()->add($timeout);
//        if (!$scheduled_refresh_at) {
//            // No more planned refreshes are expected.
//            // Start the retry after the specified delay.
//            call_user_func($fn, new RefreshAttempt([
//                'scheduled_refresh_at' => $scheduled_refresh_at,
//                'refresh_at' => $retry_start_at,
//                'deadline_at' => null,
//                'attempt_no' => $attempt_no,
//                'retries_exhausted' => $retries_exhausted,
//            ]));
//            break;
//        }
//        // Keep the refresh aligned with the original timing
//        // -------------------------------------------------
//        if ($retry_start_at->gt($scheduled_refresh_at)) {
//            // Retry starts after the next planned refresh.
//            // Wait less than necessary and start the retry at the next planned refresh.
//            call_user_func($fn, new RefreshAttempt([
//                'scheduled_refresh_at' => $scheduled_refresh_at,
//                'refresh_at' => $scheduled_refresh_at,
//                'deadline_at' => $scheduled_refresh_at->copy()->add($timeout),
//                'attempt_no' => $attempt_no,
//                'retries_exhausted' => $retries_exhausted,
//            ]));
//            break;
//        }
//        if ($deadline_at->gt($scheduled_refresh_at)) {
//            // Retry starts before the planned refresh but might end after it.
//            // Wait a bit longer and start the retry at the next planned refresh.
//            call_user_func($fn, new RefreshAttempt([
//                'scheduled_refresh_at' => $scheduled_refresh_at,
//                'refresh_at' => $scheduled_refresh_at,
//                'deadline_at' => $scheduled_refresh_at->copy()->add($timeout),
//                'attempt_no' => $attempt_no,
//                'retries_exhausted' => $retries_exhausted,
//            ]));
//            break;
//        }
//        // Retry starts before and is expected to finish before the next planned refresh.
//        // Start the retry after the specified delay.
//        call_user_func($fn, new RefreshAttempt([
//            'scheduled_refresh_at' => $scheduled_refresh_at,
//            'refresh_at' => $retry_start_at,
//            'deadline_at' => $retry_start_at->copy()->add($timeout),
//            'attempt_no' => $attempt_no,
//            'retries_exhausted' => $retries_exhausted,
//        ]));
//        break;
    default:
        throw new DomainException("Invalid action: $action");
    }
}
