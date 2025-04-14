<?php

use Carbon\Carbon;
use RRule\RRule;

class RefreshAttempt
{
    public ?Carbon $refresh_at;
    public ?Carbon $deadline_at;
    public int $attempt_no;
    public bool $final_failure;

    public function __construct($params)
    {
        $this->refresh_at = $params['refresh_at'];
        $this->deadline_at = $params['deadline_at'];
        $this->attempt_no = $params['attempt_no'];
        $this->final_failure = $params['final_failure'];
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
 *   failure → success|final_failure
 *
 * Parameters:
 *   - $now | A reference time to calculate the next refresh and deadline periods. | Instance of Carbon (or null to use the current time).
 *   - $rrule | An RRULE expression representing refresh periodicity. | Valid RRULE expression or null if no recurring refresh is needed.
 *   - $timeout | Expected time to complete the refresh process. | A valid, non-inverted, non-zero DateInterval expression (or null for the default 10-minute timeout).
 *   - $attempt_no | Current attempt number. | Non-negative integer.
 *   - $retry_intervals | List of backoff retry delays. | An array (or null) of DateInterval expressions (empty for immediate retry).
 */
function refresh_retry(array $params): void
{
    $now = $params['now'] ?? Carbon::now();

    $error_details = null;
    try {
        $rrule = null;
        if (isset($params['rrule'])) {
            // DTSTART:20250101T000000\nRRULE:FREQ=HOURLY;INTERVAL=2
            $rrule = RRule::createFromRfcString($params['rrule']);
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
        array_unshift($retry_intervals, null);
    }
    catch (Throwable $exception) {
        $error_details = get_class($exception) . "\n" . $exception->getMessage();
    }
    if ($error_details) {
        throw new InvalidArgumentException(trim("\$retry_intervals Must be an array (or null) of DateInterval expressions (empty values for immediate retry)\n\n$error_details"));
    }

    $action = $params['action'] ?? null;
    $fn = $params['fn'] ?? null;

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

    switch ($action) {
    case 'start':
        $deadline_at = $now->copy()->add($timeout);
        call_user_func($fn, new RefreshAttempt([
            'refresh_at' => empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($deadline_at, 1)),
            'deadline_at' => $deadline_at,
            'attempt_no' => $attempt_no + 1,
            'final_failure' => false,
        ]));
        break;
    case 'success':
        call_user_func($fn, new RefreshAttempt([
            'refresh_at' => empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($now, 1)),
            'deadline_at' => null,
            'attempt_no' => 0,
            'final_failure' => false,
        ]));
        break;
    case 'failure':
        if (count($retry_intervals) <= $attempt_no) {
            // Several attempts were made, but all failed
            call_user_func($fn, new RefreshAttempt([
                'refresh_at' => null,
                'deadline_at' => null,
                'attempt_no' => $attempt_no,
                'final_failure' => true,
            ]));
            break;
        }
        // Schedule a retry
        if (empty($retry_intervals[$attempt_no])) {
            // When there is no delay, reuse $now
            $retry_start_at = $now;
        }
        else {
            $delay = new DateInterval($retry_intervals[$attempt_no]);
            if ($delay->format('%y-%m-%d %h:%i:%s.%f') === '0-0-0 0:0:0.0') {
                // When there is no delay, reuse $now
                $retry_start_at = $now;
            }
            else {
                $retry_start_at = $now->copy()->add($delay);
            }
        }
        $deadline_at = $retry_start_at->copy()->add($timeout);
        $planned_refresh_at = empty($rrule) ? null : Carbon::make($rrule->getNthOccurrenceAfter($now, 1));
        if (!$planned_refresh_at) {
            // No more planned refreshes are expected.
            // Start the retry after the specified delay.
            call_user_func($fn, new RefreshAttempt([
                'refresh_at' => $retry_start_at,
                'deadline_at' => null,
                'attempt_no' => $attempt_no,
                'final_failure' => false,
            ]));
            break;
        }
        // Keep the refresh aligned with the original timing
        // -------------------------------------------------
        if ($retry_start_at->gt($planned_refresh_at)) {
            // Retry starts after the next planned refresh.
            // Wait less than necessary and start the retry at the next planned refresh.
            call_user_func($fn, new RefreshAttempt([
                'refresh_at' => $planned_refresh_at,
                'deadline_at' => null,
                'attempt_no' => $attempt_no,
                'final_failure' => false,
            ]));
            break;
        }
        if ($deadline_at->gt($planned_refresh_at)) {
            // Retry starts before the planned refresh but might end after it.
            // Wait a bit longer and start the retry at the next planned refresh.
            call_user_func($fn, new RefreshAttempt([
                'refresh_at' => $planned_refresh_at,
                'deadline_at' => null,
                'attempt_no' => $attempt_no,
                'final_failure' => false,
            ]));
            break;
        }
        // Retry starts before and is expected to finish before the next planned refresh.
        // Start the retry after the specified delay.
        call_user_func($fn, new RefreshAttempt([
            'refresh_at' => $retry_start_at,
            'deadline_at' => null,
            'attempt_no' => $attempt_no,
            'final_failure' => false,
        ]));
        break;
    default:
        throw new InvalidArgumentException("Invalid action: $action");
    }
}
