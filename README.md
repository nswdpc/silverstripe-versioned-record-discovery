# Versioned record discovery for Silverstripe

This module provides an extension to automatically add versioned listings to configured managed models in a ModelAdmin subclass.

At present, it provides:
1. an unpublished modeladmin extension, to provide an unpublished view on configured records
1. A report showing unpublished records per model class, based on your selection

This helps to solve a common question from our content editors "What remains to be published?"

## Installation

Install using composer:
```
composer require nswdpc/silverstripe-versioned-record-discovery
```

If you are new to creating ModelAdmins to allow easy manipulation of data in the administration area of a Silverstripe website, [the ModelAdmin documentation](https://docs.silverstripe.org/en/4/developer_guides/customising_the_admin_interface/modeladmin/) is a great starting point.

## Configuration

### Report

The report will automatically show all versioned records in the system.

If you wish to set a default model for the reporting view, set this in configuration (using the `Page` class as an example):

```yml
---
Name: 'default-target-model'
---
NSWDPC\Utilities\VersionedRecordDiscovery\UnpublishedRecordReport:
    default_target_model: '\Page'
```


### Tab extension for the ModelAdmin

This is slightly more involved, but provides an unpublished record view  directly in relevant modeladmins, without needing to fiddle with filters.

#### Example

Here is an example of a ModelAdmin with three managed models in a Silverstripe website.

We want to add 'unpublished' record listings in the modeladmin for the first two versioned record types, so our content editors can easily find the unpublished records.

An unpublished record is one that exists on the draft stage but not on the live stage. It may have been previously published and unpublished.

Note that the configured record classes must have the Versioned extension for this to work.

### Add the unpublished_tabs configuration

```php
<?php

namespace Amazing;

use SilverStripe\Admin\ModelAdmin;

class RecordModelAdmin extends ModelAdmin
{

    private static $url_segment = 'records';

    // Managed models for this ModelAdmin
    private static $managed_models = [
        VersionedRecordTypeOne::class,// we want an 'unpublished' tab for this
        VersionedRecordTypeTwo::class,// and this
        OtherRecord::class,
    ];

    /**
     * Add the following to your model admin configuration
     * Gotcha: do not use - characters in the keys!
     */
    private static $unpublished_tabs = [
        'recordtypeoneslug' => VersionedRecordTypeOne::class,
        'recordtypetwoslug' => VersionedRecordTypeTwo::class
    ];

}
```

### Environment configuration

Apply the extension in YAML configuration

```yml
---
Name: myrecord-versioned-tabs
---
Amazing\RecordModelAdmin:
  extensions:
    - 'NSWDPC\VersionedTabs\UnpublishedTabAdminExtension'
```

### Build

Run a `dev/build` + `flush=1` and you will see two new tabs in the relevant administration screen.

When clicked, they will list unpublished records only. The default configured `$managed_models` remain untouched.

## License

[BSD-3-Clause](./LICENSE.md)

## Maintainers

+ [dpcdigital@NSWDPC:~$](https://dpc.nsw.gov.au)

> Add additional maintainers here and/or include [authors in composer](https://getcomposer.org/doc/04-schema.md#authors)

## Bugtracker

We welcome bug reports, pull requests and feature requests on the Github Issue tracker for this project.

Please review the [code of conduct](./code-of-conduct.md) prior to opening a new issue.

## Development and contribution

If you would like to make contributions to the module please ensure you raise a pull request and discuss with the module maintainers.

Please review the [code of conduct](./code-of-conduct.md) prior to completing a pull request.
