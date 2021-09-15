<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;

/**
 * Extension for ChangeSetItem
 * @author James
 */
class ChangeSetItemExtension extends DataExtension {

    /**
     * Provide indexes to speed up query time
     * @var
     */
    private static $indexes = [
        'VersionBefore' => true,
        'VersionAfter' => true
    ];

    /**
     * Get item a the current version
     * @return DataObject|null
     */
    public function getItemAtVersion() {
        $itemAtVersion = Versioned::get_version(
            $this->owner->ObjectClass,
            $this->owner->ObjectID,
            $this->owner->VersionAfter
        );
        return $itemAtVersion;
    }

    /**
     * Get the i18n name of this item
     * @return string|null
     */
    public function getRevertableItemName() {
        $inst = Injector::inst()->get( $this->owner->ObjectClass );
        if($inst) {
            return $inst->i18n_singular_name();
        } else {
            return null;
        }
    }

}
