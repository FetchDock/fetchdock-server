import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  AdminPrivacyPolicy,
  PRIVACY_POLICY_CHANGED_EVENT,
  PRIVACY_POLICY_RESOURCE,
  REASON_LABELS,
  fetchPrivacyPolicy,
  useIsDarkTheme,
} from "./api";

/** How often admin pages re-check the policy status (it follows the integrity checks) */
const POLL_MS = 5 * 60_000;

/**
 * Shown on every admin page when the generated privacy policy is unavailable, outdated after an application or
 * configuration update, or has unpublished draft changes (ADR 0013). Never shown for custom (PRIVACY_POLICY_URL)
 * instances; nothing is blocked.
 */
export const PrivacyPolicyBanner = ({ enabled }: { enabled: boolean }) => {
  const isDark = useIsDarkTheme();
  const [policy, setPolicy] = useState<AdminPrivacyPolicy | null>(null);

  useEffect(() => {
    if (!enabled) {
      setPolicy(null);
      return;
    }
    let cancelled = false;
    const load = () =>
      fetchPrivacyPolicy()
        .then((result) => !cancelled && setPolicy(result))
        .catch(() => undefined);
    load();
    const timer = setInterval(load, POLL_MS);
    window.addEventListener(PRIVACY_POLICY_CHANGED_EVENT, load);

    return () => {
      cancelled = true;
      clearInterval(timer);
      window.removeEventListener(PRIVACY_POLICY_CHANGED_EVENT, load);
    };
  }, [enabled]);

  if (!policy || "custom" === policy.status) {
    return null;
  }

  if ("unavailable" === policy.status) {
    return (
      <div
        role="alert"
        className={`border-b px-4 py-2.5 text-sm ${
          isDark ? "border-amber-700/60 bg-amber-950/70 text-amber-100" : "border-amber-300 bg-amber-50 text-amber-900"
        }`}
      >
        <strong>Privacy policy not published:</strong> {policy.reason ? REASON_LABELS[policy.reason] : "unknown reason"}.{" "}
        <Link className="font-semibold underline" to={`/${PRIVACY_POLICY_RESOURCE}`}>
          Review privacy policy settings
        </Link>
      </div>
    );
  }

  if (policy.outdated) {
    const versionNote =
      policy.publishedTextVersion && policy.publishedTextVersion !== policy.currentTextVersion
        ? ` (policy text ${policy.publishedTextVersion} → ${policy.currentTextVersion})`
        : "";

    return (
      <div
        role="alert"
        className={`border-b-2 px-4 py-3 text-sm ${
          isDark ? "border-red-500/80 bg-red-950/85 text-red-100" : "border-red-400 bg-red-50 text-red-950"
        }`}
      >
        <strong>Action required — privacy policy update pending review{versionNote}:</strong> FetchDock or your instance
        configuration changed since revision {policy.revision} was published, so <code>/privacy</code> currently displays a
        pending-review notice. FetchDock never publishes privacy policy updates without your approval.{" "}
        <Link className="font-semibold underline" to={`/${PRIVACY_POLICY_RESOURCE}`}>
          Review changes &amp; publish updated policy
        </Link>
      </div>
    );
  }

  if (policy.pendingPublish) {
    return (
      <div
        role="status"
        className={`border-b px-4 py-2 text-sm ${
          isDark ? "border-amber-700/60 bg-amber-950/50 text-amber-100" : "border-amber-300 bg-amber-50 text-amber-900"
        }`}
      >
        <strong>Unpublished privacy policy changes:</strong> your saved answers differ from published revision{" "}
        {policy.revision}.{" "}
        <Link className="font-semibold underline" to={`/${PRIVACY_POLICY_RESOURCE}`}>
          Review &amp; publish
        </Link>
      </div>
    );
  }

  return null;
};
