<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Reports\Report;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\Versioned\Versioned;

/**
 * A report to display unpubished versioned records in the system
 * @author James
 */
class UnpublishedRecordReport extends Report
{

    /**
     * The class name of a default target model, this is used to get a count
     * of the most commonly used record in your system by default (probably \Page::class)
     * @var string
     */
    private static $default_target_model = "";

    /**
     * @var array
     */
    private static $restrict_to_models = [];

    public function title() {
        return _t(__CLASS__ . ".REPORT_TITLE", "Unpublished records");
    }

    public function description() {
        return _t(__CLASS__ . ".REPORT_DESCRIPTION", "View  and filter unpublished records");
    }

    public function group()
    {
        return _t(__CLASS__.'.GROUP_TITLE', "Versioned record reports");
    }

    public function sort()
    {
        return 1000;
    }

    /**
     * @inheritdoc
     */
    public function getCount($params = array(), $limit = null)
    {
        $default_target_model = $this->config()->get('default_target_model');
        if(empty($params) && !$default_target_model) {
            // no params, no initial filter
            return _t(__CLASS__ . '.LOTS', ">= 0");
        } else {

            try {
                // get the record type, if set
                $plural = "";

                // ensure the default if no target set
                if($default_target_model && empty($params['Target'])) {
                    $params['Target'] = $default_target_model;
                }

                // if a target is set...
                if(!empty($params['Target'])) {
                    $inst = Injector::inst()->get($params['Target']);
                    $plural = $inst->i18n_plural_name();
                }

            } catch(\Exception $e) {
                //noop
            }

            $sourceRecords = $this->sourceRecords($params, null, null);
            return trim($sourceRecords->count() . " " . $plural);
        }
    }

    /**
     * Return source records available in this report
     */
    public function sourceRecords($params = null)
    {

        $default_target_model = $this->config()->get('default_target_model');
        if($default_target_model && empty($params['Target'])) {
            $params['Target'] = $default_target_model;
        }

        if(empty($params['Target'])) {
            return ArrayList::create();
        }

        try {
            $model= $this->getVersionedModel($params['Target']);
            // get records from the draft stage
            $list = Versioned::get_by_stage( get_class($model), Versioned::DRAFT);
            // exclude records not on the _Live stage
            // see Versioned::augmentSQL()
            $list = $list->setDataQueryParam([
                'Versioned.mode' => 'stage_unique'
            ]);
            if($model->hasField('Title')) {
                $list = $list->sort(['Title' => 'ASC']);
            } else {
                $list = $list->sort(['LastEdited' => 'DESC']);
            }
            return $list;
        } catch (\Exception $e) {
            return ArrayList::create();
        }
    }

    /**
     * Return columns available in this report
     */
    public function columns()
    {
        return [
            "RecordPK" => [
                "title" =>  _t(__CLASS__ . ".ID", "ID"),
                'formatting' => function ($value, DataObject $item) {
                    return '#' . $item->ID;
                }
            ],
            "ImageThumbnail" => [
                "title" =>  _t(__CLASS__ . ".IMAGE", "Image"),
                'formatting' => function ($value, DataObject $item) {
                    if($item instanceof Image) {
                        return $image->CMSThumbnail();
                    } else if($item->hasMethod('getReportThumbnail')) {
                        return $image->getReportThumbnail();
                    }
                }
            ],
            "Title" => [
                "title" =>  _t(__CLASS__ . ".TITLE", "Title"),
                'formatting' => function ($value, DataObject $item) {
                    $value = $item->Title;
                    if (!empty($value)) {
                        if($item->hasMethod('CMSEditLink')) {
                            if ($link = $item->CMSEditLink()) {
                                return $this->getEditLink($value, $link);
                            }
                        }
                        return $value;
                    }
                    return _t(__CLASS__ . '.NOTITLE', 'No title');
                }
            ],
            "UnpublishedDate" => [
                "title" =>  _t(__CLASS__ . ".UNPUBLISHED_DATE", "Unpublished on"),
                "formatting" => function ($value, DataObject $item) {
                    $last_published = $item->Versions()
                                ->sort('LastEdited DESC')
                                ->filter([
                                    'WasPublished' => 1,
                                    'WasDeleted' => 1,// was deleted from the live stage
                                ])
                                ->limit(1)
                                ->first();
                    if(!empty($last_published->ID)) {
                        return $last_published->LastEdited;
                    } else {
                        return "";
                    }
                }
            ],
            "Created" => [
                "title" => _t(__CLASS__ . ".CREATED", "Created")
            ],
            "LastEdited" => [
                "title" => _t(__CLASS__ . ".EDITED", "Edited")
            ],
            "AbsoluteLink" =>  [
                "title" => "URL",
                'formatting' => function ($value, DataObject $item) {
                    $value = $item->Title;
                    if (!empty($value) && $item->hasMethod('AbsoluteLink')) {
                        if ($link = $item->AbsoluteLink("?stage=Stage")) {
                            return $this->getPublicLink($value, $link);
                        }
                        return $value;
                    }
                    return _t(__CLASS__ . '.NOINK', 'No link');
                }
            ],
        ];
    }

    /**
     * Return link to record
     *
     * @param string $value
     * @param string $link
     * @return string
     */
    protected function getEditLink($value, $link)
    {
        return sprintf(
            '<a class="grid-field__link" href="%s" title="%s">%s</a>',
            $link,
            $value,
            $value
        );
    }

    /**
     * Return link to record
     *
     * @param string $value
     * @param string $link
     * @return string
     */
    protected function getPublicLink($value, $link)
    {
        return sprintf(
            '<a class="grid-field__link" href="%s" title="%s">%s</a>',
            $link,
            $value,
            $value
        );
    }

    /**
     * Returns all versioned models
     */
    protected function getVersionedModels() : array {
        // Get dataobjects with staged versioning
        $list = array_filter(
            ClassInfo::subclassesFor(DataObject::class),
            function ($class) {
                $result = DataObject::has_extension($class, Versioned::class) &&
                    DataObject::singleton($class)->hasStages();

                if(!$result) {
                    return false;
                }

                $restrictedModels  = $this->config()->get('restrict_to_models');
                if(is_array($restrictedModels) && !empty($restrictedModels)) {
                    // check if restricted and allowed
                    $result = in_array($class, $restrictedModels);
                } else {
                    // no restriction
                    $result = true;
                }
                return $result;
            }
        );

        $items = [];
        foreach($list as $class) {
            try {
                $inst = Injector::inst()->get($class);
                if(!$inst->canView()) {
                    // drop those models where there is no permission to view
                    continue;
                }
                $title = $inst->i18n_singular_name();
                $description = "";
                $element = false;
                $editable_form_field = false;
                if($inst->hasMethod('i18n_classDescription')) {
                    $description = $inst->i18n_classDescription();
                } else if($inst->hasMethod('classDescription')) {
                    $description = $inst->classDescription();
                } else if( class_exists(BaseElement::class) && $inst instanceof BaseElement ) {
                    $description = $inst->getDescription();
                    $element = true;
                }

                if(is_scalar($description) && $description != "") {
                    $description = "- " . trim($description);
                } else {
                    $description = "";
                }

                if($element) {
                    // highlight that this model is a content element from Elemental
                    $description .= _t(__CLASS__ . ".CONTENT_ELEMENT", " (content element)");
                } else if( class_exists(EditableFormField::class) && $inst instanceof EditableFormField ) {
                    $description .= _t(__CLASS__ . ".EDITABLE_FORM_FIELD", " (editable form field)");
                }

                $items[ $class ] = strip_tags(trim($title . " " . $description));
            } catch (\Exception $e) {
                //noop
            }
        }
        asort($items, SORT_NATURAL | SORT_FLAG_CASE);
        return $items;
    }

    public function getVersionedModel($class) : DataObject {
        $models = $this->getVersionedModels();
        if(array_key_exists($class, $models)) {
            return Injector::inst()->get($class);
        }
        throw new \Exception("The record is not versioned or you cannot access it");
    }

    /**
     * Provides the fields used to gather input to filter the report
     *
     * @return FieldList
     */
    public function parameterFields(): FieldList
    {

        $params = $this->getSourceParams();
        $value = "";
        $default_target_model = $this->config()->get('default_target_model');
        if($default_target_model && empty($params['Target'])) {
            // set initial value as the default target if set
            $value = $default_target_model;
        } else if(!empty($params['Target'])) {
            // use the selected target
            $value = $params['Target'];
        }

        $values = $this->getVersionedModels();
        $fields = FieldList::create([
            DropdownField::create(
                'Target',
                'Select a Content Type',
                $values,
                $value
            )->setEmptyString('')
        ]);

        $this->extend('updateParameterFields', $fields);

        return $fields;
    }

}
