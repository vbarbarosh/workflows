Some scenarios to consider

### 1️⃣ Rate-Limited API with Reset Time

#### Scenario

You're calling an external API that enforces rate limits.
When you hit the limit, the API responds with a `Retry-After` header
that tells you exactly when you're allowed to try again.

### 2️⃣ Progressive Backoff Based on Failure Reason

#### Scenario

A payment processor may fail for different reasons:

- Temporary network error: retry soon.
- Insufficient funds: retry much later.
- Permanent failure: do not retry.

### 3️⃣ Retry Based on Business Hours

#### Scenario

A job must only retry during business hours (e.g., 9 AM–5 PM).
If it fails after hours, it should schedule the next attempt for
the next business day.

### 4️⃣ User-Driven Retry (Manual Approval)

#### Scenario

A document-processing workflow failed and requires manual
review/approval before retrying. You want the retry time
to be the exact time the user clicks “Retry” in the admin
panel.

### 5️⃣ Retry Based on Queue Size / System Load

#### Scenario

You have a background job queue. If the queue is long (high load),
you want to delay retries to avoid overload; if the queue is empty,
you retry quickly.

### 6️⃣ Retry Based on Previous Attempts (Exponential Backoff with Jitter)

#### Scenario

You want exponential backoff but add randomness (jitter)
to avoid retry spikes (common in distributed systems).
