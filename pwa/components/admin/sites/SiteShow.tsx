import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrayField,
  BooleanField,
  Button,
  ChipField,
  Datagrid,
  Form,
  Labeled,
  NullableBooleanInput,
  SaveButton,
  SelectInput,
  Show,
  SimpleShowLayout,
  SingleFieldList,
  TextField,
  TextInput,
  Toolbar,
  useNotify,
  usePermissions,
  useRecordContext,
} from "react-admin";
import { apiFetch } from "../../common/api";
import { hasRole, ROLE_ADMIN } from "../../common/permissions";
import { DIRECTORY_RESOURCE, DownloadDirectory } from "../storage/DownloadDirectories";

/** GET /admin/sites/{slug}/canonicalization (null fields inherit) */
type SiteCanonicalization = {
  slug: string;
  profile: string | null;
  useExtractorId: boolean | null;
  keepParams: string[] | null;
  dropParams: string[] | null;
  stripWww: boolean | null;
  stripTrailingSlash: boolean | null;
  idGroups: Record<string, number[]> | null;
  siteSettings: Record<string, unknown> | null;
  effective: Record<string, unknown>;
  availableProfiles: string[];
};

/** GET /admin/canonicalize */
type Canonicalization = { key: string; layer: string; profile: string; site: string | null };

/** The form edits pattern lists as one glob per line and idGroups as JSON; empty = inherit */
type FormValues = {
  profile: string | null;
  useExtractorId: boolean | null;
  keepParams: string;
  dropParams: string;
  stripWww: boolean | null;
  stripTrailingSlash: boolean | null;
  idGroups: string;
};

const toLines = (patterns: string[] | null): string => (patterns ?? []).join("\n");

const fromLines = (text: string | undefined): string[] | null => {
  const patterns = (text ?? "").split("\n").map((line) => line.trim()).filter((line) => "" !== line);
  return patterns.length > 0 ? patterns : null;
};

const toFormValues = (data: SiteCanonicalization): FormValues => ({
  profile: data.profile,
  useExtractorId: data.useExtractorId,
  keepParams: toLines(data.keepParams),
  dropParams: toLines(data.dropParams),
  stripWww: data.stripWww,
  stripTrailingSlash: data.stripTrailingSlash,
  idGroups: data.idGroups ? JSON.stringify(data.idGroups) : "",
});

const validateIdGroups = (value: string | undefined) => {
  if (!value?.trim()) {
    return undefined;
  }
  try {
    const parsed = JSON.parse(value);
    return parsed && "object" === typeof parsed && !Array.isArray(parsed) ? undefined : 'Expected an object, e.g. {"tweet": [1]}';
  } catch {
    return "Invalid JSON";
  }
};

const Json = ({ value }: { value: unknown }) => (
  <pre style={{ margin: 0, whiteSpace: "pre-wrap", fontSize: "0.8rem" }}>{JSON.stringify(value, null, 2)}</pre>
);

/** Shows the canonical key a URL gets with the rules in effect (GET /admin/canonicalize) */
const CanonicalizeTester = () => {
  const [url, setUrl] = useState("");
  const { mutate, data, error, isPending } = useMutation({
    mutationFn: (value: string) => apiFetch<Canonicalization>(`/admin/canonicalize?url=${encodeURIComponent(value)}`),
  });

  return (
    <div>
      <form
        style={{ display: "flex", gap: "0.5rem", alignItems: "center" }}
        onSubmit={(event) => {
          event.preventDefault();
          mutate(url.trim());
        }}
      >
        <input
          type="url"
          value={url}
          onChange={(event) => setUrl(event.target.value)}
          placeholder="https://..."
          aria-label="URL to canonicalize"
          style={{ flex: 1, padding: "0.4rem" }}
        />
        <Button type="submit" label="Canonicalize" disabled={isPending || "" === url.trim()} />
      </form>
      {error && <p>{(error as Error).message}</p>}
      {data && <Json value={data} />}
    </div>
  );
};

/**
 * The site's canonicalization override (duplicate detection): edit, reset, and the effective profile.
 * Path rewrites are code only and can't be edited here.
 */
const CanonicalizationPanel = () => {
  const record = useRecordContext();
  const notify = useNotify();
  const queryClient = useQueryClient();
  const slug: string | undefined = record?.slug;
  const path = `/admin/sites/${slug}/canonicalization`;
  const queryKey = ["admin-site-canonicalization", slug];

  const { data, error, isPending } = useQuery({
    queryKey,
    queryFn: () => apiFetch<SiteCanonicalization>(path),
    enabled: undefined !== slug,
  });

  const onSaved = (message: string) => (result: SiteCanonicalization | null) => {
    notify(message, { type: "success" });
    if (result) {
      queryClient.setQueryData(queryKey, result);
    } else {
      queryClient.invalidateQueries({ queryKey });
    }
  };
  const onError = (error: Error) => notify(error.message || "Saving failed", { type: "error" });

  const save = useMutation({
    mutationFn: (values: FormValues) =>
      apiFetch<SiteCanonicalization>(path, {
        method: "PUT",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          profile: values.profile || null,
          useExtractorId: values.useExtractorId ?? null,
          keepParams: fromLines(values.keepParams),
          dropParams: fromLines(values.dropParams),
          stripWww: values.stripWww ?? null,
          stripTrailingSlash: values.stripTrailingSlash ?? null,
          idGroups: values.idGroups?.trim() ? JSON.parse(values.idGroups) : null,
        }),
      }),
    onSuccess: onSaved("Canonicalization override saved"),
    onError,
  });
  const reset = useMutation({
    mutationFn: () => apiFetch<null>(path, { method: "DELETE" }),
    onSuccess: onSaved("Canonicalization override removed"),
    onError,
  });

  if (error) {
    return <p>Couldn&apos;t load the canonicalization settings: {(error as Error).message}</p>;
  }
  if (isPending || !data) {
    return null;
  }

  return (
    <div>
      <p>
        Duplicate detection rules for this site. Empty fields inherit from the site settings (config/sites.yaml) and
        the code profile. Changes apply to new submissions; existing jobs keep their keys until{" "}
        <code>app:jobs:canonicalize --force</code>.
      </p>
      <Form key={JSON.stringify(data)} defaultValues={toFormValues(data)} onSubmit={(values: Record<string, unknown>) => save.mutate(values as FormValues)}>
        <SelectInput
          source="profile"
          label="Base profile"
          choices={data.availableProfiles.map((name) => ({ id: name, name }))}
          emptyText="Inherit"
          fullWidth
        />
        <NullableBooleanInput source="useExtractorId" label="Use the extractor ID" nullLabel="Inherit" fullWidth />
        <NullableBooleanInput source="stripWww" label="Strip www." nullLabel="Inherit" fullWidth />
        <NullableBooleanInput source="stripTrailingSlash" label="Strip trailing slashes" nullLabel="Inherit" fullWidth />
        <TextInput source="keepParams" label="Keep only these query parameters (globs, one per line)" multiline minRows={2} fullWidth />
        <TextInput source="dropParams" label="Also drop these query parameters (globs, one per line)" multiline minRows={2} fullWidth />
        <TextInput
          source="idGroups"
          label='gallery-dl ID groups (JSON, e.g. {"tweet": [1]})'
          validate={validateIdGroups}
          fullWidth
        />
        <Toolbar>
          <SaveButton label="Save override" disabled={save.isPending} alwaysEnable />
          <Button label="Remove override" onClick={() => reset.mutate()} disabled={reset.isPending} sx={{ ml: 2 }} />
        </Toolbar>
      </Form>
      <Labeled label="Site settings (config/sites.yaml)">
        <Json value={data.siteSettings ?? {}} />
      </Labeled>
      <Labeled label="Effective profile">
        <Json value={data.effective} />
      </Labeled>
      <Labeled label="Test a URL">
        <CanonicalizeTester />
      </Labeled>
    </div>
  );
};

/** GET /admin/sites/{slug}/storage */
type SiteStorage = {
  slug: string;
  directory: string | null;
  effectiveDirectory: string;
  fileCount: number;
  filesElsewhere: number;
};

/**
 * The site's download directory (PUT /admin/sites/{slug}/storage). It applies to new downloads; existing files stay
 * where they are.
 */
const StoragePanel = () => {
  const record = useRecordContext();
  const notify = useNotify();
  const queryClient = useQueryClient();
  const slug: string | undefined = record?.slug;
  const path = `/admin/sites/${slug}/storage`;
  const queryKey = ["admin-site-storage", slug];

  const storage = useQuery({
    queryKey,
    queryFn: () => apiFetch<SiteStorage>(path),
    enabled: undefined !== slug,
  });
  const directories = useQuery({
    queryKey: ["admin-download-directories"],
    queryFn: () => apiFetch<{ member: DownloadDirectory[] }>(`/${DIRECTORY_RESOURCE}`),
  });

  const save = useMutation({
    mutationFn: (directory: string | null) =>
      apiFetch<SiteStorage>(path, {
        method: "PUT",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ directory }),
      }),
    onSuccess: (result) => {
      notify("Download directory saved", { type: "success" });
      queryClient.setQueryData(queryKey, result);
      queryClient.invalidateQueries({ queryKey: ["admin-download-directories"] });
    },
    onError: (error: Error) => notify(error.message || "Saving failed", { type: "error" }),
  });

  const error = storage.error ?? directories.error;
  if (error) {
    return <p>Couldn&apos;t load the storage settings: {(error as Error).message}</p>;
  }
  if (!storage.data || !directories.data) {
    return null;
  }

  const all = directories.data.member;
  const byIri = new Map(all.map((directory) => [directory["@id"], directory]));
  const defaultDirectory = all.find((directory) => directory.isDefault);
  const effective = byIri.get(storage.data.effectiveDirectory);
  const label = (directory: DownloadDirectory) =>
    `${directory.name} (${directory.path})${directory.available ? "" : ", not mounted or not writable"}`;

  return (
    <div>
      <p>
        New downloads of this site go to {effective ? label(effective) : storage.data.effectiveDirectory}. Changing it
        applies to new downloads only: jobs already started keep their directory, and existing files stay where they
        are.
      </p>
      <p>
        {storage.data.fileCount} files, {storage.data.filesElsewhere} of them in another directory.
      </p>
      <Form
        key={JSON.stringify(storage.data)}
        defaultValues={{ directory: storage.data.directory ?? "" }}
        onSubmit={(values: Record<string, unknown>) => save.mutate((values.directory as string) || null)}
      >
        <SelectInput
          source="directory"
          label="Directory"
          choices={all.filter((directory) => !directory.isDefault).map((directory) => ({ id: directory["@id"], name: label(directory) }))}
          emptyText={defaultDirectory ? `Default: ${label(defaultDirectory)}` : "Default"}
          fullWidth
        />
        <Toolbar>
          <SaveButton label="Save directory" disabled={save.isPending} alwaysEnable />
        </Toolbar>
      </Form>
    </div>
  );
};

/** GET /sites/{slug}, with the storage and canonicalization editors for admins */
export const SiteShow = () => {
  const { permissions } = usePermissions();

  return (
    <Show>
      <SimpleShowLayout>
        <TextField source="slug" />
        <TextField source="name" />
        <ArrayField source="domains">
          <SingleFieldList linkType={false}>
            <ChipField source="" />
          </SingleFieldList>
        </ArrayField>
        <BooleanField source="enabled" />
        <BooleanField source="curated" />
        <ArrayField source="extractors">
          <Datagrid bulkActionButtons={false} rowClick={false}>
            <TextField source="downloader" />
            <TextField source="key" label="Extractor" />
          </Datagrid>
        </ArrayField>
        {hasRole(permissions, ROLE_ADMIN) && (
          <Labeled label="Storage" fullWidth>
            <StoragePanel />
          </Labeled>
        )}
        {hasRole(permissions, ROLE_ADMIN) && (
          <Labeled label="Canonicalization (duplicate detection)" fullWidth>
            <CanonicalizationPanel />
          </Labeled>
        )}
      </SimpleShowLayout>
    </Show>
  );
};
