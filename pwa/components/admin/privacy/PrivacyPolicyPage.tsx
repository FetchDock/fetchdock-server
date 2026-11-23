import { useCallback, useEffect, useState } from "react";
import {
  ArrayInput,
  AutocompleteInput,
  Button,
  Form,
  HttpError,
  Loading,
  NumberInput,
  SaveButton,
  SelectInput,
  SimpleFormIterator,
  TextInput,
  Title,
  Toolbar,
  maxLength,
  minValue,
  useNotify,
} from "react-admin";
import { COUNTRY_CHOICES } from "./countries";
import {
  AdminPrivacyPolicy,
  FIELD_LABELS,
  INTEGRITY_LABELS,
  LEGAL_BASIS_CHOICES,
  PENDING_REASON_LABELS,
  PolicyRevision,
  REASON_LABELS,
  REVISION_REASON_LABELS,
  fetchPreview,
  fetchPrivacyPolicy,
  fetchRevisions,
  notifyPrivacyPolicyChanged,
  publishPrivacyPolicy,
  savePrivacyPolicy,
  toAnswers,
  useIsDarkTheme,
  violationsToErrors,
} from "./api";

const days = [minValue(0), (value: unknown) => (null == value || Number.isInteger(Number(value)) ? undefined : "Whole days")];

const Section = ({ title, children }: { title: string; children: React.ReactNode }) => {
  const isDark = useIsDarkTheme();

  return (
    <section className={`mb-6 rounded border p-4 ${isDark ? "border-white/15 bg-white/[0.04]" : "border-gray-200 bg-white"}`}>
      <h2 className="mb-3 text-lg font-semibold">{title}</h2>
      {children}
    </section>
  );
};

const ReviewAndPublishPanel = ({
  policy,
  publishing,
  onPublish,
  onPreview,
}: {
  policy: AdminPrivacyPolicy;
  publishing: boolean;
  onPublish: () => void;
  onPreview: () => void;
}) => {
  const isDark = useIsDarkTheme();

  if ("custom" === policy.status || "disabled" === policy.status || !policy.pendingPublish) {
    return null;
  }

  const canPublish = "verified" === policy.integrity.status && 0 === policy.missing.length;
  const nextRevision = (policy.revision ?? 0) + 1;
  const urgent = policy.outdated;
  const tone = urgent
    ? isDark
      ? "border-red-500/80 bg-red-950/60 text-red-50"
      : "border-red-400 bg-red-50 text-red-950"
    : isDark
      ? "border-amber-600/70 bg-amber-950/50 text-amber-50"
      : "border-amber-300 bg-amber-50 text-amber-950";

  return (
    <section role="alert" className={`mb-6 rounded border-2 p-4 ${tone}`}>
      <h2 className="mb-2 text-lg font-semibold">
        {urgent
          ? "Action required: review and publish updated privacy policy"
          : null === policy.revision
            ? "Review and publish your privacy policy"
            : "Unpublished privacy policy changes"}
      </h2>
      {urgent ? (
        <p className="mb-2 text-sm">
          FetchDock never updates your published privacy policy automatically. Until you review and approve the new
          revision, <code>/privacy</code> continues serving revision {policy.revision} with a notice that an update is
          pending operator review.
        </p>
      ) : (
        <p className="mb-2 text-sm">
          Saving answers updates the preview below without changing what is published. Review the preview and click{" "}
          <strong>Approve &amp; publish</strong> when you are ready to adopt revision {nextRevision}.
        </p>
      )}
      {policy.pendingReasons.length > 0 && (
        <ul className="mb-3 list-disc pl-5 text-sm">
          {policy.pendingReasons.map((reason) => (
            <li key={reason}>{PENDING_REASON_LABELS[reason] ?? reason}</li>
          ))}
        </ul>
      )}
      {policy.pendingTextChanges.length > 0 && (
        <div className={`mb-3 rounded border p-3 text-sm ${isDark ? "border-white/15 bg-black/20" : "border-black/10 bg-white/70"}`}>
          <p className="mb-1 font-medium">
            Policy text changes ({policy.publishedTextVersion ?? "none"} → {policy.currentTextVersion}):
          </p>
          <ul className="list-disc pl-5">
            {policy.pendingTextChanges.map((entry) => (
              <li key={entry.version}>
                <strong>{entry.version}:</strong> {entry.changes}
              </li>
            ))}
          </ul>
        </div>
      )}
      {!canPublish && (
        <p className="mb-3 text-sm font-medium">
          {policy.missing.length > 0
            ? `Complete the missing required answers (${policy.missing.map((path) => FIELD_LABELS[path] ?? path).join(", ")}) and save before publishing.`
            : "Publishing is only available while the code integrity check reads verified."}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <Button label="Preview updated policy" onClick={onPreview} variant="outlined" />
        <Button
          label={publishing ? "Publishing…" : `Approve & publish revision ${nextRevision}`}
          onClick={onPublish}
          disabled={!canPublish || publishing}
          variant="contained"
        />
      </div>
      <p className="mt-2 text-xs opacity-80">
        By publishing, you confirm that you have reviewed the preview and adopt this revision as the privacy policy of
        this instance. Save any edits in the Answers form below first if you changed them.
      </p>
    </section>
  );
};

const StatusPanel = ({ policy }: { policy: AdminPrivacyPolicy }) => {
  const isDark = useIsDarkTheme();

  return (
    <Section title="Status">
      {"generated" === policy.status && (
        <p>
          The generated policy is published at{" "}
          <a className="underline" href="/privacy" target="_blank" rel="noreferrer">
            /privacy
          </a>{" "}
          (revision {policy.revision}, policy text {policy.publishedTextVersion ?? policy.currentTextVersion}
          {policy.outdated ? " — pending operator review for newer software/configuration changes" : ""}).
        </p>
      )}
      {"custom" === policy.status && (
        <p>
          PRIVACY_POLICY_URL is set, so clients link the operator&apos;s own policy. The answers below are only used if it
          is removed.
        </p>
      )}
      {"disabled" === policy.status && (
        <p>
          Privacy policy is disabled (<code>PRIVACY_POLICY_ENABLED=0</code>). No privacy policy is published and no admin
          reminders are shown. Remove <code>PRIVACY_POLICY_ENABLED=0</code> to enable the generated policy or{" "}
          <code>PRIVACY_POLICY_URL</code>.
        </p>
      )}
      {"unavailable" === policy.status && (
        <p className={`font-medium ${isDark ? "text-red-400" : "text-red-700"}`}>
          No privacy policy is published: {policy.reason ? REASON_LABELS[policy.reason] : "unknown reason"}. Clients show
          that this instance has none.
        </p>
      )}
      {policy.missing.length > 0 && (
        <p className="mt-2">Missing: {policy.missing.map((path) => FIELD_LABELS[path] ?? path).join(", ")}.</p>
      )}
      <p className="mt-2 text-sm opacity-75">
        Integrity: {INTEGRITY_LABELS[policy.integrity.status]}. The generated policy is only published while the code reads
        verified. See Instance integrity for details.
      </p>
    </Section>
  );
};

const RevisionsPanel = ({ revisions }: { revisions: PolicyRevision[] }) => {
  const isDark = useIsDarkTheme();

  return (
    <Section title="Revisions">
      {0 === revisions.length ? (
        <p>None yet: the first revision is created when an administrator approves and publishes the generated policy.</p>
      ) : (
        <table className="w-full text-left text-sm">
          <thead>
            <tr>
              <th className="py-1">Revision</th>
              <th>Published</th>
              <th>Why</th>
              <th>FetchDock</th>
              <th>Text version</th>
            </tr>
          </thead>
          <tbody>
            {revisions.map((revision) => (
              <tr key={revision.number} className={`border-t ${isDark ? "border-white/10" : "border-gray-100"}`}>
                <td className="py-1">
                  <a className="underline" href={`/privacy?revision=${revision.number}`} target="_blank" rel="noreferrer">
                    {revision.number}
                  </a>
                  {revision.current && " (current)"}
                </td>
                <td>{new Date(revision.createdAt).toLocaleString()}</td>
                <td>{REVISION_REASON_LABELS[revision.reason] ?? revision.reason}</td>
                <td>{revision.appVersion}</td>
                <td>{revision.textVersion}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Section>
  );
};

const PreviewPanel = ({ html, busy, onLoad }: { html: string | null; busy: boolean; onLoad: () => void }) => {
  const isDark = useIsDarkTheme();

  return (
    <Section title="Preview">
      <p className="mb-2 text-sm opacity-75">
        The policy as rendered from the saved answers and current FetchDock version/configuration. Reviewing this preview
        does not publish it.
      </p>
      <Button label={html ? "Refresh preview" : "Show preview"} onClick={onLoad} disabled={busy} />
      {html && (
        // No scripts or same-origin access: the page is static HTML
        <iframe
          title="Privacy policy preview"
          sandbox=""
          srcDoc={html}
          className={`mt-3 h-[36rem] w-full rounded border ${isDark ? "border-white/15" : "border-gray-200"}`}
        />
      )}
    </Section>
  );
};

/**
 * The operator's answers for the generated privacy policy (ADR 0013), its status, explicit publish flow, preview and
 * revisions. PUT saves draft answers without altering /privacy; POST /admin/privacy_policy/publish explicitly approves
 * and publishes a new revision.
 */
export const PrivacyPolicyPage = () => {
  const notify = useNotify();
  const isDark = useIsDarkTheme();
  const [policy, setPolicy] = useState<AdminPrivacyPolicy | null>(null);
  const [revisions, setRevisions] = useState<PolicyRevision[]>([]);
  const [previewHtml, setPreviewHtml] = useState<string | null>(null);
  const [previewBusy, setPreviewBusy] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    try {
      const [current, list] = await Promise.all([fetchPrivacyPolicy(), fetchRevisions()]);
      setPolicy(current);
      setRevisions(list);
      setError(null);
    } catch (failure) {
      setError((failure as Error).message || "The privacy policy could not be loaded");
    }
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  const loadPreview = useCallback(async () => {
    setPreviewBusy(true);
    try {
      setPreviewHtml(await fetchPreview());
    } catch (failure) {
      notify((failure as Error).message || "The preview failed", { type: "error" });
    } finally {
      setPreviewBusy(false);
    }
  }, [notify]);

  const save = async (values: Record<string, any>) => {
    try {
      const answers = toAnswers(values as AdminPrivacyPolicy);
      answers.processors = answers.processors.filter((processor) => processor.name || processor.purpose || processor.country);
      const updated = await savePrivacyPolicy(answers);
      if (updated) {
        setPolicy(updated);
      }
      setRevisions(await fetchRevisions());
      notifyPrivacyPolicyChanged();
      if (null !== previewHtml) {
        loadPreview();
      }
      notify(
        updated?.pendingPublish ? "Answers saved — review the preview and click Approve & publish to go live" : "Answers saved",
        { type: "success" },
      );
    } catch (failure) {
      if (failure instanceof HttpError && 422 === failure.status) {
        notify("Some answers are invalid", { type: "error" });
        return violationsToErrors(failure.body) ?? undefined;
      }
      notify((failure as Error).message || "Saving failed", { type: "error" });
    }
  };

  const publish = async () => {
    setPublishing(true);
    try {
      const updated = await publishPrivacyPolicy();
      if (updated) {
        setPolicy(updated);
      }
      setRevisions(await fetchRevisions());
      notifyPrivacyPolicyChanged();
      notify(updated?.revision ? `Privacy policy revision ${updated.revision} published` : "Privacy policy published", {
        type: "success",
      });
    } catch (failure) {
      notify((failure as Error).message || "Publishing failed", { type: "error" });
    } finally {
      setPublishing(false);
    }
  };

  if (error) {
    return <p className={`p-4 ${isDark ? "text-red-400" : "text-red-700"}`}>{error}</p>;
  }
  if (!policy) {
    return <Loading />;
  }

  return (
    <div className="max-w-4xl p-4">
      <Title title="Privacy policy" />
      <ReviewAndPublishPanel policy={policy} publishing={publishing} onPublish={publish} onPreview={loadPreview} />
      <StatusPanel policy={policy} />
      <PreviewPanel html={previewHtml} busy={previewBusy} onLoad={loadPreview} />
      <Section title="Answers">
        <p className="mb-3 text-sm opacity-75">
          FetchDock writes the policy from what the software stores and from the instance&apos;s configuration. These
          answers cover what only you know. Saving updates the preview; nothing goes live on <code>/privacy</code> until
          you approve and publish. It is not legal advice.
        </p>
        <Form key={policy.revision ?? 0} record={toAnswers(policy)} onSubmit={save}>
          <TextInput source="instanceName" label="Instance name" helperText="The policy's title" validate={maxLength(255)} fullWidth />
          <h3 className="mt-2 font-medium">Operator (controller)</h3>
          <TextInput source="operator.name" label="Name" helperText="Person or organization running the instance" validate={maxLength(255)} fullWidth />
          <TextInput source="operator.address" label="Address" multiline validate={maxLength(1000)} fullWidth />
          <TextInput source="operator.contactEmail" label="Privacy contact email" type="email" validate={maxLength(255)} fullWidth />
          <TextInput source="operator.dpoContact" label="Data protection officer" helperText="If you have one" validate={maxLength(255)} fullWidth />
          <h3 className="mt-2 font-medium">Sign-in</h3>
          <TextInput source="identityProvider.name" label="Identity provider" helperText='For example "Authentik, run by the operator"' validate={maxLength(255)} fullWidth />
          <TextInput source="identityProvider.privacyPolicyUrl" label="Its privacy policy (URL)" validate={maxLength(2048)} fullWidth />
          <h3 className="mt-2 font-medium">Hosting</h3>
          <TextInput source="hosting.provider" label="Hosting provider" helperText='Or "self-hosted"' validate={maxLength(255)} fullWidth />
          <AutocompleteInput
            source="hosting.country"
            label="Country"
            choices={COUNTRY_CHOICES}
            helperText="Search by country name or ISO 3166-1 alpha-2 code"
            fullWidth
          />
          <ArrayInput source="processors" label="Other service providers (processors)" helperText="For example a backup service">
            <SimpleFormIterator inline disableReordering>
              <TextInput source="name" label="Name" validate={maxLength(255)} />
              <TextInput source="purpose" label="Purpose" validate={maxLength(500)} />
              <AutocompleteInput source="country" label="Country" choices={COUNTRY_CHOICES} sx={{ minWidth: 220 }} />
            </SimpleFormIterator>
          </ArrayInput>
          <h3 className="mt-2 font-medium">Retention</h3>
          <NumberInput source="retention.logsDays" label="Logs kept for (days)" helperText="Container and web server logs" validate={days} />
          <TextInput source="retention.backups" label="Backups" helperText="How backups are made and kept, in a sentence" validate={maxLength(1000)} fullWidth />
          <NumberInput source="retention.softDeletedJobsDays" label="Erase deleted jobs after (days)" helperText="Only if you do" validate={days} />
          <NumberInput source="retention.reportsDays" label="Delete failure reports after (days)" helperText="Only if you do" validate={days} />
          <h3 className="mt-2 font-medium">Legal</h3>
          <SelectInput source="legalBasis" label="Legal basis" choices={LEGAL_BASIS_CHOICES} emptyText="Not answered" />
          <TextInput source="supervisoryAuthority" label="Supervisory authority" helperText="Where users can complain" validate={maxLength(255)} fullWidth />
          <TextInput
            source="additionalText"
            label="Additional information (Markdown)"
            helperText="Appended as its own section. Raw HTML is shown as text."
            multiline
            minRows={4}
            validate={maxLength(10000)}
            fullWidth
          />
          <Toolbar>
            <SaveButton label="Save answers" alwaysEnable />
          </Toolbar>
        </Form>
      </Section>
      <RevisionsPanel revisions={revisions} />
    </div>
  );
};
