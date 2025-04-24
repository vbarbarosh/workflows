## Key moments

- scheduled refreshes (`rrule`)
- define timeout for 1 refresh to complete
- define retry strategy/policy
    - when to retry
    - how many of retries to perform
- when to give up

- we always have time to start a refresh
- it could be either planned refresh or a retry
- when everything goes planned, no questions
- instead, when something went wrong:
  - when to retry?
    - how many retries to perform?
    - what to do when retry overlaps with planned updated?
      - does sticking to scheduled time is important?
        - should we perform a retry which overlaps with planned refresh, and possible skip planned refresh?
        - or we should wait a little longer, and treat next planned refresh as a retry?
  - when to give up?
    - should it give up after all retires were exhausted?
    - or it should give up after some deadline (e.g. perform retries for a week)
