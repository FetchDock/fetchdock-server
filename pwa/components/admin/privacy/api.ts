import { HttpError, useTheme } from "react-admin";
import { apiFetch, getAuthHeaders } from "../../common/api";

/** Whether react-admin's active theme is dark (falling back to the browser preference when unset) */
export const useIsDarkTheme = (): boolean => {
  const [theme] = useTheme();
  if ("dark" === theme) {
    return true;
  }
  if ("light" === theme) {
    return false;
  }

  return typeof window !== "undefined" && Boolean(window.matchMedia?.("(prefers-color-scheme: dark)").matches);
};

/** react-admin resource names of the privacy policy admin (ADR 0013). Singletons: only their list page is used. */
export const PRIVACY_POLICY_RESOURCE = "admin/privacy_policy";
export const INSTANCE_INTEGRITY_RESOURCE = "admin/instance_integrity";

export type IntegrityStatus = "verified" | "modified" | "unknown";
export type PolicyStatus = "generated" | "custom" | "unavailable";
export type UnavailableReason = "incomplete" | "integrity_modified" | "integrity_unknown";

export type IntegritySummary = {
  status: IntegrityStatus;
  manifestDigest: string | null;
  gitSha: string | null;
  checkedAt: string | null;
};

/** The operator's answers (AdminPrivacyPolicy, privacy_policy:write) */
export type PrivacyPolicyAnswers = {
  instanceName: string | null;
  operator: { name: string | null; address: string | null; contactEmail: string | null; dpoContact: string | null };
  identityProvider: { name: string | null; privacyPolicyUrl: string | null };
  hosting: { provider: string | null; country: string | null };
  processors: { name: string | null; purpose: string | null; country: string | null }[];
  retention: { logsDays: number | null; backups: string | null; softDeletedJobsDays: number | null; reportsDays: number | null };
  legalBasis: "contract" | "legitimate_interest" | null;
  supervisoryAuthority: string | null;
  additionalText: string | null;
};

/** GET /admin/privacy_policy */
export type AdminPrivacyPolicy = PrivacyPolicyAnswers & {
  missing: string[];
  status: PolicyStatus;
  reason: UnavailableReason | null;
  revision: number | null;
  integrity: IntegritySummary;
};

/** GET /admin/privacy_policy/revisions */
export type PolicyRevision = {
  number: number;
  reason: "initial" | "answers" | "app_version" | "configuration";
  textVersion: string;
  appVersion: string;
  createdAt: string;
  current: boolean;
};

/** GET /admin/instance_integrity */
export type InstanceIntegrity = {
  status: IntegrityStatus;
  appVersion: string;
  manifestDigest: string | null;
  gitSha: string | null;
  checkOnline: boolean;
  checks: {
    host: string;
    role: "api" | "worker";
    status: IntegrityStatus;
    manifestDigest: string | null;
    appVersion: string;
    mismatches: string[];
    checkedAt: string;
  }[];
};

export const LEGAL_BASIS_CHOICES = [
  { id: "legitimate_interest", name: "Legitimate interest (personal and family installs)" },
  { id: "contract", name: "Contract (a service with terms users agreed to)" },
];

export const REASON_LABELS: Record<UnavailableReason, string> = {
  integrity_modified: "the integrity check found files that differ from the official build",
  integrity_unknown: "the instance could not verify its code recently (offline, a development or forked build, or no recent check)",
  incomplete: "required answers are missing",
};

export const REVISION_REASON_LABELS: Record<PolicyRevision["reason"], string> = {
  initial: "First revision",
  answers: "Answers changed",
  app_version: "FetchDock or policy text updated",
  configuration: "Configuration changed",
};

export const INTEGRITY_LABELS: Record<IntegrityStatus, string> = {
  verified: "Verified: unmodified FetchDock",
  modified: "Modified: files differ from the official build",
  unknown: "Unknown: the code could not be verified",
};

/** Labels of the required answers, keyed by the API's dotted paths (missing) */
export const FIELD_LABELS: Record<string, string> = {
  instanceName: "Instance name",
  "operator.name": "Operator name",
  "operator.contactEmail": "Privacy contact email",
  "identityProvider.name": "Identity provider",
  "hosting.provider": "Hosting provider",
  "hosting.country": "Hosting country",
  "retention.logsDays": "Log retention (days)",
  legalBasis: "Legal basis",
};

const json = { "Content-Type": "application/ld+json" };

export const fetchPrivacyPolicy = () => apiFetch<AdminPrivacyPolicy>("/admin/privacy_policy");

export const savePrivacyPolicy = (answers: PrivacyPolicyAnswers) =>
  apiFetch<AdminPrivacyPolicy>("/admin/privacy_policy", { method: "PUT", headers: json, body: JSON.stringify(answers) });

export const fetchRevisions = async (): Promise<PolicyRevision[]> =>
  (await apiFetch<{ member?: PolicyRevision[] }>("/admin/privacy_policy/revisions"))?.member ?? [];

export const fetchInstanceIntegrity = () => apiFetch<InstanceIntegrity>("/admin/instance_integrity");

/** The rendered policy from the saved answers (HTML), also while it isn't published */
export const fetchPreview = async (): Promise<string> => {
  const response = await fetch(new URL("/admin/privacy_policy/preview", window.origin), {
    headers: { Accept: "text/html", ...getAuthHeaders() },
  });
  if (!response.ok) {
    throw new HttpError(response.statusText || "The preview failed", response.status);
  }

  return response.text();
};

/** The answers of a policy, without the read-only or JSON-LD (@id, @type) fields */
export const toAnswers = (policy: AdminPrivacyPolicy | PrivacyPolicyAnswers): PrivacyPolicyAnswers => ({
  instanceName: policy.instanceName ?? null,
  operator: {
    name: policy.operator?.name ?? null,
    address: policy.operator?.address ?? null,
    contactEmail: policy.operator?.contactEmail ?? null,
    dpoContact: policy.operator?.dpoContact ?? null,
  },
  identityProvider: {
    name: policy.identityProvider?.name ?? null,
    privacyPolicyUrl: policy.identityProvider?.privacyPolicyUrl ?? null,
  },
  hosting: {
    provider: policy.hosting?.provider ?? null,
    country: policy.hosting?.country ?? null,
  },
  processors: (policy.processors ?? []).map(({ name, purpose, country }) => ({
    name: name ?? null,
    purpose: purpose ?? null,
    country: country ?? null,
  })),
  retention: {
    logsDays: policy.retention?.logsDays ?? null,
    backups: policy.retention?.backups ?? null,
    softDeletedJobsDays: policy.retention?.softDeletedJobsDays ?? null,
    reportsDays: policy.retention?.reportsDays ?? null,
  },
  legalBasis: policy.legalBasis ?? null,
  supervisoryAuthority: policy.supervisoryAuthority ?? null,
  additionalText: policy.additionalText ?? null,
});

/**
 * The API's 422 violations as a nested object for react-admin's Form (submission errors), e.g.
 * "processors[0].country" becomes { processors: [{ country: "..." }] }.
 */
export const violationsToErrors = (body: unknown): Record<string, unknown> | null => {
  const violations = (body as { violations?: { propertyPath?: string; message?: string }[] } | null)?.violations;
  if (!Array.isArray(violations) || 0 === violations.length) {
    return null;
  }

  const errors: Record<string, unknown> = {};
  for (const { propertyPath, message } of violations) {
    const keys = (propertyPath ?? "").split(/[.[\]]+/).filter(Boolean);
    if (0 === keys.length) {
      continue;
    }
    let node: Record<string, unknown> = errors;
    keys.forEach((key, index) => {
      if (index === keys.length - 1) {
        node[key] = message ?? "Invalid value";
      } else {
        node[key] ??= /^\d+$/.test(keys[index + 1]) ? [] : {};
        node = node[key] as Record<string, unknown>;
      }
    });
  }

  return errors;
};
