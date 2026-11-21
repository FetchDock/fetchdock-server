import { HttpError } from "react-admin";

/**
 * The Authorization header for API calls.
 *
 * The authProvider stores the whole token response in localStorage during the login; the API needs its access_token.
 */
export const getAuthHeaders = (): Record<string, string> => {
  const token = localStorage.getItem("token");
  if (!token) {
    return {};
  }

  try {
    const accessToken = JSON.parse(token).access_token;
    return accessToken ? { Authorization: `Bearer ${accessToken}` } : {};
  } catch {
    return {};
  }
};

/**
 * Calls the API (same origin as the admin) with the user's token and returns the JSON body, or null for 204 responses.
 * Throws a react-admin HttpError with the API's error description otherwise.
 */
export const apiFetch = async <T = any>(path: string, init: RequestInit = {}): Promise<T | null> => {
  const response = await fetch(new URL(path, window.origin), {
    ...init,
    headers: {
      Accept: "application/ld+json",
      ...getAuthHeaders(),
      ...(init.headers as Record<string, string> | undefined),
    },
  });

  if (204 === response.status) {
    return null;
  }

  const body = await response.json().catch(() => null);
  if (!response.ok) {
    const message = body?.description ?? body?.detail ?? body?.["hydra:description"] ?? response.statusText;
    throw new HttpError(message, response.status, body);
  }

  return body as T;
};
