import { ListGuesser, FieldGuesser } from "@api-platform/admin";
import {
  ChipField,
  ReferenceArrayField,
  ReferenceField,
  ReferenceManyField,
  SingleFieldList,
  TextField
} from "react-admin";

export const DownloadJobList = () => (
  <ListGuesser>
    <TextField source="uuid" />
    <TextField source="token" />
    <TextField source="uri" />
    <FieldGuesser source="state" />
    <TextField source="downloader" />


    <ReferenceManyField reference="downloadJob" target="download_job_id">
      <SingleFieldList>
        <ChipField source="updateMessage" />
      </SingleFieldList>
    </ReferenceManyField>
    <ReferenceManyField reference="DownloadedFile" source="downloaded_files" target="download_job_id">
      <TextField source="filename" />
    </ReferenceManyField>
  </ListGuesser>
);
