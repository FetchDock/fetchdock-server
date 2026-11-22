import { useEffect, useState } from "react";
import { useRouter } from "next/router";
import { configureOidc, getOidcServerSideProps, OidcPageProps } from "../../components/common/oidcConfig";

const AuthCallback = ({ oidc }: OidcPageProps) => {
  configureOidc(oidc);
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Only run when the router is ready and query params are available
    if (!router.isReady) return;

    const { code, state } = router.query;
    if (!code || !state) {
      setError("Missing authentication parameters.");
      return;
    }

    // Loaded in the browser only: the page is server-rendered, and the authProvider imports react-admin
    import("../../components/common/authProvider")
      .then(({ default: authProvider }) => {
        if (typeof authProvider.handleCallback !== "function") {
          setError("Authentication provider is not available.");
          return;
        }

        return authProvider.handleCallback().then(() => {
          router.replace("/admin");
        });
      })
      .catch(() => {
        setError("Authentication failed. Please try again.");
      });
  }, [router.isReady, router.query, router]);

  if (error) {
    return (
      <div style={{ display: "flex", justifyContent: "center", alignItems: "center", height: "100vh" }}>
        <p>{error}</p>
      </div>
    );
  }

  return (
    <div style={{ display: "flex", justifyContent: "center", alignItems: "center", height: "100vh" }}>
      <p>Authenticating, please wait...</p>
    </div>
  );
};

export const getServerSideProps = getOidcServerSideProps;

export default AuthCallback;

