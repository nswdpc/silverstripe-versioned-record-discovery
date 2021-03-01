# Configuration and setup for developers

In this section:

1. Unpublished record report
1. Unpublished tabs in a model admin
1. Review and revert versioned records

### Editors

See [usage - for editors](./003_editor.md)

## Report of unpublished records

The report will automatically show all versioned classes in the system.

By default, SiteTree records are shown first. If you wish to set a different default model for the reporting view, set this in configuration (using the `Page` class as an example):

```yml
---
Name: 'default-target-model'
---
NSWDPC\Utilities\VersionedRecordDiscovery\UnpublishedRecordReport:
    default_target_model: 'Page'
```


## Unpublished tab extension for a ModelAdmin

This is slightly more involved, but provides an unpublished record view  directly in relevant modeladmins, without needing to fiddle with filters.

If you are new to creating ModelAdmins to allow easy manipulation of data in the administration area of a Silverstripe website, [the ModelAdmin documentation](https://docs.silverstripe.org/en/4/developer_guides/customising_the_admin_interface/modeladmin/) is a great starting point.

### Example

Here is an example of a ModelAdmin with three managed models in a Silverstripe website.

We want to add 'unpublished' record listings in the modeladmin for the first two versioned record types, so our content editors can easily find the unpublished records.

An unpublished record is one that exists on the draft stage but not on the live stage. It may have been previously published and unpublished, with those actions each contributing a version each to the history of that record.

Note that the configured record classes must have the `Versioned` (silverstripe/versioned) extension for this to work.

#### Add the unpublished_tabs configuration

```php
<?php

namespace Amazing;

use SilverStripe\Admin\ModelAdmin;

class RecordModelAdmin extends ModelAdmin
{

    private static $url_segment = 'records';

    private static $menu_title = "Amazing records";

    /**
     * Managed models for this ModelAdmin
     */
    private static $managed_models = [
        VersionedRecordTypeOne::class,// we want an 'unpublished' tab for this
        VersionedRecordTypeTwo::class,// and this
        OtherRecord::class,
    ];

    /**
     * Add the following to your model admin configuration
     * Gotcha: do not use "-" characters in the keys!
     */
    private static $unpublished_tabs = [
        'recordtypeoneslug' => VersionedRecordTypeOne::class,
        'recordtypetwoslug' => VersionedRecordTypeTwo::class
    ];

}
```

#### Environment configuration

Apply the extension via YAML configuration in the usual Silverstripe way:

```yml
---
Name: myrecord-versioned-tabs
---
Amazing\RecordModelAdmin:
  extensions:
    - 'NSWDPC\Utilities\VersionedRecordDiscovery\UnpublishedTabAdminExtension'
```

#### Build

Run a `dev/build` + `flush=1` and you will see two new tabs in the relevant administration screen.

When clicked, they will list unpublished records only. The default configured `$managed_models` remain untouched.

## Review and Revert

After the `Revertable` extension is added to a Versioned dataobject, a History tab will appear when editing its records.

```yml
Amazing\VersionedRecordTypeOne:
  extensions:
    - 'NSWDPC\Utilities\VersionedRecordDiscovery\Revertable'
```

Each entry in the gridfield represents a version (except deleted versions). When viewing each version an editor will have the option to revert the record to that version.

For a record to be revertable, the following requirements must be in place:

1. The requested version needs to be valid
1. The revert action needs to take place within a ModelAdmin controller
1. The record must have a method "CMSEditLink" returning the link to the latest draft version of the record in a ModelAdmin
1. The record cannot be in a workflow (via `symbiote/silverstripe-advancedworkflow`). Approve or reject the workflow for the record first.
1. You cannot revert to the latest version of the record

Internally the revert process is handled by `Versioned::rollbackRecursive()`

> Note: while you could possibly enable this functionality for SiteTree records, the revert handling in this module is focused on versioned dataobject support. The CMS module provides its own handling for page history review, comparison and revert and you should use that.
