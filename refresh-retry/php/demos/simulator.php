<?php

use Carbon\Carbon;

require_once __DIR__.'/../vendor/autoload.php';

main();

function main(): void
{
    global $now, $db, $jobs;

    $db = ['refresh_at' => null, 'attempt_no' => 0];
    $jobs = [];
    $now = Carbon::create('2020/01/01 00:00:00');

    info('👨‍💻 Manual Start');
    foobar(REFRESH_RETRY_START, false);

    while (true) {
        $next = array_filter([$db['refresh_at'], $jobs[0]['return_at'] ?? null]);
        usort($next, function (Carbon $a, Carbon $b) {
            return $a->getTimestamp() <=> $b->getTimestamp();
        });
        if (empty($next)) {
            info('The end');
            break;
        }
        $now = $next[0]->copy();
        process_jobs();
        process_poll();
        $now->addHour();
        sleep(1);
    }
}

function info(string $s): void
{
    global $now;
    echo sprintf("[%s] %s\n", $now->format('Y-m-d H:i'), $s);
}

function process_poll(): void
{
    global $now, $db;

    if ($db['refresh_at'] === null || $now->lt($db['refresh_at'])) {
        return;
    }
    foobar(REFRESH_RETRY_START);
}

function foobar(string $action, $log = true): void
{
    global $now, $db, $jobs;

    if ($log) {
        switch ($action) {
        case REFRESH_RETRY_START:
            info('🚀 Refresh started');
            break;
        case REFRESH_RETRY_SUCCESS:
            info(sprintf('✅ Success (%d)', $db['attempt_no']));
            break;
        case REFRESH_RETRY_FAILURE:
            info(sprintf('❌ Failure (%d)', $db['attempt_no']));
            break;
        }
    }

    refresh_retry([
        'now' => $now,
        'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
        'retry_intervals' => ['PT0M', 'PT1M', 'PT5M', 'PT10M', 'PT15M'],
        'timeout' => 'PT10M',
        'attempt_no' => $db['attempt_no'],
        'action' => $action,
        'fn' => function (RefreshAttempt $attempt) use (&$db) {
            $db['refresh_at'] = $attempt->refresh_at;
            $db['attempt_no'] = $attempt->attempt_no;
            if ($attempt->final_failure) {
                info('ℹ️ Refresh was disabled until the user reviewed it. An email about the incident was sent to the user.');
            }
        },
    ]);
    if ($action !== REFRESH_RETRY_START) {
        return;
    }

    $jobs[] = [
        'return_at' => $now->copy()->addMinutes(mt_rand(3, 5)),
        'action' => mt_rand(0, 100) > 75 ? REFRESH_RETRY_SUCCESS : REFRESH_RETRY_FAILURE,
    ];
}

function process_jobs(): void
{
    global $now, $jobs;

    if (empty($jobs)) {
        return;
    }

    if ($now->gte($jobs[0]['return_at'])) {
        foobar(array_shift($jobs)['action']);
    }
}
