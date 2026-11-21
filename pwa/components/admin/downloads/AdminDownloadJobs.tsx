import {
  BooleanField,
  Datagrid,
  DateField,
  DeleteButton,
  FunctionField,
  List,
  NullableBooleanInput,
  NumberField,
  SelectField,
  SelectInput,
  Show,
  Tab,
  TabbedShowLayout,
  TextField,
  TextInput,
  TopToolbar,
  usePermissions,
  useRecordContext,
} from "react-admin";
import { canManageAllDownloads } from "../../common/permissions";
import { RestoreButton, STATE_CHOICES, SubresourceList } from "./common";
import { FileActions } from "./AdminDownloadedFiles";

const jobFilters = [
  <TextInput key="uri" source="uri" label="URI contains" alwaysOn />,
  <SelectInput key="state" source="state" choices={STATE_CHOICES} />,
  <NullableBooleanInput key="deleted" source="deleted" label="Deleted" />,
  <TextInput key="owner" source="owner" label="Owner (user UUID)" />,
];

/** GET /admin/download_jobs: all users' jobs, including soft-deleted ones */
export const AdminDownloadJobList = () => (
  <List filters={jobFilters} sort={{ field: "createdAt", order: "DESC" }} perPage={30} title="All download jobs">
    <Datagrid rowClick="show" bulkActionButtons={false}>
      <TextField source="uri" sortable={false} />
      <SelectField source="state" choices={STATE_CHOICES} sortable={false} />
      <TextField source="downloader" sortable={false} />
      <TextField source="owner.subject" label="Owner" sortable={false} emptyText="-" />
      <DateField source="createdAt" showTime sortable={false} />
      <DateField source="deletedAt" showTime sortable={false} emptyText="-" />
      <NumberField source="fileCount" label="Files" sortable={false} />
    </Datagrid>
  </List>
);

/**
 * Restore and permanent delete, both only for soft-deleted jobs (the API answers 409 for live jobs).
 */
const JobActions = () => {
  const record = useRecordContext();
  const { permissions } = usePermissions();

  if (!record?.deletedAt || !canManageAllDownloads(permissions)) {
    return <TopToolbar />;
  }

  return (
    <TopToolbar>
      <RestoreButton label="Restore job" />
      <DeleteButton
        label="Delete permanently"
        mutationMode="pessimistic"
        confirmTitle="Permanently delete this job?"
        confirmContent="The job and its events are deleted for good. Its files are kept (soft-deleted if no other live job uses them)."
        redirect="list"
      />
    </TopToolbar>
  );
};

/** GET /admin/download_jobs/{uuid}, with its files and events */
export const AdminDownloadJobShow = () => (
  <Show actions={<JobActions />}>
    <TabbedShowLayout>
      <Tab label="Job">
        <TextField source="uuid" />
        <TextField source="uri" />
        <SelectField source="state" choices={STATE_CHOICES} />
        <TextField source="downloader" emptyText="-" />
        <TextField source="owner.subject" label="Owner" emptyText="-" />
        <TextField source="owner.uuid" label="Owner UUID" emptyText="-" />
        <DateField source="createdAt" showTime />
        <DateField source="updatedAt" showTime />
        <DateField source="deletedAt" showTime emptyText="-" />
        <NumberField source="fileCount" label="Files" />
      </Tab>
      <Tab label="Files" path="files">
        <SubresourceList path="files">
          <Datagrid bulkActionButtons={false} rowClick={false}>
            <TextField source="filename" />
            <TextField source="path" />
            <BooleanField source="existsOnDisk" label="On disk" />
            <BooleanField source="visible" />
            <DateField source="deletedAt" showTime emptyText="-" />
            <FileActions />
          </Datagrid>
        </SubresourceList>
      </Tab>
      <Tab label="Events" path="events">
        <SubresourceList path="events">
          <Datagrid bulkActionButtons={false} rowClick={false}>
            <DateField source="createdAt" showTime />
            <TextField source="event" />
            <TextField source="source" />
            <TextField source="updateMessage" emptyText="-" />
            <TextField source="exceptionMessage" emptyText="-" />
            <FunctionField
              label="Context"
              render={(event: any) => (event.context ? <code>{JSON.stringify(event.context)}</code> : "-")}
            />
          </Datagrid>
        </SubresourceList>
      </Tab>
    </TabbedShowLayout>
  </Show>
);
