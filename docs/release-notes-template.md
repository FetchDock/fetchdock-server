# Release notes template

FetchDock server releases are written by hand on GitHub. Copy the sections that apply into the release body before publishing.

## Generating the "Data practices changed" section

Whenever `api/privacy/data-inventory.yaml` or `api/templates/privacy/` changes, `PolicyVersionTest` requires a new entry in `api/privacy/policy-version.yaml`. Before publishing a release, generate the **Data practices changed** section from the entries added since the previous release:

```bash
make privacy-release-notes SINCE=<previous-tag>
```

If `SINCE` is omitted, it compares against the latest stable semver tag.
- **Entries were added:** paste the Markdown output into the release body below *Upgrade notes*.
- **No entries were added:** the command prints nothing to stdout; omit the *Data practices changed* section.

## Template

```markdown
<!-- Short summary of the release and any extension compatibility note. -->

## Security
<!-- Omit if there are no security fixes. -->
- **[GHSA-xxxx-xxxx-xxxx](https://github.com/FetchDock/fetchdock-server/security/advisories/GHSA-xxxx-xxxx-xxxx)** (severity): short summary (#PR).

## Highlights
- **Feature:** short summary (#PR).

## Fixes
<!-- Omit if there are no notable fixes. -->
- **Area:** short summary (#PR).

## Upgrade notes
- **Compatibility:** additive / breaking changes for the browser extension and API clients.
- **Configuration / environment:** new or changed environment variables.
- **Database migrations:** note any non-trivial migrations or startup tasks.

## Data practices changed
<!--
  Generated with `make privacy-release-notes SINCE=<previous-tag>`.
  Omit this section when no entries were added to api/privacy/policy-version.yaml since the previous release.
-->
The data inventory or the generated privacy policy text changed (policy text version X.Y → X.Z):
- **X.Z:** <summary from api/privacy/policy-version.yaml>

If the instance publishes the generated policy, review the changes and your answers under *Admin → Privacy policy*, then explicitly approve and publish a new revision. Until you publish, `/privacy` keeps serving your last approved revision with a pending-review notice. If you link your own policy through `PRIVACY_POLICY_URL`, check that it still matches.

## What's Changed
<!-- Click "Generate release notes" on GitHub for the PR list and full changelog link. -->
```
