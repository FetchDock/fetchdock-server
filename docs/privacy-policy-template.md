# Instance privacy policy

FetchDock can generate and publish your instance's privacy policy automatically — you no longer need to maintain a static policy file by hand for an unmodified deployment.

## Using the built-in generator (recommended)

1. Leave `PRIVACY_POLICY_URL` unset.
2. Open the admin web app and go to **Privacy policy**.
3. Fill in the required operator details (instance name, operator name and contact email, identity provider, hosting provider and country, log retention, legal basis) and save.

The server renders the policy from:
- a CI-verified data inventory of everything FetchDock stores and sends (`api/privacy/data-inventory.yaml`),
- your instance's runtime configuration (failure reports, upstream forwarding, site defaults, `ROLE_VIEW_ALL_DOWNLOADS`), and
- the answers you entered in the admin app.

Once the required answers are saved and the instance's code integrity check reads **verified** (official unmodified image with `INTEGRITY_CHECK_ONLINE=1`), the policy is published automatically at `/privacy` and linked by the browser extension and admin footer. Every change to your answers, FetchDock's version/policy text or relevant configuration creates a new numbered revision; earlier revisions stay readable at `/privacy?revision=N`.

> This generator is not legal advice. You remain the controller of your instance's personal data and adopt the generated text (have it reviewed for your jurisdiction if needed).

## Publishing a custom policy (`PRIVACY_POLICY_URL`)

You need your own hosted privacy policy if:
- you run a fork or locally modified build (the integrity check reads `modified` or `unknown`),
- your instance runs offline or with `INTEGRITY_CHECK_ONLINE=0` (the integrity check reads `unknown`), or
- you prefer to write and host your own legal text.

Set `PRIVACY_POLICY_URL` to your policy's public URL (see [`deploy/README.md`](../deploy/README.md)). Clients will link to that URL instead of `/privacy`.

If you need a starting point for a custom policy, use the static starter template in [`docs/privacy-policy-custom-template.md`](privacy-policy-custom-template.md). You can also open the preview under **Admin → Privacy policy** to see what your current configuration stores and sends.
