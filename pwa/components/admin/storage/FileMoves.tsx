import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Button,
  Datagrid,
  DateField,
  FunctionField,
  Labeled,
  List,
  ReferenceField,
  SelectInput,
  Show,
  SimpleShowLayout,
  TextField,
  TextInput,
  useNotify,
  useCreatePath,
  useRecordContext,
  useRefresh,
} from "react-admin";
import { Link } from "react-router-dom";
import { apiFetch } from "../../common/api";
import { DIRECTORY_RESOURCE, FILE_MOVE_RESOURCE } from "./resources";

export { FILE_MOVE_RESOURCE } from "./resources";

/** GET /admin/file_moves/{id} */
export type FileMoveBatch = {
  "@id": string;
  id: number;
  scope: "site" | "directory";
  site: string | null;
  sourceDirectory: string | null;
  targetDirectory: string | null;
  status: "queued" | "running" | "completed" | "completed_with_errors" | "cancelled" | "failed";
  total: number;
  moved: number;
  skipped: number;
  failed: number;
  bytesTotal: number;
  bytesMoved: number;
  errors: { fileId: number; path: string; error: string }[];
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  requestedBy: string | null;
};

const STATUS_CHOICES = [
  { id: "queued", name: "Queued" },
  { id: "running", name: "Running" },
  { id: "completed", name: "Completed" },
  { id: "completed_with_errors", name: "Completed with errors" },
  { id: "cancelled", name: "Cancelled" },
  { id: "failed", name: "Failed" },
];

export const isActive = (batch: Pick<FileMoveBatch, "status">) => "queued" === batch.status || "running" === batch.status;

const formatBytes = (bytes: number) => {
  const units = ["B", "KB", "MB", "GB", "TB"];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value.toFixed(unit > 0 ? 1 : 0)} ${units[unit]}`;
};

/** "12 / 40 files (3 skipped, 1 failed), 1.2 GB / 8.0 GB" with a bar */
export const FileMoveProgress = ({ batch }: { batch: FileMoveBatch }) => {
  const done = batch.moved + batch.skipped + batch.failed;
  const percent = batch.total > 0 ? Math.round((done / batch.total) * 100) : 100;

  return (
    <div style={{ minWidth: "16rem" }}>
      <div style={{ height: "0.5rem", background: "rgba(127,127,127,0.25)", borderRadius: "0.25rem", overflow: "hidden" }}>
        <div style={{ width: `${percent}%`, height: "100%", background: batch.failed > 0 ? "#d32f2f" : "#1976d2" }} />
      </div>
      <small>
        {done} / {batch.total} files ({batch.moved} moved, {batch.skipped} skipped, {batch.failed} failed)
        {batch.bytesTotal > 0 && `, ${formatBytes(batch.bytesMoved)} / ${formatBytes(batch.bytesTotal)}`}
      </small>
    </div>
  );
};

/** POST {IRI}/cancel, for active batches */
const CancelButton = () => {
  const record = useRecordContext<FileMoveBatch>();
  const notify = useNotify();
  const refresh = useRefresh();
  const [busy, setBusy] = useState(false);
  if (!record || !isActive(record)) {
    return null;
  }

  const cancel = async () => {
    setBusy(true);
    try {
      await apiFetch(`${record["@id"]}/cancel`, { method: "POST", headers: { "Content-Type": "application/ld+json" }, body: "{}" });
      notify("File move cancelled: files being moved finish, the others stay where they are", { type: "success" });
      refresh();
    } catch (error) {
      notify((error as Error).message || "Cancelling failed", { type: "error" });
    } finally {
      setBusy(false);
    }
  };

  return <Button label="Cancel move" onClick={cancel} disabled={busy} />;
};

const DirectoryField = ({ source }: { source: string }) => (
  <ReferenceField source={source} reference={DIRECTORY_RESOURCE} link="edit" emptyText="-">
    <TextField source="name" />
  </ReferenceField>
);

export const FileMoveList = () => (
  <List
    title="File moves"
    exporter={false}
    sort={{ field: "id", order: "DESC" }}
    filters={[
      <SelectInput key="status" source="status" choices={STATUS_CHOICES} alwaysOn />,
      <TextInput key="site" source="site" label="Site (slug)" alwaysOn />,
    ]}
  >
    <Datagrid bulkActionButtons={false} rowClick="show">
      <TextField source="id" />
      <FunctionField label="Moving" render={(batch: FileMoveBatch) => ("site" === batch.scope ? `Site ${batch.site ?? "-"}` : "Directory")} />
      <DirectoryField source="sourceDirectory" />
      <DirectoryField source="targetDirectory" />
      <FunctionField label="Status" render={(batch: FileMoveBatch) => STATUS_CHOICES.find((choice) => choice.id === batch.status)?.name} />
      <FunctionField label="Progress" render={(batch: FileMoveBatch) => <FileMoveProgress batch={batch} />} />
      <DateField source="createdAt" showTime />
      <TextField source="requestedBy" />
    </Datagrid>
  </List>
);

/** Live through Mercure: the API publishes the batch on its IRI, at most once per second */
export const FileMoveShow = () => (
  <Show title="File move" actions={<CancelButton />}>
    <SimpleShowLayout>
      <TextField source="id" />
      <TextField source="scope" />
      <TextField source="site" emptyText="-" />
      <DirectoryField source="sourceDirectory" />
      <DirectoryField source="targetDirectory" />
      <FunctionField label="Status" render={(batch: FileMoveBatch) => STATUS_CHOICES.find((choice) => choice.id === batch.status)?.name} />
      <FunctionField label="Progress" render={(batch: FileMoveBatch) => <FileMoveProgress batch={batch} />} />
      <DateField source="createdAt" showTime />
      <DateField source="startedAt" showTime emptyText="-" />
      <DateField source="finishedAt" showTime emptyText="-" />
      <TextField source="requestedBy" emptyText="-" />
      <FunctionField
        label="Errors (first 50)"
        render={(batch: FileMoveBatch) =>
          0 === batch.errors.length ? (
            "None"
          ) : (
            <ul style={{ margin: 0, paddingLeft: "1.2rem" }}>
              {batch.errors.map((error, index) => (
                <li key={index}>
                  <code>{error.path || "batch"}</code>: {error.error}
                </li>
              ))}
            </ul>
          )
        }
      />
    </SimpleShowLayout>
  </Show>
);

/**
 * A batch's progress, polled while it is active (for panels outside the file move views).
 */
export const FileMoveStatus = ({ iri, onFinished }: { iri: string; onFinished?: () => void }) => {
  const createPath = useCreatePath();
  const { data } = useQuery({
    queryKey: ["admin-file-move", iri],
    queryFn: async () => {
      const batch = await apiFetch<FileMoveBatch>(iri);
      if (batch && !isActive(batch)) {
        onFinished?.();
      }
      return batch;
    },
    refetchInterval: (query) => (query.state.data && !isActive(query.state.data) ? false : 2000),
  });
  if (!data) {
    return null;
  }

  return (
    <Labeled label={`File move ${data.id}: ${STATUS_CHOICES.find((choice) => choice.id === data.status)?.name}`}>
      <div>
        <FileMoveProgress batch={data} />
        <Link to={createPath({ resource: FILE_MOVE_RESOURCE, id: data["@id"], type: "show" })}>Details</Link>
      </div>
    </Labeled>
  );
};
