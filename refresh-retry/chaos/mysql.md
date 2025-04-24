The following MySQL query fetches the next BigTable to refresh.

```mysql
SELECT
    *
FROM
    big_tables
WHERE
    refresh_at <= NOW()
ORDER BY
    refresh_at
LIMIT
    1
```
