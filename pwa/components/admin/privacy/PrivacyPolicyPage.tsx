import { useCallback, useEffect, useState } from "react";
import {
  ArrayInput,
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
import {
  AdminPrivacyPolicy,
  FIELD_LABELS,
  INTEGRITY_LABELS,
  LEGAL_BASIS_CHOICES,
  PolicyRevision,
  REASON_LABELS,
  REVISION_REASON_LABELS,
  fetchPreview,
  fetchPrivacyPolicy,
  fetchRevisions,
  savePrivacyPolicy,
  toAnswers,
  violationsToErrors,
} from "./api";

/** ISO 3166-1 alpha-2 codes are upper case; the API rejects lower case ones */
const upperCase = (value: string | null) => (value ? value.toUpperCase() : value);
const days = [minValue(0), (value: unknown) => (null == value || Number.isInteger(Number(value)) ? undefined : "Whole days")];

const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
  <section className="mb-6 rounded border border-gray-200 bg-white p-4">
    <h2 className="mb-3 text-lg font-semibold">{title}</h2>
    {children}
  </section>
);

const StatusPanel = ({ policy }: { policy: AdminPrivacyPolicy }) => (
  <Section title="Status">
    {"generated" === policy.status && (
      <p>
        The generated policy is published at{" "}
        <a className="underline" href="/privacy" target="_blank" rel="noreferrer">
          /privacy
        </a>{" "}
        (revision {policy.revision}).
      </p>
    )}
    {"custom" === policy.status && (
      <p>
        PRIVACY_POLICY_URL is set, so clients link the operator&apos;s own policy. The answers below are only used if it
        is removed.
      </p>
    )}
    {"unavailable" === policy.status && (
      <p className="font-medium text-red-700">
        No privacy policy is published: {policy.reason ? REASON_LABELS[policy.reason] : "unknown reason"}. Clients show
        that this instance has none.
      </p>
    )}
    {policy.missing.length > 0 && (
      <p className="mt-2">Missing: {policy.missing.map((path) => FIELD_LABELS[path] ?? path).join(", ")}.</p>
    )}
    <p className="mt-2 text-sm text-gray-600">
      Integrity: {INTEGRITY_LABELS[policy.integrity.status]}. The generated policy is only published while the code reads
      verified. See Instance integrity for details.
    </p>
  </Section>
);

const RevisionsPanel = ({ revisions }: { revisions: PolicyRevision[] }) => (
  <Section title="Revisions">
    {0 === revisions.length ? (
      <p>None yet: the first revision is created when the generated policy is published.</p>
    ) : (
      <table className="w-full text-left text-sm">
        <thead>
          <tr>
            <th className="py-1">Revision</th>
            <th>Created</th>
            <th>Why</th>
            <th>FetchDock</th>
            <th>Text version</th>
          </tr>
        </thead>
        <tbody>
          {revisions.map((revision) => (
            <tr key={revision.number} className="border-t border-gray-100">
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

const PreviewPanel = () => {
  const notify = useNotify();
  const [html, setHtml] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      setHtml(await fetchPreview());
    } catch (error) {
      notify((error as Error).message || "The preview failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Section title="Preview">
      <p className="mb-2 text-sm text-gray-600">The policy as rendered from the saved answers, also while it isn&apos;t published.</p>
      <Button label={html ? "Refresh preview" : "Show preview"} onClick={load} disabled={busy} />
      {html && (
        // No scripts or same-origin access: the page is static HTML
        <iframe title="Privacy policy preview" sandbox="" srcDoc={html} className="mt-3 h-[36rem] w-full rounded border border-gray-200" />
      )}
    </Section>
  );
};

/**
 * The operator's answers for the generated privacy policy (ADR 0013), its status, preview and revisions.
 * PUT replaces all answers; incomplete answers can be saved.
 */
export const PrivacyPolicyPage = () => {
  const notify = useNotify();
  const [policy, setPolicy] = useState<AdminPrivacyPolicy | null>(null);
  const [revisions, setRevisions] = useState<PolicyRevision[]>([]);
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

  const save = async (values: Record<string, any>) => {
    try {
      const answers = toAnswers(values as AdminPrivacyPolicy);
      answers.processors = answers.processors.filter((processor) => processor.name || processor.purpose || processor.country);
      setPolicy(await savePrivacyPolicy(answers));
      setRevisions(await fetchRevisions());
      notify("Answers saved", { type: "success" });
    } catch (failure) {
      if (failure instanceof HttpError && 422 === failure.status) {
        notify("Some answers are invalid", { type: "error" });
        return violationsToErrors(failure.body) ?? undefined;
      }
      notify((failure as Error).message || "Saving failed", { type: "error" });
    }
  };

  if (error) {
    return <p className="p-4 text-red-700">{error}</p>;
  }
  if (!policy) {
    return <Loading />;
  }

  return (
    <div className="max-w-4xl p-4">
      <Title title="Privacy policy" />
      <StatusPanel policy={policy} />
      <Section title="Answers">
        <p className="mb-3 text-sm text-gray-600">
          FetchDock writes the policy from what the software stores and from the instance&apos;s configuration. These
          answers cover what only you know. You adopt the text as the operator; it is not legal advice.
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
          <TextInput source="hosting.country" label="Country" helperText="ISO 3166-1 alpha-2 code, e.g. NL" parse={upperCase} validate={maxLength(2)} />
          <ArrayInput source="processors" label="Other service providers (processors)" helperText="For example a backup service">
            <SimpleFormIterator inline disableReordering>
              <TextInput source="name" label="Name" validate={maxLength(255)} />
              <TextInput source="purpose" label="Purpose" validate={maxLength(500)} />
              <TextInput source="country" label="Country" parse={upperCase} validate={maxLength(2)} />
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
            <SaveButton alwaysEnable />
          </Toolbar>
        </Form>
      </Section>
      <PreviewPanel />
      <RevisionsPanel revisions={revisions} />
    </div>
  );
};
