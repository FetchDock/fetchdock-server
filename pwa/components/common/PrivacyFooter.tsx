import { useEffect, useState } from "react";

/**
 * Links the instance's privacy policy (PRIVACY_POLICY_URL), read from the public well-known document.
 * Renders nothing when the operator published none.
 */
const PrivacyFooter = () => {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetch("/.well-known/browser-extension", { headers: { Accept: "application/json" } })
      .then((response) => (response.ok ? response.json() : null))
      .then((config) => {
        const policy = config?.privacyPolicyUrl;
        if (!cancelled && typeof policy === "string" && /^https?:\/\//i.test(policy)) {
          setUrl(policy);
        }
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  if (!url) {
    return null;
  }

  return (
    <footer className="fixed bottom-0 right-0 z-50 px-3 py-1 text-xs bg-white/80 rounded-tl">
      <a href={url} target="_blank" rel="noreferrer noopener">
        Privacy policy
      </a>
    </footer>
  );
};

export default PrivacyFooter;
