<?php

use Carbon\Carbon;

class RetryStrategy
{
    /**
     * Strategy 1: retry_until_success
     * In case of failure, perform retries until success.
     * Scheduled refresh could follow only after a successful attempt.
     */
    public static function retry_until_success(?Carbon $retry_start): ?Carbon
    {
        return $retry_start;
    }

    /**
     * Strategy 2: retry_at_planned_time
     * Each attempt should perform at scheduled time.
     */
    public static function retry_at_planned_time(?Carbon $retry_start, ?Carbon $planned_start): ?Carbon
    {
        return $planned_start;
    }

    /**
     * Strategy 3: whichever_first
     * In case of failure, prefer scheduled refresh if it comes before next retry.
     */
    public static function whichever_first(?Carbon $retry_start, ?Carbon $planned_start): ?Carbon
    {
        if ($retry_start === null) {
            return $planned_start;
        }
        if ($planned_start === null) {
            return $retry_start;
        }
        return $retry_start->lt($planned_start) ? $retry_start : $planned_start;
    }

    /**
     * Strategy 4: retry_align_planned
     * In case of failure, perform retries but prefer next scheduled
     * refresh if it comes before next retry, or if next retry can
     * complete before next scheduled refresh.
     */
    public static function retry_align_planned(?Carbon $retry_start, ?Carbon $planned_start, DateInterval $timeout): ?Carbon
    {
        if (!$retry_start || !$planned_start) {
            return $retry_start;
        }
        $retry_end = $retry_start->copy()->add($timeout);
        if ($retry_end->lt($planned_start)) {
            return $retry_start;
        }
        return $planned_start;
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
