# Real-World Scenarios

## Create a thumbnail of a banner after editing

A banner was edited. Immediately after it is saved, a request to the thumbnailer
service should be made to create a fresh thumbnail. However, this request might
fail. If, after several attempts, the thumbnails still cannot be created, mark
the banner with "thumbnail creation failed".

1. Send a request to the thumbnailer service.
2. Allow 1 minute to complete.
3. Retry policy:
    - On the first failure, retry immediately.
    - On the second failure, retry after 1 minute.
    - On the third failure, retry after 5 minutes.
    - On the fourth failure, mark the banner with "thumbnail creation failed."
4. Give up after the final unsuccessful retry.

## Refresh BigTable twice per day at 6 AM and 4 PM

1. Refresh BigTable twice per day at 6 AM and 4 PM
2. Allow 1 hour to complete
3. Retry policy:
    - On the first failure, retry immediately.
    - On the second failure, retry after 1 minute.
    - On the third failure, retry after 5 minutes.
    - On the fourth failure, retry after 15 minutes.
    - On the fifth failure, retry after 30 minutes.
    - On the sixth failure, retry after 1 hour.
4. Give up after the final unsuccessful retry:
    - Disable auto-refresh.
    - Email the customer that the BigTable refresh has been disabled.

## Refresh BigTable twice per day at 6 AM and 4 PM, do retries, but give up only after a week

1. Refresh BigTable twice per day at 6 AM and 4 PM
2. Allow 1 hour to complete
3. Retry policy:
    - On the first failure, retry immediately.
    - On the second failure, retry after 1 minute.
    - On the third failure, retry after 5 minutes.
    - On the fourth failure, retry after 15 minutes.
    - On the fifth failure, retry after 30 minutes.
    - On the sixth failure, retry after 1 hour.
    - On the seventh failure:
        - Reset `attempt_no`
        - Wait until next scheduled refresh
4. Give up after one week of unsuccessful refresh attempts:
    - Disable auto-refresh
    - Send the customer an email notifying them that BigTable refresh has been disabled
