<?php

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefreshRetryTest extends TestCase
{
    #[Test] // action: Should throw "Invalid action: ..."
    public function should_throw___invalid_action(): void
    {
        $this->assetThrows('/^Invalid action: \w+$/', function () {
            refresh_retry(['action' => 'foo']);
        });
    }

    #[Test] // rrule: Should throw "$rrule must be a valid RRULE expression or null"
    public function should_throw___rrule_must_be_a_valid_rrule_expression_or_null(): void
    {
        $regexp = '/^\$rrule must be a valid RRULE expression \(or null\)/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['rrule' => '']);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['rrule' => 'RRULE:foo']);
        });
    }

    #[Test] // attempt_no: Should throw "$attempt_no must be a non-negative integer: ..."
    public function should_throw___attempt_no_must_be_a_non_negative_integer(): void
    {
        $regexp = '/^\$attempt_no must be a non-negative integer:.+$/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['attempt_no' => -1]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['attempt_no' => '']);
        });
    }

    #[Test] // timeout: Should throw "$timeout must be a valid DateInterval expression (or instance) with an interval greater than zero"
    public function should_throw___timeout_must_be_a_valid_DateInterval_expression_or_instance_with_an_interval_greater_than_zero(): void
    {
        $regexp = '/^\$timeout must be a valid DateInterval expression \(or instance\) with an interval greater than zero/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['timeout' => 0]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['timeout' => 'P0M']);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['timeout' => new DateInterval('P0M')]);
        });
        $this->assetThrows($regexp, function () {
            $timeout = new DateInterval('PT1M');
            $timeout->invert = true;
            refresh_retry(['timeout' => $timeout]);
        });
    }

    #[Test] // retry_intervals: Should throw "$retry_intervals Must be an array (or null) of DateInterval expressions (empty values for immediate retry)"
    public function should_throw___retry_intervals_must_be_an_array_of_DateInterval_expressions(): void
    {
        $regexp = '/\$retry_intervals Must be an array \(or null\) of DateInterval expressions \(empty values for immediate retry\)/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['retry_intervals' => 1]);
        });
    }

    #[Test] // Basic • start → success
    public function basic___start_success(): void
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

    #[Test] // Basic • start → final_failure
    public function basic___start_final_failure(): void
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
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 1,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertTrue($attempt->final_failure);
            },
        ]);
    }

    #[Test] // Basic • start → failure[1] → start → success
    public function basic___start_failure1_start_success(): void
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
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now, $attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'attempt_no' => 1,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it succeeds after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'success',
            'attempt_no' => 2,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(0, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
    }

    #[Test] // Basic • start → failure[1] → start → final failure
    public function basic___start_failure1_start_final_failure(): void
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
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now, $attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        refresh_retry([
            'now' => $now,
            'action' => 'start',
            'attempt_no' => 1,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 2,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertTrue($attempt->final_failure);
            },
        ]);
    }

    #[Test] // Basic • start → failure[1] → start → failure[2] → start → failure[3] → start → final_failure
    public function basic___start_failure1_start_failure2_start_failure3_start_final_failure(): void
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
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 1,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
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
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 2,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
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
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(3, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 3,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
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
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertFalse($attempt->final_failure);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => 'failure',
            'attempt_no' => 4,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertTrue($attempt->final_failure);
            },
        ]);
    }

    private function assetThrows(string $regex, callable $fn): void
    {
        try {
            call_user_func($fn);
            $this->assertFalse("An exception matching >>$regex<< is expected");
        }
        catch (Throwable $exception) {
            $this->assertMatchesRegularExpression($regex, $exception->getMessage());
        }
    }
}
