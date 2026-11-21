import { apiFetch } from "./api";

/**
 * Permission roles for the /admin/... operations (see the API's role_hierarchy). Check these, not ROLE_ADMIN:
 * other roles (e.g. a moderator) may be granted them.
 */
export const ROLE_VIEW_ALL_DOWNLOADS = "ROLE_VIEW_ALL_DOWNLOADS";
export const ROLE_MANAGE_ALL_DOWNLOADS = "ROLE_MANAGE_ALL_DOWNLOADS";
/** Resources the API only exposes to admins (downloader option policies, users) */
export const ROLE_ADMIN = "ROLE_ADMIN";

/** The user's effective roles, as returned by GET /users/me */
export type Permissions = string[];

let cache: { token: string | null; roles: Promise<Permissions> } | null = null;

/**
 * The user's effective roles (including those granted through the role hierarchy), cached per token.
 * Resolves to [] when not logged in or when the API can't be reached, so nothing admin-only is shown.
 */
export const fetchRoles = (): Promise<Permissions> => {
  const token = localStorage.getItem("token");
  if (!token) {
    return Promise.resolve([]);
  }
  if (cache?.token !== token) {
    const roles = apiFetch<{ roles?: unknown }>("/users/me")
      .then((user) => (Array.isArray(user?.roles) ? user.roles.filter((role): role is string => "string" === typeof role) : []))
      .catch(() => {
        cache = null;
        return [];
      });
    cache = { token, roles };
  }

  return cache.roles;
};

export const hasRole = (permissions: unknown, role: string): boolean =>
  Array.isArray(permissions) && permissions.includes(role);

export const canViewAllDownloads = (permissions: unknown) => hasRole(permissions, ROLE_VIEW_ALL_DOWNLOADS);
export const canManageAllDownloads = (permissions: unknown) => hasRole(permissions, ROLE_MANAGE_ALL_DOWNLOADS);
