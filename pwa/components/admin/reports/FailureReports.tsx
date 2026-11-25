import { useState } from "react";
import {
  Button,
  Datagrid,
  DateField,
  DateTimeInput,
  Edit,
  Form,
  FunctionField,
  List,
  NumberField,
  SaveButton,
  SelectField,
  SelectInput,
  Show,
  SimpleShowLayout,
  TextField,
  TextInput,
  Toolbar,
  TopToolbar,
  EditButton,
  required,
  useNotify,
  useRecordContext,
  useRedirect,
  useRefresh,
} from "react-admin";
import { apiFetch } from "../../common/api";
import { FORWARDING_CHOICES, ForwardingState, UPSTREAM_COMMENT_WARNING, addUpstreamComment, forwardReport } from "./forwarding";

/** react-admin resource names of the failure reports admin (ADR 0012) */
export const FAILURE_REPORT_RESOURCE = "admin/failure_reports";
export const FAILURE_REPORT_SUMMARY_RESOURCE = "admin/failure_reports/summary";

export const KIND_CHOICES = [
  { id: "job_failed", name: "Failed job" },
  { id: "unsupported_url", name: "Unsupported URL" },
];

export const STATUS_CHOICES = [
  { id: "new", name: "New" },
  { id: "acknowledged", name: "Acknowledged" },
  { id: "resolved", name: "Resolved" },
  { id: "wont_fix", name: "Won't fix" },
];

/** GET /admin/failure_reports/{id}: never includes the reporter or the job */
export type FailureReport = {
  "@id": string;
  id: string;
  kind: string;
  url: string;
  host: string;
  site: string | null;
  userAgent: string | null;
  referrer: string | null;
  diagnostics: Record<string, string | null> | null;
  comment: string | null;
  status: string;
  adminNote: string | null;
  createdAt: string;
  updatedAt: string;
  /** Phase 2 (forwarding to the FetchDock collector) */
  forwarding: ForwardingState;
  upstreamPolicyVersion: string | null;
  forwardingError: string | null;
  upstreamId: string | null;
  forwardedAt: string | null;
  upstreamComment: string | null;
  upstreamComments: { comment: string; createdAt: string; state: "pending" | "forwarded" | "failed"; error: string | null }[];
};

const reportFilters = [
  <SelectInput key="status" source="status" choices={STATUS_CHOICES} alwaysOn />,
  <SelectInput key="kind" source="kind" choices={KIND_CHOICES} />,
  <TextInput key="site" source="site" label="Site (slug)" />,
  <TextInput key="host" source="host" label="Host" />,
  <SelectInput key="forwarding" source="forwarding" label="Shared with developers" choices={FORWARDING_CHOICES} />,
];

export const FailureReportList = () => (
  <List filters={reportFilters} perPage={30} exporter={false} title="Failure reports">
    <Datagrid rowClick="show" bulkActionButtons={false}>
      <DateField source="createdAt" showTime sortable={false} />
      <SelectField source="kind" choices={KIND_CHOICES} sortable={false} />
      <TextField source="host" sortable={false} />
      <TextField source="site" sortable={false} emptyText="-" />
      <TextField source="url" sortable={false} />
      <SelectField source="status" choices={STATUS_CHOICES} sortable={false} />
      <SelectField source="forwarding" label="Shared" choices={FORWARDING_CHOICES} sortable={false} />
    </Datagrid>
  </List>
);

/**
 * Forwarding to the FetchDock developers (phase 2 of ADR 0012): only for reports the reporter agreed to share. Admins
 * forward them (review mode or after a failure), and add comments to forwarded ones.
 */
const UpstreamPanel = () => {
  const record = useRecordContext<FailureReport>();
  const notify = useNotify();
  const refresh = useRefresh();
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);

  if (!record || "not_requested" === record.forwarding) {
    return <p>The reporter did not agree to share this report with the FetchDock developers.</p>;
  }

  const canForward = "awaiting_review" === record.forwarding || "failed" === record.forwarding;
  const canComment = "forwarded" === record.forwarding;
  const run = async () => {
    setBusy(true);
    try {
      if (canForward) {
        await forwardReport(record.id, text.trim() || null);
        notify("Report queued for the developers", { type: "success" });
      } else {
        await addUpstreamComment(record.id, text);
        notify("Comment queued for the developers", { type: "success" });
      }
      setText("");
      refresh();
    } catch (error) {
      notify((error as Error).message || "The request failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="font-medium">State</dt>
        <dd>{FORWARDING_CHOICES.find((choice) => choice.id === record.forwarding)?.name ?? record.forwarding}</dd>
        {record.forwardingError && (
          <>
            <dt className="font-medium">Reason</dt>
            <dd style={{ whiteSpace: "pre-wrap" }}>{record.forwardingError}</dd>
          </>
        )}
        <dt className="font-medium">Reporter agreed to policy</dt>
        <dd>{record.upstreamPolicyVersion ?? "-"}</dd>
        {record.upstreamId && (
          <>
            <dt className="font-medium">Collector id</dt>
            <dd className="font-mono">{record.upstreamId}</dd>
            <dt className="font-medium">Forwarded</dt>
            <dd>{record.forwardedAt ? new Date(record.forwardedAt).toLocaleString() : "-"}</dd>
          </>
        )}
        {record.upstreamComment && (
          <>
            <dt className="font-medium">Comment sent with it</dt>
            <dd style={{ whiteSpace: "pre-wrap" }}>{record.upstreamComment}</dd>
          </>
        )}
      </dl>
      {record.upstreamComments.length > 0 && (
        <ul className="mt-2 text-sm">
          {record.upstreamComments.map((comment) => (
            <li key={comment.createdAt + comment.comment} className="mt-1">
              <span className="opacity-75">
                {new Date(comment.createdAt).toLocaleString()} · {comment.state}
                {comment.error ? ` (${comment.error})` : ""}
              </span>
              <div style={{ whiteSpace: "pre-wrap" }}>{comment.comment}</div>
            </li>
          ))}
        </ul>
      )}
      {(canForward || canComment) && (
        <div className="mt-3">
          <textarea
            className="w-full rounded border p-2 text-sm"
            rows={3}
            maxLength={4000}
            value={text}
            onChange={(event) => setText(event.target.value)}
            placeholder={canForward ? "Optional comment for the developers" : "Comment for the developers"}
          />
          <p className="text-xs opacity-75">{UPSTREAM_COMMENT_WARNING}</p>
          <Button
            label={canForward ? "Forward to developers" : "Send comment"}
            variant="contained"
            disabled={busy || (canComment && "" === text.trim())}
            onClick={run}
          />
        </div>
      )}
    </div>
  );
};

const Diagnostics = () => (
  <FunctionField
    label="Diagnostics"
    render={(report: FailureReport) =>
      report.diagnostics ? (
        <dl>
          {Object.entries(report.diagnostics)
            .filter(([, value]) => null !== value)
            .map(([key, value]) => (
              <div key={key}>
                <dt style={{ fontWeight: 600 }}>{key}</dt>
                <dd style={{ whiteSpace: "pre-wrap", margin: 0 }}>{value}</dd>
              </div>
            ))}
        </dl>
      ) : (
        "-"
      )
    }
  />
);

export const FailureReportShow = () => (
  <Show
    title="Failure report"
    actions={
      <TopToolbar>
        <EditButton label="Triage" />
      </TopToolbar>
    }
  >
    <SimpleShowLayout>
      <SelectField source="kind" choices={KIND_CHOICES} />
      <TextField source="url" />
      <TextField source="host" />
      <TextField source="site" emptyText="-" />
      <TextField source="userAgent" emptyText="-" />
      <TextField source="referrer" emptyText="-" />
      <Diagnostics />
      <TextField source="comment" label="Reporter's comment" emptyText="-" sx={{ whiteSpace: "pre-wrap" }} />
      <SelectField source="status" choices={STATUS_CHOICES} />
      <TextField source="adminNote" label="Internal note" emptyText="-" sx={{ whiteSpace: "pre-wrap" }} />
      <DateField source="createdAt" showTime />
      <DateField source="updatedAt" showTime />
      <FunctionField label="Shared with the FetchDock developers" render={() => <UpstreamPanel />} />
    </SimpleShowLayout>
  </Show>
);

/** PATCH {IRI} through apiFetch, which shows the API's validation messages */
const FailureReportForm = () => {
  const record = useRecordContext<FailureReport>();
  const notify = useNotify();
  const redirect = useRedirect();
  const [busy, setBusy] = useState(false);

  if (!record) {
    return null;
  }
  const iri = String(record["@id"] ?? record.id);

  const save = async (values: Record<string, unknown>) => {
    setBusy(true);
    try {
      await apiFetch(iri, {
        method: "PATCH",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ status: values.status, adminNote: values.adminNote || null }),
      });
      notify("Report saved", { type: "success" });
      redirect("show", FAILURE_REPORT_RESOURCE, iri);
    } catch (error) {
      notify((error as Error).message || "The request failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Form defaultValues={{ status: record.status, adminNote: record.adminNote ?? "" }} onSubmit={save}>
      <p>
        <strong>{record.url}</strong>
        {record.comment ? <><br />Reporter: {record.comment}</> : null}
      </p>
      <SelectInput source="status" choices={STATUS_CHOICES} validate={required()} />
      <TextInput
        source="adminNote"
        label="Internal note"
        helperText="Only admins of this server see it. It is never shown to the reporter or sent anywhere."
        multiline
        minRows={3}
        fullWidth
      />
      <Toolbar>
        <SaveButton alwaysEnable disabled={busy} />
      </Toolbar>
    </Form>
  );
};

export const FailureReportEdit = () => (
  <Edit actions={false} title="Triage failure report">
    <FailureReportForm />
  </Edit>
);

const summaryFilters = [
  <DateTimeInput key="since" source="since" label="Reported since" alwaysOn />,
  <SelectInput key="kind" source="kind" choices={KIND_CHOICES} alwaysOn />,
];

/** GET /admin/failure_reports/summary: the most reported hosts and sites first */
export const FailureReportSummaryList = () => (
  <List filters={summaryFilters} pagination={false} exporter={false} title="Failure report summary">
    <Datagrid bulkActionButtons={false} rowClick={false}>
      <TextField source="host" sortable={false} />
      <TextField source="site" sortable={false} emptyText="-" />
      <SelectField source="kind" choices={KIND_CHOICES} sortable={false} />
      <NumberField source="count" label="Reports" sortable={false} />
      <DateField source="lastReportedAt" label="Last reported" showTime sortable={false} />
    </Datagrid>
  </List>
);
