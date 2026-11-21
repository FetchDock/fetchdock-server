import {
  HydraAdmin,
  ResourceGuesser,
  fetchHydra as baseFetchHydra,
  hydraDataProvider as baseHydraDataProvider,
} from "@api-platform/admin";
import { parseHydraDocumentation } from "@api-platform/api-doc-parser";
import authProvider from "../common/authProvider";
import { getAuthHeaders } from "../common/api";
import { canViewAllDownloads, fetchRoles, hasRole, Permissions, ROLE_ADMIN } from "../common/permissions";
import LoginPage from "../loginPage";
import { Navigate } from "react-router-dom";
import { useIntrospection } from "@api-platform/admin";
import { useEffect, useState } from "react";
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

const dataProvider = (setRedirectToLogin: (arg0: boolean) => void) =>
  baseHydraDataProvider({
    entrypoint: window.origin,
    httpClient: fetchHydra,
    useEmbedded: true,
    // Mercure updates are private per user (topics "/users/{uuid}/..."), and the admin builds its topics from resource
    // IRIs ("/download_jobs/..."), which a user's subscriber token doesn't cover. Live updates for admins: see #55.
    mercure: false,
    apiDocumentationParser: apiDocumentationParser(setRedirectToLogin)
  })

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

  authProvider.checkAuth(null).catch(() => {
    localStorage.removeItem("token");
    setRedirectToLogin(true);
  });

  return (
  <HydraAdmin
    authProvider={authProvider}
    dataProvider={dataProvider(setRedirectToLogin)}
    loginPage={LoginPage}
    entrypoint={window.origin}
    title="API Platform admin"
  >
    {redirectToLogin ? <RedirectToLogin /> : resources(roles)}
  </HydraAdmin>
)
};

export default App;
