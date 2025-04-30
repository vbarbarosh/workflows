- 🔁 When to refresh
    - strategy 1: retry_until_success
    - strategy 2: retry_align_planned
    - strategy 3: retry_until_planned
- 🛑 When to give up
    - no more scheduled refreshes
    - retries exhausted
    - too much time after last successful refresh (or start)

```text
Refresh BigTable twice per day at 6 AM and 4 PM,
do retries, but give up only after a week
```

- when to reset attempt_no
- when to give up
