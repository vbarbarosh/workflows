# Long polling

A workflow for monitoring the output queue and either processing messages or
sleeping if the queue is empty.

- 📥 Shift items from a queue,
- ⚙️ process them,
- or 💤 sleep briefly if the queue is empty.
- 🔁 Continue for several hours, then 🚪 exit to clean up any dangling
  resources.

```
🔁 Repeat for 12 hours:
    📥 Shift 10 items from a queue
        → 🛑 Any items received?
            → 👍 Yes → ⚙️ Process them → 🔄 Repeat
            → 👎 No → 💤 Sleep → 🔄 Repeat
⏰ Once in 15 minutes:
    📡 Ping
```

```mermaid
flowchart TD
    Main["🔁 12-hour cycle"] --> Shift["📥 Shift 10 items"]
    Shift --> Any{"🛑 Any items?"}
    Any --> |👍 Yes| Process["⚙️ Process"]
    Any --> |👎 No| Sleep["💤 Sleep"]
    Main --> Timer["⏰ Every 15 minutes"]
    Timer --> Ping["📡 Ping"]
```
