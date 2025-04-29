<?php

use Carbon\Carbon;

function simulate(array $params): array
{
    $out = [];
    $now = Carbon::create('2020/01/01 00:00:00');
    $db = ['refresh_at' => null, 'attempt_no' => 0];
    $jobs = [];

    $info = function (string $message) use (&$now, &$out) {
        $out[] = sprintf('[%d][%s] %s', count($out) + 1, $now->format('Y-m-d H:i'), $message);
    };

    $tick = function (string $action) use ($params, $info, &$now, &$db, &$jobs) {
        switch ($action) {
        case REFRESH_RETRY_START:
            if ($db['attempt_no'] === 0) {
                $info('🚀 Refresh started');
            }
            else {
                $info("🔄 Retry started #{$db['attempt_no']}");
            }
            break;
        case REFRESH_RETRY_SUCCESS:
            $info('✅ Success');
            break;
        case REFRESH_RETRY_FAILURE:
            $info("❌ Failure ({$db['attempt_no']})");
            break;
        }
        $params['tick']($info, $action, $now, $db, $jobs);
    };

    $tick(REFRESH_RETRY_START);

    $limit = $params['limit'] ?? 100;
    $iteration = 0;

    while ($iteration++ < $limit) {
        // $jobs = []; // Simulate sudden job process termination

        // Find next event time
        $next = array_filter([$db['refresh_at'], ...array_map(fn ($v) => $v['return_at'], $jobs)]);
        if (empty($next)) {
            $info('🚪 The end. Bye! 👋');
            break;
        }

        usort($next, function (Carbon $a, Carbon $b) {
            return $a->getTimestamp() <=> $b->getTimestamp();
        });

        $now = $next[0]->copy();

        // Process jobs that are due
        if (count($jobs) && $now->gte($jobs[0]['return_at'])) {
            $job = array_shift($jobs);
            $tick($job['action']);
        }

        // Process poll if refresh is due
        if ($db['refresh_at'] && $now->gte($db['refresh_at'])) {
            $tick(REFRESH_RETRY_START);
        }
    }

    return $out;
}
