<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\Fieldlist;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\View\ArrayData;

/**
 * Versioned items request to view the record at the relevant version
 */
class RevertableVersionedGridFieldItemRequest extends VersionedGridFieldItemRequest
{

    protected $version = null;

    private static $url_handlers = [
        '$Action!' => '$Action',
        '' => 'view',
    ];

    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'view' => true,
        'ItemEditForm' => true
    ];

    public function __construct($gridField, $component, $record, $requestHandler, $popupFormName)
    {
        try {
            // set the current record to be the record at the relevant version
            $record = $this->getVersionRecordFromRecord($record, $requestHandler);
            parent::__construct($gridField, $component, $record, $requestHandler, $popupFormName);
        } catch (\Exception $e) {
            return $requestHandler->httpError(
                404,
                _t(__CLASS__ . '.InvalidVersion', $e->getMessage())
            );
        }
    }

    /**
     * Return the name for the input field or query string, whose value contains a version  number
     */
    public static function getRevertRequestValueName() {
        return "rv";
    }

    /**
     * Return version based on the request
     */
    public static function getRequestedRevertVersion() {
        $version = null;
        if(Controller::has_curr()) {
            $request = Controller::curr()->getRequest();
            $version = $request->requestVar(self::getRevertRequestValueName());
        }
        return $version;
    }

    /**
     * Get the record at the requested version
     * @return DataObject
     */
    protected function getVersionRecordFromRecord(DataObject $record, $requestHandler) : DataObject {
        // validate version ID
        $this->version = self::getRequestedRevertVersion();
        if(!$this->version) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".VERSION_NOT_PROVIDED",
                    "No version provided"
                )
            );
        }

        // validate the record
        if(!$record->hasExtension(Versioned::class)) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".RECORD_NOT_VERSIONED",
                    "The record is not versioned"
                )
            );
        }

        if(!$record->canView()) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".NO_ACCESS",
                    "You do not have access to this record"
                )
            );
        }

        $versioned_record = $record->VersionsList()
                    ->filter('Version', $this->version)
                    ->first();
        if(empty($versioned_record->ID)) {
            throw new \Exception(
                _t(
                    __CLASS__ . ".VERSION_NOT_FOUND",
                    "No version #{$this->version} found for this record"
                )
            );
        }

        return $versioned_record;
    }

    /**
     * Display the version of the record at the specific time
     */
    public function view($request) {

        if (!$this->record->canView()) {
            $this->httpError(403);
        }
        $controller = $this->getToplevelController();
        $form = $this->ItemEditForm();
        $data = ArrayData::create([
            'Backlink' => $controller->Link(),
            'ItemEditForm' => $form
        ]);
        $return = $data->renderWith($this->getTemplates());
        if ($request->isAjax()) {
            return $return;
        }
        return $controller->customise(['Content' => $return]);
    }

    /**
     * Return the detail form for this version of the record
     * @return Form
     */
    public function ItemEditForm()
    {
        // the parent will call updateItemEditForm
        $form = parent::ItemEditForm();

        // make all fields readonly
        $fieldlist = $form->Fields();
        $this->transformDataFields($fieldlist);
        // set the transformed fields back on the form
        $form->setFields($fieldlist);

        $record = $this->getRecord();

        $created = ($record->Created ? DBField::create_field(DBDatetime::class, $record->Created) : null);

        if ($record->isLatestVersion()) {
            $message = _t(
                __CLASS__ . '.VIEWINGLATEST',
                "You are currently viewing the latest version, created {created}",
                [
                    'version' => $this->version,
                    'created' => $created ? $created->Nice() : '?'
                ]
            );
        } else {
            $message = _t(
                __CLASS__ . '.VIEWINGVERSION',
                "You are currently viewing version {version}, created {created}",
                [
                    'version' => $this->version,
                    'created' => $created ? $created->Nice() : '?'
                ]
            );
        }

        $form->sessionMessage(
            DBField::create_field('HTMLFragment', $message),
            ValidationResult::TYPE_WARNING
        );

        // remove any actions that may have been added by other extensions
        // in versioned mode, we only want the revert action
        $actions = $form->Actions();
        $version = RevertableVersionedGridFieldItemRequest::getRequestedRevertVersion();
        if($version) {
            $record->removeOtherActions($actions);
        }
        return $form;
    }

    /**
     * Transform all data fields into their reviewable state
     * @param Fieldlist $fieldlist
     */
    protected function transformDataFields(Fieldlist &$fieldlist) : array {
        $fields = $fieldlist->dataFields();
        foreach($fields as $k => $field) {
            if($field instanceof GridField) {
                // GridFields are set to a basic listing view via their config
                $field->setConfig( new GridFieldConfig_Base());
            }
            $fieldlist->replaceField(
                $field->getName(),
                $field->performReadonlyTransformation()
            );
        }
        return $fields;
    }

}
