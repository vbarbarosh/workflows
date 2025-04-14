<?php

require_once __DIR__.'/vendor/autoload.php';

main();

function main(): void
{
    refresh_retry([
        'action' => 'start',
        'fn' => function (RefreshAttempt $attempt) {
            var_dump([
                'refresh_at' => empty($attempt->refresh_at) ? null : $attempt->refresh_at->toJSON(),
                'deadline_at' => empty($attempt->deadline_at) ? null : $attempt->deadline_at->toJSON(),
                'attempt_no' => $attempt->attempt_no,
                'final_failure' => $attempt->final_failure,
            ]);
        },
    ]);
}
