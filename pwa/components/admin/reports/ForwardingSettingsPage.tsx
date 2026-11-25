import { useEffect, useState } from "react";
import { Button, Loading, Title, useNotify } from "react-admin";
import { useIsDarkTheme } from "../privacy/api";
import { FailureReportForwarding, ForwardingMode, fetchForwarding, saveForwarding } from "./forwarding";

/**
 * Forwarding of failure reports to the FetchDock collector (phase 2 of ADR 0012): only reports whose reporter agreed
 * are sent, without the reporter, the job or internal notes. Enabling accepts the collector's privacy policy.
 */
export const ForwardingSettingsPage = () => {
  const isDark = useIsDarkTheme();
  const notify = useNotify();
  const [settings, setSettings] = useState<FailureReportForwarding | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [mode, setMode] = useState<ForwardingMode>("review");
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);

  const apply = (result: FailureReportForwarding | null) => {
    if (result) {
      setSettings(result);
      setMode(result.mode);
      setAccepted(false);
    }
  };

  useEffect(() => {
    fetchForwarding()
      .then(apply)
      .catch((failure) => setError((failure as Error).message || "The forwarding settings could not be loaded"));
  }, []);

  if (error) {
    return <p className={`p-4 ${isDark ? "text-red-400" : "text-red-700"}`}>{error}</p>;
  }
  if (!settings) {
    return <Loading />;
  }

  const policy = settings.policy;
  // A new policy version needs to be accepted again; an older one still accepted by the collector keeps working
  const needsAcceptance = !settings.acceptedPolicyCurrent || (null !== policy && settings.acceptedPolicyVersion !== policy.version);
  const save = async (enabled: boolean) => {
    setBusy(true);
    try {
      const version = enabled && accepted && policy ? policy.version : settings.acceptedPolicyVersion;
      apply(await saveForwarding({ enabled, mode, acceptedPolicyVersion: version }));
      notify(enabled ? "Forwarding saved" : "Forwarding disabled", { type: "success" });
    } catch (failure) {
      notify((failure as Error).message || "The request failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  const cardClass = `mb-6 rounded border p-4 ${isDark ? "border-white/15 bg-white/[0.04]" : "border-gray-200 bg-white"}`;
  const warnClass = isDark ? "text-amber-300" : "text-amber-700";

  return (
    <div className="max-w-3xl p-4">
      <Title title="Report forwarding" />
      <section className={cardClass}>
        <p className="text-lg font-semibold">{settings.enabled ? "Forwarding is on" : "Forwarding is off"}</p>
        <p className="mt-2 text-sm opacity-75">
          When forwarding is on, clients let reporters choose to share a report with the FetchDock developers. Only
          those reports are sent to the collector, with what the reporter saw in the report form, the site, this
          server&apos;s FetchDock version and the comments you write for the developers. The reporter, the job and
          internal notes stay on this server. Withdrawn reports are deleted at the collector too.
        </p>
        {!settings.available && (
          <p className={`mt-3 text-sm ${warnClass}`}>
            Unavailable on this server: failure reports are disabled (FAILURE_REPORTS_ENABLED) or no collector is
            configured (FAILURE_REPORTS_COLLECTOR_URL).
          </p>
        )}
        {settings.enabled && !settings.acceptedPolicyCurrent && null !== policy && (
          <p className={`mt-3 text-sm ${warnClass}`}>
            The collector no longer accepts the policy version you accepted ({settings.acceptedPolicyVersion}).
            Reports are held until you accept version {policy.version}.
          </p>
        )}
        <dl className="mt-3 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="font-medium">Collector</dt>
          <dd className="break-all">{settings.collectorUrl ?? "-"}</dd>
          <dt className="font-medium">Collector privacy policy</dt>
          <dd>
            {policy ? (
              <a href={policy.url} target="_blank" rel="noreferrer" className="underline">
                Version {policy.version}
              </a>
            ) : (
              "the collector can't be reached"
            )}
          </dd>
          <dt className="font-medium">Accepted version</dt>
          <dd>
            {settings.acceptedPolicyVersion ?? "-"}
            {settings.acceptedAt ? ` (${new Date(settings.acceptedAt).toLocaleString()})` : ""}
          </dd>
          <dt className="font-medium">Instance id</dt>
          <dd className="break-all font-mono">{settings.instanceId ?? "not registered yet"}</dd>
          <dt className="font-medium">Awaiting review</dt>
          <dd>{settings.awaitingReview}</dd>
          <dt className="font-medium">Failed</dt>
          <dd>{settings.failed}</dd>
        </dl>
      </section>
      {settings.available && (
        <section className={cardClass}>
          <fieldset className="mb-3">
            <legend className="mb-1 font-medium">Mode</legend>
            <label className="block text-sm">
              <input type="radio" name="mode" checked={"review" === mode} onChange={() => setMode("review")} /> Review:
              an admin forwards each shared report, optionally with a comment for the developers
            </label>
            <label className="block text-sm">
              <input type="radio" name="mode" checked={"automatic" === mode} onChange={() => setMode("automatic")} />{" "}
              Automatic: shared reports are forwarded as soon as they arrive
            </label>
          </fieldset>
          {policy && needsAcceptance && (
            <label className="mb-3 block text-sm">
              <input type="checkbox" checked={accepted} onChange={(event) => setAccepted(event.target.checked)} /> I
              have read and accept the{" "}
              <a href={policy.url} target="_blank" rel="noreferrer" className="underline">
                collector privacy policy, version {policy.version}
              </a>
              , and the instance registers with the collector.
            </label>
          )}
          <div className="flex gap-2">
            <Button
              label={settings.enabled ? "Save" : "Enable forwarding"}
              variant="contained"
              disabled={busy || null === policy || (needsAcceptance && !accepted && !(settings.enabled && settings.acceptedPolicyCurrent))}
              onClick={() => save(true)}
            />
            {settings.enabled && <Button label="Disable forwarding" variant="outlined" disabled={busy} onClick={() => save(false)} />}
          </div>
        </section>
      )}
    </div>
  );
};
