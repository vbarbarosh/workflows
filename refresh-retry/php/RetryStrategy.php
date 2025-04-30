<?php

use Carbon\Carbon;

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
