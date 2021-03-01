<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use SilverStripe\Admin\ModelAdmin;

class ReviewAndRevertAdmin extends ModelAdmin {
    private static $url_segment = "review-and-revert";

    private static $menu_title = "Review & Revert";

    private static $managed_models = [];

    /**
     * TODO get all models that have the Revertable extension
     */
    public function getManagedModels() {
        return [];
    }
}
