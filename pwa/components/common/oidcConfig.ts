/** The admin's OpenID Connect client settings */
export type OidcConfig = {
  issuer: string | null;
  clientId: string | null;
  redirectUri: string | null;
};

const read = (name: string, buildTimeValue: string | undefined): string | null => {
  // Next.js inlines `process.env.NEXT_PUBLIC_*` at build time, and the published images are built without them.
  // A computed key isn't inlined, so the server reads the container's environment at request time; the build-time
  // value (e.g. from pwa/.env in development) is the fallback.
  const env: Record<string, string | undefined> = process.env;

  return env[name] || buildTimeValue || null;
};

/**
 * OIDC settings from the server's environment, for getServerSideProps: they must be read at request time, not
 * build time, so one image works for every deployment.
 */
export const readOidcConfig = (): OidcConfig => ({
  issuer: read("NEXT_PUBLIC_OIDC_ISSUER", process.env.NEXT_PUBLIC_OIDC_ISSUER),
  clientId: read("NEXT_PUBLIC_OIDC_CLIENT_ID", process.env.NEXT_PUBLIC_OIDC_CLIENT_ID),
  redirectUri: read("NEXT_PUBLIC_OIDC_REDIRECT_URI", process.env.NEXT_PUBLIC_OIDC_REDIRECT_URI),
});

/** Props of the pages that use the authProvider */
export type OidcPageProps = { oidc: OidcConfig };

export const getOidcServerSideProps = async (): Promise<{ props: OidcPageProps }> => ({
  props: { oidc: readOidcConfig() },
});

let current: OidcConfig | null = null;

/**
 * Sets the settings the authProvider uses. Pages call it with their getServerSideProps props before rendering. It lives
 * here rather than in the authProvider so server-rendered pages don't import react-admin.
 */
export const configureOidc = (config: OidcConfig) => {
  current = config;
};

export const currentOidcConfig = (): OidcConfig | null => current;
