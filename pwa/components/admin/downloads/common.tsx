import { ReactNode } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  Button,
  ListContextProvider,
  RaRecord,
  useList,
  useNotify,
  usePermissions,
  useRecordContext,
  useRefresh,
} from "react-admin";
import { apiFetch } from "../../common/api";
import { canManageAllDownloads } from "../../common/permissions";

export const JOB_RESOURCE = "admin/download_jobs";
export const FILE_RESOURCE = "admin/downloaded_files";

/** DownloadStateEnum on the API */
export const STATE_CHOICES = [
  { id: 0, name: "Pending" },
  { id: 1, name: "In progress" },
  { id: 2, name: "Completed" },
  { id: 3, name: "Failed" },
  { id: 4, name: "Canceled" },
  { id: 5, name: "Already exists" },
];

/** The admin endpoints' maximum page size: lists inside a show view aren't paginated */
const ALL_ITEMS = "itemsPerPage=1000";

/**
 * POST {record IRI}/restore for soft-deleted records, for users with ROLE_MANAGE_ALL_DOWNLOADS.
 * Hidden for live records; disabled when the record can't be restored (e.g. a file that is gone from disk).
 */
export const RestoreButton = ({ label = "Restore", disabledReason }: { label?: string; disabledReason?: string }) => {
  const record = useRecordContext();
  const { permissions } = usePermissions();
  const notify = useNotify();
  const refresh = useRefresh();
  const { mutate, isPending } = useMutation({
    mutationFn: () => apiFetch(`${record?.id}/restore`, { method: "POST" }),
    onSuccess: () => {
      notify("Restored", { type: "success" });
      refresh();
    },
    onError: (error: Error) => notify(error.message || "Restoring failed", { type: "error" }),
  });

  if (!record?.deletedAt || !canManageAllDownloads(permissions)) {
    return null;
  }

  return (
    <Button
      label={label}
      title={disabledReason}
      disabled={isPending || undefined !== disabledReason}
      onClick={(event) => {
        // Don't trigger the row click of a surrounding Datagrid
        event.stopPropagation();
        mutate();
      }}
    />
  );
};

/**
 * Renders a sub-collection of the current record (e.g. "{job IRI}/files") with react-admin list components.
 */
export const SubresourceList = ({ path, children }: { path: string; children: ReactNode }) => {
  const record = useRecordContext();
  const url = `${record?.id}/${path}?${ALL_ITEMS}`;
  const { data, isPending, error } = useQuery({
    queryKey: ["admin-subresource", url],
    queryFn: () => apiFetch<{ member?: RaRecord[] }>(url),
    enabled: undefined !== record?.id,
  });
  const listContext = useList({
    data: (data?.member ?? []).map((item: any) => ({ ...item, id: item["@id"] ?? item.id })),
    isPending,
    perPage: 1000,
  });

  if (error) {
    return <p>Couldn&apos;t load {path}: {(error as Error).message}</p>;
  }

  return <ListContextProvider value={listContext}>{children}</ListContextProvider>;
};
