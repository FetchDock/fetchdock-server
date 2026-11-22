import { useEffect, useState } from "react";
import { Loading, Title } from "react-admin";
import { INTEGRITY_LABELS, InstanceIntegrity, fetchInstanceIntegrity, useIsDarkTheme } from "./api";

/** How often the page refreshes the checks */
const REFRESH_MS = 60_000;

const statusClass = (status: keyof typeof INTEGRITY_LABELS, isDark: boolean) =>
  ({
    verified: isDark ? "text-green-400" : "text-green-700",
    modified: isDark ? "text-red-400" : "text-red-700",
    unknown: isDark ? "text-amber-300" : "text-amber-700",
  })[status];

/**
 * The integrity check of the instance's code (ADR 0013): the effective status (worst check of the last 48 hours)
 * and the latest check of each api and worker container.
 */
export const InstanceIntegrityPage = () => {
  const isDark = useIsDarkTheme();
  const [integrity, setIntegrity] = useState<InstanceIntegrity | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const load = () =>
      fetchInstanceIntegrity()
        .then((result) => {
          if (!cancelled) {
            setIntegrity(result);
            setError(null);
          }
        })
        .catch((failure) => !cancelled && setError((failure as Error).message || "The integrity status could not be loaded"));
    load();
    const timer = setInterval(load, REFRESH_MS);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, []);

  if (error) {
    return <p className={`p-4 ${isDark ? "text-red-400" : "text-red-700"}`}>{error}</p>;
  }
  if (!integrity) {
    return <Loading />;
  }

  const cardClass = `rounded border p-4 ${isDark ? "border-white/15 bg-white/[0.04]" : "border-gray-200 bg-white"}`;

  return (
    <div className="max-w-4xl p-4">
      <Title title="Instance integrity" />
      <section className={`mb-6 ${cardClass}`}>
        <p className={`text-lg font-semibold ${statusClass(integrity.status, isDark)}`}>{INTEGRITY_LABELS[integrity.status]}</p>
        <p className="mt-2 text-sm opacity-75">
          The api and worker containers compare their application files with the manifest baked into the image
          {integrity.checkOnline
            ? ", and the manifest with the digest the FetchDock project published for this version"
            : " (the online comparison is off: INTEGRITY_CHECK_ONLINE=false, so the status can't read verified)"}
          . They check when they start and daily. This is a signal that catches accidental changes, not proof: a
          deliberately modified server could fake it.
        </p>
        <dl className="mt-3 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="font-medium">FetchDock</dt>
          <dd>{integrity.appVersion}</dd>
          <dt className="font-medium">Manifest digest</dt>
          <dd className="break-all font-mono">{integrity.manifestDigest ?? "none (no manifest in this image)"}</dd>
          <dt className="font-medium">Commit</dt>
          <dd className="font-mono">{integrity.gitSha ?? "-"}</dd>
        </dl>
      </section>
      <section className={cardClass}>
        <h2 className="mb-3 text-lg font-semibold">Latest check per container (last 48 hours)</h2>
        {0 === integrity.checks.length ? (
          <p>No checks in the last 48 hours, so the status reads unknown.</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr>
                <th className="py-1">Container</th>
                <th>Role</th>
                <th>Status</th>
                <th>Checked</th>
                <th>Files that differ</th>
              </tr>
            </thead>
            <tbody>
              {integrity.checks.map((check) => (
                <tr key={`${check.host}-${check.role}`} className={`border-t align-top ${isDark ? "border-white/10" : "border-gray-100"}`}>
                  <td className="py-1 font-mono">{check.host}</td>
                  <td>{check.role}</td>
                  <td className={statusClass(check.status, isDark)}>{check.status}</td>
                  <td>{new Date(check.checkedAt).toLocaleString()}</td>
                  <td>
                    {0 === check.mismatches.length ? (
                      "-"
                    ) : (
                      <ul className="font-mono text-xs">
                        {check.mismatches.map((path) => (
                          <li key={path}>{path}</li>
                        ))}
                      </ul>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
};
