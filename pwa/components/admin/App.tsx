import {
  HydraAdmin,
  ResourceGuesser,
  fetchHydra as baseFetchHydra,
  hydraDataProvider as baseHydraDataProvider,
} from "@api-platform/admin";
import { parseHydraDocumentation } from "@api-platform/api-doc-parser";
import authProvider from "../common/authProvider";
import { apiFetch, getAuthHeaders } from "../common/api";
import { canViewAllDownloads, fetchRoles, hasRole, Permissions, ROLE_ADMIN } from "../common/permissions";
import LoginPage from "../loginPage";
import { Navigate } from "react-router-dom";
import { useIntrospection } from "@api-platform/admin";
import { useEffect, useMemo, useState } from "react";
import { Resource } from "react-admin";
import { AdminDownloadJobList, AdminDownloadJobShow } from "./downloads/AdminDownloadJobs";
import { AdminDownloadedFileList, AdminDownloadedFileShow } from "./downloads/AdminDownloadedFiles";
import { FILE_RESOURCE, JOB_RESOURCE } from "./downloads/common";

const fetchHydra = (url: URL, options?: Record<string, any>) =>
  baseFetchHydra(url, {
    ...options,
    headers: getAuthHeaders,
  });


const RedirectToLogin = () => {
  const introspect = useIntrospection();

  if (localStorage.getItem("token")) {
    introspect();
    return <></>;
  }

  return <Navigate to="/login" replace />;
}

const apiDocumentationParser = (setRedirectToLogin: (arg0: boolean) => void) => async () => {
  try {
    setRedirectToLogin(false);
    return await parseHydraDocumentation(window.origin, { headers: getAuthHeaders });
  } catch (result: any) {
    const { api, response, status } = result;
    if (status !== 401 || !response) {
      throw result;
    }

    localStorage.removeItem("token");
    setRedirectToLogin(true);

    return { api, response, status };
  }
};

/** Options of the admin's Mercure support: the JWT is sent as the mercureAuthorization cookie, with Path={hub} */
type MercureSettings = { hub: string; jwt: string; topicUrl: URL };

const dataProvider = (setRedirectToLogin: (arg0: boolean) => void, mercure: MercureSettings | false) =>
  baseHydraDataProvider({
    entrypoint: window.origin,
    httpClient: fetchHydra,
    useEmbedded: true,
    // Live updates only reach admins: the API publishes the /admin/... resources on their absolute IRIs (AdminTopics),
    // which their subscriber token covers. Other resources' updates are private per user ("/users/{uuid}/...") and
    // don't match the topics the admin subscribes to, so Mercure stays off for other users.
    mercure,
    apiDocumentationParser: apiDocumentationParser(setRedirectToLogin)
  })

type SubscriptionToken = { hubUrl: string; token: string; expiresAt: string };

/** How long before the subscriber token expires a new one is requested */
const TOKEN_RENEWAL_MARGIN_MS = 60_000;
const TOKEN_RETRY_MS = 5 * 60_000;

/**
 * Mercure settings for users with ROLE_VIEW_ALL_DOWNLOADS, renewed before the subscriber token expires
 * (POST /users/me/subscription-token). false (no live updates) for other users or while no token is available.
 *
 * The hub is given as a path, so the admin's cookie gets a valid Path; the hub's origin is the topic base, as the API
 * builds the admin topics from it.
 */
const useAdminMercure = (enabled: boolean): MercureSettings | false => {
  const [mercure, setMercure] = useState<MercureSettings | false>(false);

  useEffect(() => {
    if (!enabled) {
      setMercure(false);
      return;
    }

    let timer: ReturnType<typeof setTimeout> | undefined;
    let cancelled = false;
    const load = async () => {
      try {
        const subscription = await apiFetch<SubscriptionToken>("/users/me/subscription-token", { method: "POST" });
        if (cancelled || !subscription) {
          return;
        }
        const hub = new URL(subscription.hubUrl, window.origin);
        setMercure({ hub: hub.pathname, jwt: subscription.token, topicUrl: new URL(hub.origin) });
        const renewIn = new Date(subscription.expiresAt).getTime() - Date.now() - TOKEN_RENEWAL_MARGIN_MS;
        timer = setTimeout(load, Math.max(TOKEN_RENEWAL_MARGIN_MS, renewIn));
      } catch (error) {
        console.error("Failed to get a Mercure subscriber token, live updates are disabled:", error);
        if (!cancelled) {
          setMercure(false);
          timer = setTimeout(load, TOKEN_RETRY_MS);
        }
      }
    };
    load();

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [enabled]);

  return mercure;
};

/**
 * Declared resources replace the guessed ones, so every resource the admin shows is listed here.
 * Admin-only resources are only shown to users with the matching roles (GET /users/me); the API enforces them anyway.
 */
const resources = (roles: Permissions) => [
  <ResourceGuesser key="download_jobs" name="download_jobs" />,
  <ResourceGuesser key="downloaders" name="downloaders" />,
  <ResourceGuesser key="versions" name="versions" />,
  <ResourceGuesser key="sites" name="sites" />,
  <ResourceGuesser key="users/clients" name="users/clients" />,
  <ResourceGuesser key="users/settings/downloaders" name="users/settings/downloaders" />,
  <ResourceGuesser key="users/settings/sites" name="users/settings/sites" />,
  ...(canViewAllDownloads(roles)
    ? [
        <Resource
          key={JOB_RESOURCE}
          name={JOB_RESOURCE}
          options={{ label: "All download jobs" }}
          list={AdminDownloadJobList}
          show={AdminDownloadJobShow}
        />,
        <Resource
          key={FILE_RESOURCE}
          name={FILE_RESOURCE}
          options={{ label: "All downloaded files" }}
          list={AdminDownloadedFileList}
          show={AdminDownloadedFileShow}
        />,
      ]
    : []),
  ...(hasRole(roles, ROLE_ADMIN)
    ? [
        <ResourceGuesser key="downloader_option_policies" name="downloader_option_policies" />,
        <ResourceGuesser key="oidc_subject_identifiers" name="oidc_subject_identifiers" />,
      ]
    : []),
];

const App = () => {
  const [redirectToLogin, setRedirectToLogin] = useState(false);
  const [roles, setRoles] = useState<Permissions>([]);

  useEffect(() => {
    if (!redirectToLogin) {
      fetchRoles().then(setRoles);
    }
  }, [redirectToLogin]);

  const mercure = useAdminMercure(!redirectToLogin && canViewAllDownloads(roles));
  // Re-created when the subscriber token is renewed: the admin captures the JWT when the data provider is created
  const provider = useMemo(() => dataProvider(setRedirectToLogin, mercure), [mercure]);

  authProvider.checkAuth(null).catch(() => {
    localStorage.removeItem("token");
    setRedirectToLogin(true);
  });

  return (
  <HydraAdmin
    authProvider={authProvider}
    dataProvider={provider}
    loginPage={LoginPage}
    entrypoint={window.origin}
    title="API Platform admin"
  >
    {redirectToLogin ? <RedirectToLogin /> : resources(roles)}
  </HydraAdmin>
)
};

export default App;
