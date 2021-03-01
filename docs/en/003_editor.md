# Editor usage

Once your developers have configured this module, you will have access to one or more of the following:

## Unpublished record report

In the Reports section, you will see an "Unpublished records" report. Click this entry to view the default report (unpublished pages).

You will be able to see:
1. The record ID
1. An optional image (if one is provided by the record)
1. The record title, linked to the administration area
1. The unpublished, created and last edited date for the record. If the 'Unpublised on' column is empty, this means the record was never published.
1. A URL to view the record on the draft site, if applicable

The records presented here are Draft records, and do not include those records that were archived. If your developer has installed the Archives module, use that section to find and manage archived records.

To view a draft record, click its Title. You will then  be able to take actions on that record.

To change the type of record being viewed, use the 'Select a Content Type' menu option.

In a Silverstripe install there can be many versioned records. To assist with finding records, the type of the record is sometimes added to the selection:

1. Editable form field - fields added to an editable form
1. Content elements - content elements added to a page or record

## History tab in relevant records

If your developer has enabled this for a record, an additional tab will be added to the administration area for the record. This tab will provide a listing of unpublished records for that record type. The original tab will display all records, including unpublished ones.

## Ability to revert a record to a previous version

If your developer has enabled this for a record, a History tab will be made available for a record, with the tab showing the current version of the record.

Each version in the record's history will be displayed, except for when a record is deleted (e.g unpublished). You can view a version for the record, review the change and optionally revert the record to that version.

When you revert a record to a version, a new version will be created. The change will not be published (the last published version will be the live version).
