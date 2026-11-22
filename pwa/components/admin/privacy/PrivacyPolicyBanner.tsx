import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { AdminPrivacyPolicy, PRIVACY_POLICY_RESOURCE, REASON_LABELS, fetchPrivacyPolicy, useIsDarkTheme } from "./api";

/** How often admin pages re-check the policy status (it follows the integrity checks) */
const POLL_MS = 5 * 60_000;

/**
 * Shown on every admin page while the instance publishes no privacy policy (ADR 0013). Only rendered for admins;
 * nothing is blocked.
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

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [enabled]);

  if (!policy || "unavailable" !== policy.status) {
    return null;
  }

  return (
    <div
      role="alert"
      className={`border-b px-4 py-2 text-sm ${
        isDark ? "border-amber-700/60 bg-amber-950/70 text-amber-100" : "border-amber-300 bg-amber-50 text-amber-900"
      }`}
    >
      This instance publishes no privacy policy: {policy.reason ? REASON_LABELS[policy.reason] : "unknown reason"}.{" "}
      <Link className="underline" to={`/${PRIVACY_POLICY_RESOURCE}`}>
        Privacy policy settings
      </Link>
    </div>
  );
};
