import { useState } from "react";
import {
  BooleanField,
  BooleanInput,
  Button,
  Create,
  Datagrid,
  Edit,
  Form,
  List,
  NumberField,
  SaveButton,
  SimpleForm,
  TextField,
  TextInput,
  Toolbar,
  required,
  useNotify,
  SelectInput,
  useGetList,
  useRecordContext,
  useRedirect,
} from "react-admin";
import { apiFetch } from "../../common/api";
import { DIRECTORY_RESOURCE, FILE_MOVE_RESOURCE } from "./resources";

export { DIRECTORY_RESOURCE } from "./resources";

/** GET /admin/download_directories/{id} */
export type DownloadDirectory = {
  "@id": string;
  id: number;
  name: string;
  path: string;
  isDefault: boolean;
  verifyChecksum: boolean;
  fileCount: number;
  siteCount: number;
  available: boolean;
};

const PATH_HELP = "Absolute path inside DOWNLOAD_ROOTS. The api and the worker need the same mount.";
const CHECKSUM_HELP = "Verify files moved here from another filesystem with SHA-256, in addition to their size (slower).";

export const DownloadDirectoryList = () => (
  <List pagination={false} exporter={false} title="Download directories">
    <Datagrid bulkActionButtons={false} rowClick="edit">
      <TextField source="name" />
      <TextField source="path" />
      <BooleanField source="isDefault" label="Default" />
      <BooleanField source="available" label="Mounted and writable" />
      <BooleanField source="verifyChecksum" label="SHA-256 checks" />
      <NumberField source="fileCount" label="Files" />
      <NumberField source="siteCount" label="Sites" />
    </Datagrid>
  </List>
);

export const DownloadDirectoryCreate = () => (
  <Create redirect="list" title="New download directory">
    <SimpleForm>
      <TextInput source="name" validate={required()} fullWidth />
      <TextInput source="path" validate={required()} helperText={PATH_HELP} fullWidth />
      <BooleanInput source="verifyChecksum" label="Verify copies with SHA-256" helperText={CHECKSUM_HELP} />
      <BooleanInput source="isDefault" label="Default directory" helperText="Sites without their own directory store new downloads here." />
    </SimpleForm>
  </Create>
);

/** PATCH and DELETE {IRI} through apiFetch, which shows the API's 409 and 422 messages */
const DownloadDirectoryForm = () => {
  const record = useRecordContext<DownloadDirectory>();
  const notify = useNotify();
  const redirect = useRedirect();
  const [busy, setBusy] = useState(false);

  if (!record) {
    return null;
  }
  const iri = String(record["@id"] ?? record.id);

  const run = async (request: () => Promise<unknown>, message: string) => {
    setBusy(true);
    try {
      await request();
      notify(message, { type: "success" });
      redirect("list", DIRECTORY_RESOURCE);
    } catch (error) {
      notify((error as Error).message || "The request failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  const save = (values: Record<string, unknown>) =>
    run(
      () =>
        apiFetch(iri, {
          method: "PATCH",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({
            name: values.name,
            path: values.path,
            verifyChecksum: Boolean(values.verifyChecksum),
            isDefault: Boolean(values.isDefault),
            relocate: Boolean(values.relocate),
          }),
        }),
      "Directory saved",
    );
  const remove = () => run(() => apiFetch(iri, { method: "DELETE" }), "Directory deleted");
  const inUse = record.isDefault || record.fileCount > 0 || record.siteCount > 0;

  return (
    <Form defaultValues={{ ...record, relocate: false }} onSubmit={save}>
      <TextInput source="name" validate={required()} fullWidth />
      <TextInput
        source="path"
        validate={required()}
        helperText={
          record.fileCount > 0
            ? `${PATH_HELP} It holds ${record.fileCount} files: change the path only after moving the whole tree there, with the option below.`
            : PATH_HELP
        }
        fullWidth
      />
      {record.fileCount > 0 && (
        <BooleanInput
          source="relocate"
          label="The files were already moved to the new path (relocate)"
          helperText="Only the directory's record changes; the files' paths inside it stay the same."
        />
      )}
      <BooleanInput source="verifyChecksum" label="Verify copies with SHA-256" helperText={CHECKSUM_HELP} />
      <BooleanInput
        source="isDefault"
        label="Default directory"
        helperText={record.isDefault ? "Make another directory the default one to change this." : "Sites without their own directory store new downloads here."}
        disabled={record.isDefault}
      />
      <Toolbar>
        <SaveButton alwaysEnable disabled={busy} />
        <Button
          label="Delete"
          onClick={remove}
          disabled={busy || inUse}
          title={inUse ? "The default directory, and directories used by files or sites, can't be deleted" : undefined}
          sx={{ ml: 2 }}
        />
      </Toolbar>
    </Form>
  );
};

/**
 * POST {IRI}/file-moves: moves every file of the directory to another one, as a tracked batch, and by default
 * switches the sites that use this directory to the target.
 */
const MoveFilesPanel = () => {
  const record = useRecordContext<DownloadDirectory>();
  const notify = useNotify();
  const redirect = useRedirect();
  const { data: directories } = useGetList<DownloadDirectory>(DIRECTORY_RESOURCE, { pagination: { page: 1, perPage: 1000 } });
  const [busy, setBusy] = useState(false);
  if (!record || 0 === record.fileCount) {
    return null;
  }
  const iri = String(record["@id"] ?? record.id);

  const move = async (values: Record<string, unknown>) => {
    setBusy(true);
    try {
      const batch = await apiFetch<{ "@id": string }>(`${iri}/file-moves`, {
        method: "POST",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ targetDirectory: values.targetDirectory, reassignSites: Boolean(values.reassignSites) }),
      });
      notify("Moving the files", { type: "success" });
      if (batch) {
        redirect("show", FILE_MOVE_RESOURCE, batch["@id"]);
      }
    } catch (error) {
      notify((error as Error).message || "The move could not start", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Form defaultValues={{ targetDirectory: "", reassignSites: true }} onSubmit={move}>
      <h3>Move all {record.fileCount} files to another directory</h3>
      <SelectInput
        source="targetDirectory"
        label="Target directory"
        validate={required()}
        choices={(directories ?? [])
          .filter((directory) => directory.id !== record.id)
          .map((directory) => ({ id: String(directory.id), name: `${directory.name} (${directory.path})` }))}
        fullWidth
      />
      <BooleanInput
        source="reassignSites"
        label="Also switch the sites that use this directory to the target"
        helperText="New downloads of those sites then go to the target straight away."
      />
      <Toolbar>
        <SaveButton label="Move files" icon={<></>} disabled={busy} alwaysEnable />
      </Toolbar>
    </Form>
  );
};

export const DownloadDirectoryEdit = () => (
  <Edit actions={false} title="Download directory">
    <DownloadDirectoryForm />
    <MoveFilesPanel />
  </Edit>
);
