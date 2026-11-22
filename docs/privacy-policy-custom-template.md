<!--
FetchDock instance privacy policy: static starter template for operators using a custom policy (PRIVACY_POLICY_URL).

- Most operators do not need this file: FetchDock generates and publishes a privacy policy automatically at /privacy
  from its verified data inventory, runtime configuration and your answers under Admin → Privacy policy. See
  docs/privacy-policy-template.md.
- Use this template when you set PRIVACY_POLICY_URL ("custom" mode): for example if you run a fork or locally modified
  build, run offline (INTEGRITY_CHECK_ONLINE=0), or prefer to maintain your own policy text.
- This is a starting point, not legal advice. Have it reviewed for your jurisdiction (GDPR if you or your users are in
  the EU/EEA or UK).
- Replace every [PLACEHOLDER] and delete sections for features you have turned off.
- Publish the result and set PRIVACY_POLICY_URL so the browser extension and the admin app link to it.
- Set FAILURE_REPORTS_ENABLED=0 to turn failure reports off (then delete sections 5 and 6). See deploy/README.md.
-->

# Privacy policy for [INSTANCE NAME]

**Last updated:** [DATE]

This policy explains what personal data [INSTANCE NAME] (the "service"), a self-hosted installation of the open-source software FetchDock, processes when you use it through the FetchDock browser extension or the admin web app.

The service is run by [OPERATOR NAME, ADDRESS, CONTACT EMAIL] (the "operator", "we"). The FetchDock project develops the software but does not run this service and has no access to its data, except for failure reports that you choose to share with it (see section 6).

## 1. Who is responsible
The operator is the controller of the personal data described here. Contact: [CONTACT EMAIL]. [If applicable: our data protection officer is [NAME, CONTACT].]

## 2. Signing in
You sign in through [IDENTITY PROVIDER, e.g. "our Authentik server" / "Google"]. The service receives an identifier for your account (the OIDC subject) and the roles or groups the identity provider sends. It does not receive or store your password. [Name the identity provider's own privacy policy if it is a third party.]

The service stores: your account identifier and the date it was first seen.

## 3. Downloads
When you ask the service to download something, it stores:

| Data | Why | How long |
|---|---|---|
| The URL you submitted and the site it belongs to | To perform the download and detect duplicates | [Until you delete the job / X days] |
| Your browser's **cookies** for that site, if your settings allow sending them | Some sites only serve media to signed-in visitors | [Until the job finishes / until you delete the job]. Cookies can give access to your accounts on those sites; [describe how they are protected] |
| Your browser's user agent and the referring page, if your settings allow sending them | Some sites require them | Same as the job |
| Job status, progress and error messages | To show you the result and troubleshoot | Same as the job |
| The downloaded files | To make them available to you | [Until deleted by you or an admin / X days] |

Deleting a job in the extension hides it from you (a "soft delete"); administrators can still see and restore it until it is permanently deleted. [Describe whether and when soft-deleted jobs are purged.]

Downloaded files are reused across users: if another user downloads the same media, the service may link the existing file instead of downloading it again. [State whether administrators can see who downloaded what: users with the "view all downloads" role can.]

To perform downloads, the service connects to the websites you submit from [the operator's server / location]. Those websites see the service's IP address and, if sent, your cookies, user agent and referrer, and process them under their own privacy policies.

## 4. Devices, settings and preferences
The service stores the browser extension installations you register (a generated identifier, a name you can change, the type, and when it was last seen), your download settings per site and downloader, and interface preferences that sync between your devices (such as theme and notification choices). It keeps them until you delete them or your account is removed.

## 5. Failure reports
You can report a failed download or a page FetchDock can't handle. Before sending, the extension shows you everything the report contains. A report contains the URL, your optional comment and, only if you tick them, your browser's user agent, the referring page and technical details (downloader, version, error message, extension version). It never contains cookies.

The service stores the report with a link to your account so that you can see and withdraw it, and so it can limit duplicate reports. Administrators see the report contents but not who sent it. Administrators may add internal notes. We keep reports [until resolved plus X months / until deleted by an admin]. Withdrawing a report deletes it.

## 6. Sharing reports with the FetchDock developers (only if enabled)
[Delete this section if you do not enable upstream forwarding.]

If we enable it, you can tick "Also share with FetchDock developers" on an individual report. Only then is a copy sent to the FetchDock project's report collector, without your account identifier or any link to your downloads, together with a random identifier for this installation and its software version. An administrator may [review it before it is sent and] add a comment for the developers. The FetchDock project is then the controller for that copy under its own policy: [COLLECTOR POLICY URL]. Withdrawing the report also asks the collector to delete its copy.

## 7. Logs and technical data
Our web server and application keep logs that include IP addresses, request paths, times and user agents, for security and troubleshooting. We keep them for [X days]. [Mention any monitoring, backups and their retention.]

## 8. Legal bases (GDPR)
- Providing the service you asked for (downloads, settings, sync): performance of a contract, Art. 6(1)(b), or [legitimate interest, Art. 6(1)(f), for private/family installations].
- Failure reports and sharing them with the FetchDock developers: your consent, Art. 6(1)(a). You can withdraw it at any time by withdrawing the report.
- Logs and security: our legitimate interest in running a secure service, Art. 6(1)(f).

## 9. Where data is stored and who can access it
Data is stored on [HOSTING PROVIDER / OWN HARDWARE] in [COUNTRY]. Access is limited to [administrators]. [List processors, e.g. hosting or backup providers.] We do not sell your data or use it for advertising.

## 10. Your rights
You can ask us for access to, correction of, deletion of, or a copy of your data, and object to or restrict its processing, by contacting [CONTACT EMAIL]. You can also complain to your data protection authority [name it if you like].

## 11. Changes
We will post changes here and update the date above. [Describe how you announce significant changes.]
