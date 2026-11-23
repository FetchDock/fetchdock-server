import { useEffect, useState } from "react";
import { Button, Loading, Title, useNotify } from "react-admin";
import { apiFetch } from "../../common/api";
import { useIsDarkTheme } from "../privacy/api";

/** A react-admin resource name for the page (the API has no such collection; the page loads GET /users/me itself) */
export const ACCOUNT_RESOURCE = "account";

/** What the user types to confirm the deletion */
const CONFIRMATION = "delete my account";

/**
 * The user's own account (server#129): deleting it deletes their downloads, settings, apps, preferences and problem
 * reports, and the files no other user's download uses. Signs out afterwards.
 */
export const AccountPage = () => {
  const isDark = useIsDarkTheme();
  const notify = useNotify();
  const [subject, setSubject] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [typed, setTyped] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ subject: string }>("/users/me")
      .then((user) => !cancelled && setSubject(user?.subject ?? null))
      .catch((failure) => !cancelled && setError((failure as Error).message || "Your account could not be loaded"));

    return () => {
      cancelled = true;
    };
  }, []);

  const onDelete = async () => {
    if (null === subject) {
      return;
    }
    setBusy(true);
    try {
      await apiFetch(`/users/me?confirm=${encodeURIComponent(subject)}`, { method: "DELETE" });
    } catch (failure) {
      notify((failure as Error).message || "Your account could not be deleted", { type: "error" });
      setBusy(false);
      return;
    }

    // Signed out here; the identity provider account stays, so signing in again creates a new, empty account
    localStorage.removeItem("token");
    window.location.assign(window.origin);
  };

  if (error) {
    return <p className={`p-4 ${isDark ? "text-red-400" : "text-red-700"}`}>{error}</p>;
  }
  if (null === subject) {
    return <Loading />;
  }

  const cardClass = `rounded border p-4 ${isDark ? "border-red-400/40 bg-white/[0.04]" : "border-red-200 bg-white"}`;
  const inputClass = `mt-2 w-full max-w-sm rounded border px-2 py-1 ${isDark ? "border-white/25 bg-transparent" : "border-gray-300"}`;

  return (
    <div className="max-w-3xl p-4">
      <Title title="My account" />
      <section className={cardClass}>
        <h2 className="text-lg font-semibold">Delete your account</h2>
        <p className="mt-2 text-sm">
          This permanently deletes your download jobs (including deleted ones), settings, registered apps, interface
          preferences and problem reports. Downloaded files that no other user&apos;s download uses are deleted from
          disk; files other users downloaded too stay for them. Downloads that are still running are stopped.
        </p>
        <p className="mt-2 text-sm">
          Your account at the identity provider is not deleted: if you sign in again, a new, empty account is created.
          Sign out of your other apps (such as the browser extension) too, or they create a new account right away.
        </p>
        <label className="mt-4 block text-sm">
          Type <span className="font-mono font-semibold">{CONFIRMATION}</span> to confirm
          <input
            className={inputClass}
            value={typed}
            onChange={(event) => setTyped(event.target.value)}
            disabled={busy}
            autoComplete="off"
          />
        </label>
        <div className="mt-4">
          <Button
            label="Delete my account"
            color="error"
            variant="contained"
            onClick={onDelete}
            disabled={busy || CONFIRMATION !== typed.trim().toLowerCase()}
          />
        </div>
      </section>
    </div>
  );
};
