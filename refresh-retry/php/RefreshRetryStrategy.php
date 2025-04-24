<?php

use Carbon\Carbon;

class RefreshRetryStrategy
{
    /**
     * Strategy 1: retry_until_success
     * In case of failure, perform retries until success.
     * Scheduled refresh could follow only after successful attempt.
     */
    public static function retry_until_success(Carbon $retry_start, Carbon $retry_end, Carbon $planned_start, Carbon $planned_end): Carbon
    {
        return $retry_start;
    }

    /**
     * Strategy 2: retry_align_planned
     * In case of failure, perform retries but prefer next scheduled
     * refresh if it comes before next retry, or if next retry can
     * complete before next scheduled refresh.
     */
    public static function retry_align_schedule(Carbon $retry_start, Carbon $retry_end, Carbon $planned_start, Carbon $planned_end): Carbon
    {
        if ($retry_end->lt($planned_start)) {
            return $retry_start;
        }
        return $planned_start;
    }

    /**
     * Strategy 3: retry_until_planned
     * In case of failure, perform retries but stop when next retry
     * can complete after next scheduled refresh.
     * Each scheduled refresh is treated as a new base attempt (attempt_no = 0).
     */
    public static function retry_until_schedule(Carbon $retry_start, Carbon $retry_end, Carbon $planned_start, Carbon $planned_end): Carbon
    {
        if ($retry_start->lt($planned_start)) {
            return $retry_start;
        }
        return $planned_start;
    }
}
