import {
  ArrayField,
  BooleanField,
  Datagrid,
  DateField,
  FunctionField,
  List,
  NullableBooleanInput,
  NumberField,
  SelectField,
  Show,
  SimpleShowLayout,
  TextField,
  TopToolbar,
  useCreatePath,
  useRecordContext,
} from "react-admin";
import { Link } from "react-router-dom";
import { JOB_RESOURCE, RestoreButton, STATE_CHOICES } from "./common";

const fileFilters = [
  <NullableBooleanInput key="deleted" source="deleted" label="Deleted" alwaysOn />,
  <NullableBooleanInput key="orphaned" source="orphaned" label="Not used by a live job" alwaysOn />,
];

/** Restore for soft-deleted files; disabled while the file is missing from disk (the API answers 409) */
export const FileActions = () => {
  const record = useRecordContext();

  return <RestoreButton disabledReason={record?.existsOnDisk ? undefined : "The file no longer exists on disk"} />;
};

const DownloadLink = () => {
  const record = useRecordContext();

  return record?.downloadUri ? (
    <a href={record.downloadUri} onClick={(event) => event.stopPropagation()}>
      Download
    </a>
  ) : (
    <span title="Every job linked to the file is deleted">-</span>
  );
};

/** Links a linked job (AdminJobReferenceDTO) to its admin show view */
const JobLink = () => {
  const job = useRecordContext();
  const createPath = useCreatePath();

  return job?.uuid ? (
    <Link to={createPath({ resource: JOB_RESOURCE, id: `/${JOB_RESOURCE}/${job.uuid}`, type: "show" })}>{job.uuid}</Link>
  ) : null;
};

/** GET /admin/downloaded_files: all files, including soft-deleted and hidden ones */
export const AdminDownloadedFileList = () => (
  <List filters={fileFilters} sort={{ field: "id", order: "DESC" }} perPage={30} title="All downloaded files">
    <Datagrid rowClick="show" bulkActionButtons={false}>
      <NumberField source="originId" label="ID" sortable={false} />
      <TextField source="filename" sortable={false} />
      <TextField source="path" sortable={false} />
      <BooleanField source="existsOnDisk" label="On disk" sortable={false} />
      <BooleanField source="visible" sortable={false} />
      <DateField source="deletedAt" showTime sortable={false} emptyText="-" />
      <FunctionField label="Jobs" render={(file: any) => file.jobs?.length ?? 0} />
      <FileActions />
    </Datagrid>
  </List>
);

const FileShowActions = () => (
  <TopToolbar>
    <FileActions />
  </TopToolbar>
);

/** GET /admin/downloaded_files/{id}, with all linked jobs */
export const AdminDownloadedFileShow = () => (
  <Show actions={<FileShowActions />}>
    <SimpleShowLayout>
      <NumberField source="originId" label="ID" />
      <TextField source="filename" />
      <TextField source="path" />
      <BooleanField source="existsOnDisk" label="On disk" />
      <BooleanField source="visible" />
      <DateField source="deletedAt" showTime emptyText="-" />
      <FunctionField label="Metadata" render={(file: any) => <code>{JSON.stringify(file.metadata ?? {})}</code>} />
      <FunctionField label="Download" render={() => <DownloadLink />} />
      <ArrayField source="jobs">
        <Datagrid bulkActionButtons={false} rowClick={false}>
          <FunctionField label="Job" render={() => <JobLink />} />
          <TextField source="owner.subject" label="Owner" emptyText="-" />
          <SelectField source="state" choices={STATE_CHOICES} />
          <DateField source="deletedAt" showTime emptyText="-" />
        </Datagrid>
      </ArrayField>
    </SimpleShowLayout>
  </Show>
);
