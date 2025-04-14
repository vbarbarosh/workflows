<?php

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefreshRetryTest extends TestCase
{
    #[Test] // Empty RRule • start → success
    public function empty_rrule___start_success(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        $now->addMinutes(5);
        refresh_retry([
            'now' => $now,
            'action' => 'success',
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(0, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
    }

    #[Test] // Empty RRule • start → failure[1] → start → failure[2] → start → failure[3] → start → final_failure
    public function empty_rrule___start_failure1_start_failure2_start_failure3_start_final_failure(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'retry_delay' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it fails after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 1,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // The first retry is immediate
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'attempt_no' => 1,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it fails after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 2,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(5)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // The second retry is after 5 minutes
        $now->addMinutes(5);
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'attempt_no' => 2,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(3, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it fails after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 3,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(15)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(3, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // The third retry is after 15 minutes
        $now->addMinutes(15);
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'attempt_no' => 3,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it fails after 1 minute. This retry was final.
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 4,
            'retry_delay' => ['PT0M', 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertTrue($attempt->final_failure);
            },
        ]);
    }
}
