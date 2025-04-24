## How it can be expressed in code

```php
refresh_retry([
    'rrule' => 'RRULE:FREQ=HOURLY;INTERVAL=2',
    'timeout' => '1 hour',
    'retries' => [
        0,
        '1 minute',
        '5 minute',
        '10 minutes',
        '15 minutes',
    ],
]);
```

```php
refresh_retry([
    'expr' => '
        - Refresh the model every 2 hours
        - Allow 1 hour for a response
        - On the first failure, retry immediately
        - On the second failure, retry after 1 minute
        - On the third failure, retry after 5 minutes
        - On the fourth failure, retry after 10 minutes
        - On the fifth failure, retry after 15 minutes
        - Panic
    ',
]);
```

```php
public function refresh_retry(string $action): void
{
    refresh_retry([
        'rrule' => $this->refresh_rrule,
        'timeout' => 'PT1H',
        'attempt_no' => $this->refresh_attempt,
        'retry_intervals' => [0, 'PT5M', 'PT10M', 'PT15M', 'PT30M', 'PT1H'],
        'action' => $action,
        'fn' => function (RefreshAttempt $attempt) use ($action) {
            Log::info(sprintf('[big_table_refresh_retry] %s | %s | %s', $action, $this->pub_id, json_encode($attempt)));
            $this->refresh_at = $attempt->refresh_at;
            $this->deadline_at = $attempt->deadline_at;
            $this->refresh_attempt = $attempt->attempt_no;
            if ($attempt->final_failure) {
                $this->is_disabled_until_update = true;
                $this->user_friendly_disabled_message = 'Something is wrong with the provided url. Please replace it and try again.';
            }
        },
    ]);
    if ($action !== REFRESH_RETRY_START) {
        $this->save();
        return;
    }
    // [...]
}
```

```php
switch ($action) {
case 'start':
    if (!$model->refresh_final_failure_at) {
        $model->refresh_final_failure_at = now()->addWeek();
    }
    break;
case 'success':
    $model->refresh_final_failure_at = null;
    break;
}
refresh_retry([
    'rrule' => 'RRULE:FREQ=DAILY;BYHOUR=6,16;BYMINUTE=0;BYSECOND=0',
    'timeout' => 'PT1H',
    'attempt_no' => $model->refresh_attempt,
    'retry_intervals' => ['PT0M', 'PT1M', 'PT5M', 'PT15M', 'PT30M', 'PT1H', 'NEXT'],
    'final_failure_at' => $model->refresh_final_failure_at,
    'action' => 'start',
    'fn' => function (RefreshAttempt $attempt) use ($model) {
        $model->refresh_at = $attempt->refresh_at;
        $model->refresh_attempt = $attempt->attempt_no;
        if ($attempt->final_failure) {
            $this->is_disabled_until_update = true;
            $this->user_friendly_disabled_message = 'Something is wrong with the provided url. Please replace it and try again.';
        }
    },
])
```
