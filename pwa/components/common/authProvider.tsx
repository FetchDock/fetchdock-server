import { AuthProvider } from 'react-admin';
import { UserManager } from 'oidc-client-ts';

import getProfileFromToken from '../common/getProfileFromToken';
import {OAuthProvider} from "../../types";

const issuer = process.env.NEXT_PUBLIC_OIDC_ISSUER;
const clientId = process.env.NEXT_PUBLIC_OIDC_CLIENT_ID;
const redirectUri = process.env.NEXT_PUBLIC_OIDC_REDIRECT_URI;
const apiUri = process.env.NEXT_PUBLIC_VITE_API_URL;

const userManager = new UserManager({
  authority: issuer as string,
  client_id: clientId as string,
  redirect_uri: redirectUri as string,
  response_type: "code",
  scope: "openid email profile offline_access", // Allow to retrieve the email and user name later api side
});

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
    await userManager.signinRedirect();
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

    console.log("Checking authentication with token:", token);

    try {
      const jwt: any = getProfileFromToken(token);

      console.log("Decoded JWT:", jwt);

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

    console.log("Existing token:", existingToken);

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
      console.log([
        'parsedToken:', parsedToken,
        'jwt:', jwt,
        'exp:', exp * 1000,
        'now:', now,
        'expired:', now > exp * 1000
      ]);

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
        userManager.clearStaleState();
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
  getPermissions: () => Promise.resolve(),
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
      body: JSON.stringify({ code: code, code_verifier, redirect_uri: redirectUri }),
    });

    if (!response.ok) {
      console.error('Failed to exchange code for token:', await response.text());
      cleanup();
      return Promise.reject();
    }

    const token = await response.json();

    localStorage.setItem("token", JSON.stringify(token));
    userManager.clearStaleState();
    cleanup();
    return Promise.resolve();
  },
};

export default authProvider;

