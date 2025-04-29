<?php

use Carbon\Carbon;

require_once __DIR__.'/../vendor/autoload.php';

main();

// One-Week Turn-Off – stop refreshing after a week of unsuccessful attempts
function main(): void
{
    $lines = simulate([
        'limit' => 10000,
        'tick' => function (callable $info, string $action, Carbon $now, array &$db, array &$jobs) {
            refresh_retry([
                'now' => $now,
                'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
                'timeout' => 'PT10M',
                'retry_intervals' => ['PT0M', 'PT5M', 'PT10M'],
                'attempt_no' => $db['attempt_no'],
                'action' => $action,
                'fn' => function (RefreshAttempt $attempt) use ($info, &$db, $now) {
                    if ($attempt->retries_exhausted) {
                        $info('🚨 No more retries. Wait until next planned refresh.');
                        $db['refresh_at'] = $attempt->scheduled_refresh_at;
                        $db['attempt_no'] = 0;
                    }
                    else {
                        $db['refresh_at'] = $attempt->refresh_at;
                        $db['attempt_no'] = $attempt->attempt_no;
                    }
                },
            ]);
            switch ($action) {
            case REFRESH_RETRY_START:
                if (empty($db['latest_success_at'])) {
                    $db['latest_success_at'] = $now;
                }
                $jobs[] = [
                    'return_at' => $now->copy()->addMinutes(mt_rand(1, 5)),
                    'action' => mt_rand(1, 100) <= 95 ? REFRESH_RETRY_FAILURE : REFRESH_RETRY_SUCCESS,
                ];
                break;
            case REFRESH_RETRY_FAILURE:
                if ($db['latest_success_at']->diffInWeeks($now) >= 1) {
                    $db['refresh_at'] = null;
                    $info('🚧 Not a single successful refresh in over a week');
                    $info('🚧 Refresh disabled until the user reviews the model settings/configuration');
                    $info('📧 An email about the incident was sent to the user');
                    break;
                }
                break;
            case REFRESH_RETRY_SUCCESS:
                $db['latest_success_at'] = $now;
                break;
            }
        },
    ]);
    echo implode("\n", $lines), "\n";
}
