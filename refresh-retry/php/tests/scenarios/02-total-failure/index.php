<?php

use Carbon\Carbon;

require_once __DIR__.'/../../../vendor/autoload.php';

main();

// Total Failure Path
function main(): void
{
    mt_srand(12345);

    $lines = simulate([
        'limit' => 100,
        'tick' => function (callable $info, string $action, Carbon $now, array &$db, array &$jobs) {
            refresh_retry([
                'now' => $now,
                'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
                'timeout' => 'PT10M',
                'retry_intervals' => ['PT0M', 'PT5M', 'PT10M'],
                'attempt_no' => $db['attempt_no'],
                'action' => $action,
                'fn' => function (RefreshAttempt $attempt) use ($info, &$db, $now) {
                    $db['refresh_at'] = $attempt->refresh_at;
                    $db['attempt_no'] = $attempt->attempt_no;
                    if ($attempt->retries_exhausted) {
                        $info('🚧 Refresh was disabled until the user reviewed the model settings/configuration');
                        $info('📧 An email about the incident was sent to the user');
                    }
                },
            ]);
            if ($action === REFRESH_RETRY_START) {
                $jobs[] = [
                    'return_at' => $now->copy()->addMinutes(mt_rand(1, 5)),
                    'action' => REFRESH_RETRY_FAILURE,
                ];
            }
        },
    ]);
    echo implode("\n", $lines), "\n";
}
