<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/*
 * This extension automatically adds a "Record (unpublished)" in ModelAdmin subclasses
 * that are configured to use it. Check the README.md for setup instructions.
 *
 * To configure your model admin, add a private static $unpublished_tabs to it:

    private static $unpublished_tabs = [
        'myrecordunpublished' => MyRecord::class,
        'myotherrecordunpublished' => MyOtherRecord::class
    ];

 * Note that you cannot use "-" values for the keys as these are assumed to be
 * URL-safed namespace delimiters and will be converted by ModelAdmin to \
 *
 * @author James
 */
class UnpublishedTabAdminExtension extends Extension {

    /**
     * Add managed model specs for configured unpublished tabs
     */
    public function onBeforeInit() {
        $tabs = $this->owner->getUnpublishedTabs();
        if(empty($tabs)) {
            return;
        }

        // process versioned tabs
        $models = $this->owner->getManagedModels();
        $updated = [];
        foreach($models as $k => $spec) {
            $updated[$k] = $spec;// pish it onto the list
            // check if this modelClass has a configired unpublished listing tab
            if($slug = $this->owner->getUnpublishedTab($spec['dataClass'])) {

                // grab the name of the data class, for the tab title
                $model = Injector::inst()->get($spec['dataClass']);
                $name = $model->i18n_plural_name();

                // create a new managed_model record, after the configured one
                $updated[ $slug ] = [
                    'unpublished' => true,
                    'dataClass' => $spec['dataClass'],
                    'title' => "{$name} (" . _t(__CLASS__ . ".UNPUBLISHED", "unpublished") . ")"
                ];

            }
        }
        // replace managed_models config
        $this->owner->config()->set('managed_models', $updated);
    }

    /**
     * If the current model listing is for an "unpublihsed" listing,
     * modify the list returned
     *  @return null
     */
    public function updateList(&$list) {
        if($this->owner->isUnpublishedListing()) {
            // grab the model class
            $model_class = $this->owner->getModelClass();
            // get records from the draft stage
            $list = Versioned::get_by_stage($model_class, Versioned::DRAFT);
            // exclude records not on the _Live stage
            // see Versioned::augmentSQL()
            $list = $list->setDataQueryParam([
                'Versioned.mode' => 'stage_unique'
            ]);
        }

    }

    /**
     * Determine if the current model listing request references an
     * unpublished listing
     * There is no "getModelTab()" in ModelAdmin :(
     * have to replicate ModelAdmin::init() to get a model tab
     */
    public function isUnpublishedListing() : bool {
        $models = $this->owner->getManagedModels();
        $model_tab = $this->owner->getRequest()->param('ModelClass');
        if(!$model_tab) {
            // grab the first item
            reset($models);
            $model_tab = key($models);
        }
        return isset($models[ $model_tab ]['unpublished'])
                    ? $models[ $model_tab ]['unpublished']
                    : false;
    }

    /**
     * Get all unpublished tabs configured for this model admin
     * @return array
     */
    public function getUnpublishedTabs() : array {
        $unpublished_tabs = $this->owner->config()->get('unpublished_tabs');
        if(!is_array($unpublished_tabs)) {
            $unpublished_tabs = [];
        }
        return $unpublished_tabs;
    }

    /**
     * Return the key of the unpublished tab, which is the URL slug
     */
    public function getUnpublishedTab($model_class) : string {
        $tabs = $this->owner->getUnpublishedTabs();
        $key = array_search($model_class, $tabs);
        return $key;
    }

}
