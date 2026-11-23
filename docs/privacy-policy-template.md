# Instance privacy policy

FetchDock can generate and publish your instance's privacy policy automatically — you no longer need to maintain a static policy file by hand for an unmodified deployment.

## Using the built-in generator (recommended)

1. Leave `PRIVACY_POLICY_URL` unset.
2. Open the admin web app and go to **Privacy policy**.
3. Fill in the required operator details (instance name, operator name and contact email, identity provider, hosting provider and country, log retention, legal basis), save, review the preview, and click **Approve & publish**.

The server renders the policy from:
- a CI-verified data inventory of everything FetchDock stores and sends (`api/privacy/data-inventory.yaml`),
- your instance's runtime configuration (failure reports, upstream forwarding, site defaults, `ROLE_VIEW_ALL_DOWNLOADS`), and
- the answers you entered in the admin app.

Once you approve and publish a revision and the instance's code integrity check reads **verified** (official unmodified image with `INTEGRITY_CHECK_ONLINE=1`), the policy is published at `/privacy` and linked by the browser extension and admin footer. Earlier revisions stay readable at `/privacy?revision=N`.

FetchDock **never** modifies or replaces a published privacy policy automatically. Whenever FetchDock's policy text, data inventory, or instance configuration changes, `/privacy` continues serving your last approved revision with a calm pending-review notice while the admin app shows a prominent warning until you review the changes and publish a new revision.

### What the generated policy covers

- **Controller & no-selling commitment:** names you as the data controller, confirms user data is not sold or used for advertising or tracking, and notes that the FetchDock project only operates `api.fetchdock.dev` (and `staging.fetchdock.dev` for testing).
- **Legal bases (GDPR):** covers providing the download service (`contract`, Art. 6(1)(b), or `legitimate_interest`, Art. 6(1)(f)), logs and security (Art. 6(1)(f)), and optional problem reports (consent, Art. 6(1)(a)).
- **Stored data & visibility:** lists every category of personal data stored in the database and outside it (OIDC account identifier with no password, settings, download jobs and temporary cookies, downloaded media, registered apps/synced preferences, problem reports, and server/web access logs) along with who can see it in the app.
- **Retention:** notes that cookies are cleared when a download completes successfully, deleting a job soft-deletes it without removing shared files from disk, and includes your configured log, soft-deleted job, report, and backup retention periods.
- **Connections to other parties:** discloses requests to submitted websites (showing server and per-site defaults while emphasizing users always have the final say via their own settings), your OIDC identity provider, hosting provider and country, and any additional processors you list.
- **Problem reports:** explains that reports are strictly opt-in, never include cookies, may be shared (without the user's account identifier) with FetchDock maintainers if support is needed, and are permanently and irrevocably deleted from the server when withdrawn (unless already shared before withdrawal).
- **Software integrity & user rights:** explains that this generated policy is only published while the integrity check passes (as a signal, not proof), and lists GDPR rights (access, correction, deletion, restriction, portability, objection, withdrawing consent, and complaining to a supervisory authority).

> This generator is not legal advice. You remain the controller of your instance's personal data and adopt the generated text (have it reviewed for your jurisdiction if needed).

## Publishing a custom policy (`PRIVACY_POLICY_URL`)

You need your own hosted privacy policy if:
- you run a fork or locally modified build (the integrity check reads `modified` or `unknown`),
- your instance runs offline or with `INTEGRITY_CHECK_ONLINE=0` (the integrity check reads `unknown`), or
- you prefer to write and host your own legal text.

Set `PRIVACY_POLICY_URL` to your policy's public URL (see [`deploy/README.md`](../deploy/README.md)). Clients will link to that URL instead of `/privacy`.

If you need a starting point for a custom policy, use the static starter template in [`docs/privacy-policy-custom-template.md`](privacy-policy-custom-template.md). You can also open the preview under **Admin → Privacy policy** to see what your current configuration stores and sends.

## Disabling the privacy policy (`PRIVACY_POLICY_ENABLED=0`)

If you self-host FetchDock purely for personal or household use where no privacy policy is required, set `PRIVACY_POLICY_ENABLED=0` (see [`deploy/README.md`](../deploy/README.md)). When disabled:
- no privacy policy is published (`/privacy` returns `404` and `privacyPolicyUrl` is omitted from well-known),
- the admin app hides the privacy policy reminder banner, and
- container logs omit privacy policy update warnings.

Any previously published revisions remain readable at `/privacy?revision=N`.
