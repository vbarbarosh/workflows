<?php

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefreshRetryTest extends TestCase
{
    public static function static_tests_provider(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/scenarios/*') as $dir) {
            $script = "$dir/index.php";
            $stdout = file_get_contents("$dir/stdout.txt");
            $out[] = [$script, $stdout];
        }
        return $out;
    }

    #[Test] #[\PHPUnit\Framework\Attributes\DataProvider('static_tests_provider')]
    public function static_tests(string $script, string $stdout): void
    {
        $actual = shell_exec("php $script");
        $this->assertEquals($stdout, $actual);
    }

    #[Test] // Should throw "$rrule must be a valid RRULE expression or null"
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

    #[Test] // Should throw "$timeout must be a valid DateInterval expression (or instance) with an interval greater than zero"
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

    #[Test] // Should throw "$attempt_no must be a non-negative integer: ..."
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

    #[Test] // Should throw "$retry_intervals Must be an array (or null) of DateInterval expressions (empty values for immediate retry)"
    public function should_throw___retry_intervals_must_be_an_array_of_DateInterval_expressions(): void
    {
        $regexp = '/\$retry_intervals Must be an array \(or null\) of DateInterval expressions \(empty values for immediate retry\)/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['retry_intervals' => 1]);
        });
    }

    #[Test] // Should throw "$action Must be one of: start, success, failure"
    public function should_throw___action_must_be_one_of_start_success_failure(): void
    {
        $regexp = '/^\$action Must be one of: start, success, failure/';
        $this->assetThrows($regexp, function () {
            refresh_retry([]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['action' => null]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['action' => 'foo']);
        });
    }

    #[Test] // Should throw "$fn Must be a callable"
    public function should_throw___fn_must_be_a_callable(): void
    {
        $regexp = '/^\$fn Must be a callable/';
        $this->assetThrows($regexp, function () {
            refresh_retry(['action' => REFRESH_RETRY_START]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['action' => REFRESH_RETRY_START, 'fn' => null]);
        });
        $this->assetThrows($regexp, function () {
            refresh_retry(['action' => REFRESH_RETRY_START, 'fn' => true]);
        });
    }

    #[Test] // Should throw "Invalid parameters: ..."
    public function should_throw___invalid_parameters(): void
    {
        $this->assetThrows('/^Invalid parameters: \w+$/', function () {
            refresh_retry([
                'foo' => null,
                'action' => REFRESH_RETRY_START,
                'fn' => fn () => 0,
            ]);
        });
    }

    #[Test] // Non-recurring • start → success
    public function non_recurring___start_success(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        $now->addMinutes(5);
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_SUCCESS,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(0, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
    }

    #[Test] // Non-recurring • start → retries_exhausted
    public function non_recurring___start_retries_exhausted(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 1,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertTrue($attempt->retries_exhausted);
            },
        ]);
    }

    #[Test] // Non-recurring • start → failure[1] → start → success
    public function non_recurring___start_failure1_start_success(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now, $attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it succeeds after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_SUCCESS,
            'attempt_no' => 2,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(0, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
    }

    #[Test] // Non-recurring • start → failure[1] → start → retries_exhausted
    public function non_recurring___start_failure1_start_retries_exhausted(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now, $attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'attempt_no' => 1,
            'retry_intervals' => [0],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 2,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertTrue($attempt->retries_exhausted);
            },
        ]);
    }

    #[Test] // Non-recurring • start → failure[1] → start → failure[2] → start → failure[3] → start → retries_exhausted
    public function non_recurring___start_failure1_start_failure2_start_failure3_start_retries_exhausted(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 1,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // The first retry is immediate
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'attempt_no' => 1,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(10)->addMinutes(5)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 2,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(5)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(2, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // The second retry is after 5 minutes
        $now->addMinutes(5);
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'attempt_no' => 2,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(10)->addMinutes(15)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(3, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 3,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addMinutes(15)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(3, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // The third retry is after 15 minutes
        $now->addMinutes(15);
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_START,
            'attempt_no' => 3,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        // Let's pretend it failed after 1 minute
        $now->addMinute();
        refresh_retry([
            'now' => $now,
            'action' => REFRESH_RETRY_FAILURE,
            'attempt_no' => 4,
            'retry_intervals' => [0, 'PT5M', 'PT15M'],
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertNull($attempt->refresh_at);
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(4, $attempt->attempt_no);
                $this->assertTrue($attempt->retries_exhausted);
            },
        ]);
    }

    #[Test] // Recurring • start → success → start
    public function recurring___start_success_start(): void
    {
        $now = Carbon::parse('2025/01/01');
        refresh_retry([
            'now' => $now,
            'rrule' => "DTSTART:20250101T000000Z\nRRULE:FREQ=HOURLY;INTERVAL=2",
            'action' => REFRESH_RETRY_START,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->addHours(2)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertSame($now->copy()->addMinutes(10)->toJSON(), $attempt->deadline_at->toJSON());
                $this->assertSame(1, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
            },
        ]);
        $now->addMinutes(5);
        refresh_retry([
            'now' => $now,
            'rrule' => "DTSTART:20250101T000000Z\nRRULE:FREQ=HOURLY;INTERVAL=2",
            'action' => REFRESH_RETRY_SUCCESS,
            'fn' => function (RefreshAttempt $attempt) use ($now) {
                $this->assertSame($now->copy()->startOfHour()->addHours(2)->toJSON(), $attempt->refresh_at->toJSON());
                $this->assertNull($attempt->deadline_at);
                $this->assertSame(0, $attempt->attempt_no);
                $this->assertFalse($attempt->retries_exhausted);
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
