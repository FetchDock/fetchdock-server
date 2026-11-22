# FetchDock integrity digests

Manifest digests of official `fetchdock-server-api` images (ADR 0013).
Instances compare their baked `integrity-manifest.json` digest with `<version>.sha256`.
`-dev` digests older than 90 days are pruned automatically.
