import {
  HydraAdmin,
  fetchHydra as baseFetchHydra,
  hydraDataProvider as baseHydraDataProvider,
} from "@api-platform/admin";
import { parseHydraDocumentation } from "@api-platform/api-doc-parser";
import authProvider from "../common/authProvider";
import LoginPage from "../loginPage";
import { Navigate } from "react-router-dom";
import { useIntrospection } from "@api-platform/admin";
import {useState} from "react";

const getHeaders = (): Record<string, string> => {
  // token is stored in localStorage by the authProvider during the login process
  // it contains the entire JWT token, which is needed for the API calls to be authenticated
  // we need to get the access_token from the JWT token and add it to the Authorization header
  const token = localStorage.getItem("token");
  if (!token) {
    return {};
  }

  const jwt = JSON.parse(token);
  const accessToken = jwt.access_token;
  return { Authorization: `Bearer ${accessToken}` };
}

const fetchHydra = (url: URL, options?: Record<string, any>) =>
  baseFetchHydra(url, {
    ...options,
    headers: getHeaders,
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
    return await parseHydraDocumentation(window.origin, { headers: getHeaders });
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
    apiDocumentationParser: apiDocumentationParser(setRedirectToLogin)
  })





const App = () => {
  const [redirectToLogin, setRedirectToLogin] = useState(false);

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
    {redirectToLogin ? (
      <RedirectToLogin />
    ) : (
      // <ResourceGuesser name="download_jobs" list={DownloadJobList} />
      <></>
    )}
  </HydraAdmin>
)
};

export default App;
