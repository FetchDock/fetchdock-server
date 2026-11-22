import { AuthProvider } from 'react-admin';
import { UserManager } from 'oidc-client-ts';

import getProfileFromToken from '../common/getProfileFromToken';
import { fetchRoles } from './permissions';
import {OAuthProvider} from "../../types";
import { currentOidcConfig, type OidcConfig } from "./oidcConfig";
// The API serves the admin, so it defaults to the admin's origin (like the data provider's entrypoint).
// NEXT_PUBLIC_VITE_API_URL is the variable's former name, still read for existing setups.
const apiUri =
  process.env.NEXT_PUBLIC_API_URL ||
  process.env.NEXT_PUBLIC_VITE_API_URL ||
  (typeof window !== "undefined" ? window.origin : "");

let manager: UserManager | null = null;
let managerConfig: OidcConfig | null = null;

const requireOidcConfig = (): { issuer: string; clientId: string; redirectUri: string } => {
  const { issuer, clientId, redirectUri } = currentOidcConfig() ?? { issuer: null, clientId: null, redirectUri: null };
  if (!issuer || !clientId || !redirectUri) {
    throw new Error(
      "OIDC isn't configured: set NEXT_PUBLIC_OIDC_ISSUER, NEXT_PUBLIC_OIDC_CLIENT_ID and NEXT_PUBLIC_OIDC_REDIRECT_URI on the pwa service"
    );
  }

  return { issuer, clientId, redirectUri };
};

const userManager = (): UserManager => {
  // Re-created if the settings change (they're set by each page)
  if (!manager || managerConfig !== currentOidcConfig()) {
    managerConfig = currentOidcConfig();
    const { issuer, clientId, redirectUri } = requireOidcConfig();
    manager = new UserManager({
      authority: issuer,
      client_id: clientId,
      redirect_uri: redirectUri,
      response_type: "code",
      scope: "openid email profile offline_access", // Allow to retrieve the email and user name later api side
    });
  }

  return manager;
};

const getAccessToken = () => localStorage.getItem("token");

const cleanup = () => {
  // Remove the ?code&state from the URL
  window.history.replaceState(
    {},
    window.document.title,
    window.location.origin
  );
};

const authProvider: OAuthProvider = {
  login: async () => {
    await userManager().signinRedirect();
    return;
  },
  logout: () => {
    localStorage.removeItem("token");
    return Promise.resolve();
  },
  checkError: () => {
    localStorage.removeItem("token");
    return Promise.resolve();
  },
  checkAuth: async () => {
    const token = localStorage.getItem("token");

    if (!token) {
      return Promise.reject({
        redirectToLogin: true,
        reason: "Token not found",
      });
    }

    try {
      const jwt: any = getProfileFromToken(token);

      const exp = jwt?.exp;
      const now = Date.now();
      if (now > exp * 1000) {
        if (authProvider.refreshToken) {
          try {
            await authProvider.refreshToken();
          } catch (refreshError) {
            console.error("Failed to refresh token:", refreshError);
            return Promise.reject({
              redirectToLogin: true,
              reason: "Token refresh failed",
            });
          }
        } else {
          return Promise.reject({
            redirectToLogin: true,
            reason: "Refresh token method not available",
          });
        }
      }
      return Promise.resolve();
    } catch (error) {
      console.error("Failed to decode token in checkAuth:", error);
      return Promise.reject({
        redirectToLogin: true,
        reason: "Failed to decode token",
      });
    }
  },
  refreshToken: async () => {
    const existingToken = localStorage.getItem("token");

    if (!existingToken) {
      return Promise.reject({
        redirectToLogin: true,
        reason: "Token not found",
      });
    }

    const parsedToken = JSON.parse(existingToken);
    if (!parsedToken.refresh_token) {
      return Promise.reject({
        redirectToLogin: true,
        reason: "Refresh token not found",
      });
    }

    try {
      const jwt: any = getProfileFromToken(existingToken);
      const exp = jwt?.exp;
      const now = Date.now();

      console.log(
        `Token expiration: ${new Date(exp * 1000).toLocaleString()}, Current time: ${new Date(now).toLocaleString()}`
      );

      if (now > exp * 1000) {
        const response = await fetch(`${apiUri}/auth/token`, {
          method: "POST",
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            grant_type: 'refresh_token',
            refresh_token: parsedToken.refresh_token
          }).toString()
        }).catch(error => {
          console.error('Failed to exchange code for token:', error);
          return Promise.reject();
        });

        if (!response.ok) {
          console.error('Failed to exchange code for token:', await response.text());
          cleanup();
          return Promise.reject();
        }

        const token = await response.json();

        localStorage.setItem("token", JSON.stringify(token));
        userManager().clearStaleState();
        cleanup();
        return Promise.resolve();
      }
    } catch (error) {
      console.error("Failed to refresh token:", error);
      return Promise.reject({
        redirectToLogin: true,
        reason: "Failed to refresh token",
      });
    }
  },
  // The user's effective roles from GET /users/me (see ../common/permissions)
  getPermissions: () => fetchRoles(),
  getIdentity: () => {
    const token = window.localStorage.getItem("token");
    if (!token) {
      return Promise.reject();
    }
    try {
      const profile: any = getProfileFromToken(token);
      if (!profile || !profile.sub) {
        return Promise.reject();
      }
      return Promise.resolve({
        id: profile.sub,
        fullName: profile.name ?? "",
        avatar: profile.picture,
      });
    } catch (error) {
      console.error("Failed to decode token in getIdentity:", error);
      return Promise.reject();
    }
  },
  handleCallback: async () => {
    const { searchParams } = new URL(window.location.href);
    const code = searchParams.get("code");
    const state = searchParams.get("state");

    const stateKey = `oidc.${state}`;
    const { code_verifier } = JSON.parse(
      localStorage.getItem(stateKey) || "{}"
    );

    const response = await fetch(`${apiUri}/auth/code-to-token`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ code: code, code_verifier, redirect_uri: requireOidcConfig().redirectUri }),
    });

    if (!response.ok) {
      console.error('Failed to exchange code for token:', await response.text());
      cleanup();
      return Promise.reject();
    }

    const token = await response.json();

    localStorage.setItem("token", JSON.stringify(token));
    userManager().clearStaleState();
    cleanup();
    return Promise.resolve();
  },
};

export default authProvider;

