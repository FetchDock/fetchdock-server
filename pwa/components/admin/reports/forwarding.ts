import { apiFetch } from "../../common/api";

/** react-admin resource name of the forwarding settings (singleton: only its list page is used) */
export const FAILURE_REPORT_FORWARDING_RESOURCE = "admin/failure_report_forwarding";

export type ForwardingMode = "review" | "automatic";
export type ForwardingState = "not_requested" | "awaiting_review" | "pending" | "forwarded" | "failed";

export const FORWARDING_CHOICES: { id: ForwardingState; name: string }[] = [
  { id: "not_requested", name: "Not shared" },
  { id: "awaiting_review", name: "Awaiting review" },
  { id: "pending", name: "Sending" },
  { id: "forwarded", name: "Forwarded" },
  { id: "failed", name: "Failed" },
];

export type CollectorPolicy = { version: string; url: string; acceptedVersions: string[] };

/** GET /admin/failure_report_forwarding (phase 2 of ADR 0012) */
export type FailureReportForwarding = {
  enabled: boolean;
  mode: ForwardingMode;
  acceptedPolicyVersion: string | null;
  acceptedAt: string | null;
  available: boolean;
  collectorUrl: string | null;
  policy: CollectorPolicy | null;
  acceptedPolicyCurrent: boolean;
  instanceId: string | null;
  registeredAt: string | null;
  awaitingReview: number;
  failed: number;
};

export const fetchForwarding = () => apiFetch<FailureReportForwarding>("/admin/failure_report_forwarding");

export const saveForwarding = (body: { enabled: boolean; mode: ForwardingMode; acceptedPolicyVersion: string | null }) =>
  apiFetch<FailureReportForwarding>("/admin/failure_report_forwarding", {
    method: "PUT",
    headers: { "Content-Type": "application/ld+json" },
    body: JSON.stringify(body),
  });

export const forwardReport = (id: string, upstreamComment: string | null) =>
  apiFetch(`/admin/failure_reports/${id}/forward`, {
    method: "POST",
    headers: { "Content-Type": "application/ld+json" },
    body: JSON.stringify({ upstreamComment }),
  });

export const addUpstreamComment = (id: string, comment: string) =>
  apiFetch(`/admin/failure_reports/${id}/upstream_comments`, {
    method: "POST",
    headers: { "Content-Type": "application/ld+json" },
    body: JSON.stringify({ comment }),
  });

/** Shown wherever an admin writes for the developers */
export const UPSTREAM_COMMENT_WARNING =
  "This comment leaves this server: it is sent to the FetchDock developers as written. Don't include personal data. Internal notes are never sent.";
