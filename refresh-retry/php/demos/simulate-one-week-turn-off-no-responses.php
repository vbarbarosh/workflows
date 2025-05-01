<?php

use Carbon\Carbon;

require_once __DIR__.'/../vendor/autoload.php';

main();

// One-Week Turn-Off – stop refreshing after a week of unsuccessful attempts
function main(): void
{
    $lines = simulate([
        'limit' => 1000,
        'tick' => function (callable $info, string $action, Carbon $now, array &$db, array &$jobs) {
            $db['latest_success_at'] ??= $now;
            if ($db['latest_success_at']->diffInWeeks($now) >= 1) {
                if ($action !== REFRESH_RETRY_SUCCESS) {
                    // 🏳️ Give up
                    $db['refresh_at'] = null;
                    $info('🚧 Not a single successful refresh in over a week');
                    $info('🚧 Refresh disabled until the user reviews the model settings/configuration');
                    $info('📧 An email about the incident was sent to the user');
                    return;
                }
            }
            refresh_retry([
                'now' => $now,
                'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
                'timeout' => 'PT10M',
                'retry_intervals' => ['PT0M', 'PT5M', 'PT10M'],
                'attempt_no' => $db['attempt_no'],
                'action' => $action,
                'fn' => function (RefreshAttempt $attempt) use ($info, &$db, $now, &$retries_exhausted) {
                    if ($attempt->retries_exhausted) {
                        $info('🚨 No more retries. Wait until next planned refresh.');
                        $db['refresh_at'] = $attempt->planned_at;
                        $db['attempt_no'] = 0;
                    }
                    else {
                        $db['refresh_at'] = $attempt->refresh_at;
                        $db['attempt_no'] = $attempt->attempt_no;
                    }
                },
            ]);
            switch ($action) {
            case REFRESH_RETRY_SUCCESS:
                $db['latest_success_at'] = $now;
                break;
            case REFRESH_RETRY_START:
                // Start
                break;
            }
        },
    ]);
    echo implode("\n", $lines), "\n";
}
