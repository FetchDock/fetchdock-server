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
  useRecordContext,
  useRedirect,
} from "react-admin";
import { apiFetch } from "../../common/api";

export const DIRECTORY_RESOURCE = "admin/download_directories";

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

export const DownloadDirectoryEdit = () => (
  <Edit actions={false} title="Download directory">
    <DownloadDirectoryForm />
  </Edit>
);
