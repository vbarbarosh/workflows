<?php

use Carbon\Carbon;

require_once __DIR__.'/../vendor/autoload.php';

main();

function main(): void
{
    $lines = simulate([
        'limit' => 200,
        'tick' => function (callable $info, string $action, Carbon $now, array &$db, array &$jobs) {
            refresh_retry([
                'now' => $now,
                'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
                'timeout' => 'PT10M',
                'retry_intervals' => ['PT0M', 'PT5M', 'PT10M'],
                'attempt_no' => $db['attempt_no'],
                'action' => $action,
                'fn' => function (RefreshAttempt $attempt) use ($info, &$db, $now) {
                    if (time()) {
                        if ($attempt->retries_exhausted) {
                            $info('🚨 No more retries. Wait until next planned refresh.');
                            $db['refresh_at'] = $attempt->scheduled_refresh_at;
                            $db['attempt_no'] = 0;
                            return;
                        }
                    }
                    $db['refresh_at'] = $attempt->refresh_at;
                    $db['attempt_no'] = $attempt->attempt_no;
                    if ($attempt->retries_exhausted) {
                        $info('🚧 Refresh was disabled until the user reviewed the model settings/configuration');
                        $info('📧 An email about the incident was sent to the user');
                    }
                },
            ]);
            if ($action === REFRESH_RETRY_FAILURE) {
                if ($db['latest_success_at']->diffInWeeks($now) >= 1) {
                    $db['refresh_at'] = null;
                    $info('🚧 No successful refresh in over a week.');
                    $info('🚧 Refresh was disabled until the user reviewed the model settings/configuration');
                    $info('📧 An email about the incident was sent to the user');
                    return;
                }
            }
            // Uncomment to simulate situations where the job process dies without
            // sending any event and no other timeout handler is configured.
//            if (time()) {
//                return;
//            }
            if ($action === REFRESH_RETRY_START) {
                if (empty($db['latest_success_at'])) {
                    $db['latest_success_at'] = $now;
                }
                $jobs[] = [
                    'return_at' => $now->copy()->addMinutes(mt_rand(1, 5)),
                    'action' => REFRESH_RETRY_FAILURE,
                ];
            }
        },
    ]);
    echo implode("\n", $lines), "\n";
}
