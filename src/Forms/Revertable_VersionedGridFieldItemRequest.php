<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;

/**
 * Versioned items request to view the record at the relevant version
 */
class Revertable_VersionedGridFieldItemRequest extends VersionedGridFieldItemRequest
{

    /**
     * The version being requested
     * @var null|string
     */
    protected $version = null;

    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'view',
        'ItemEditForm' => true
    ];

    public function __construct($gridField, $component, $record, $requestHandler, $popupFormName)
    {
        try {
            // set the current record to be the record at the relevant requetsed version
            $record = $this->getVersionRecordFromRecord($record, $requestHandler);
            // set current record  at version as the viewable record
            parent::__construct($gridField, $component, $record, $requestHandler, $popupFormName);
        } catch (\Exception $e) {
            return $requestHandler->httpError(
                404,
                _t('ReviewAndRevert.InvalidVersion', $e->getMessage())
            );
        }
    }

    /**
     * Breadcrumbs - update with version value
     */
    public function Breadcrumbs($unlinked = false)
    {
        $items = parent::Breadcrumbs($unlinked);
        $this->version = self::getRequestedRevertVersion();
        if($this->version) {
            $lastItem = $items->last();
            $lastItem->setField('Title', _t('ReviewAndRevert.VERSION', 'v{version}', ['version' => $this->version ]));
        }
        return $items;
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
                    "ReviewAndRevert.VERSION_NOT_PROVIDED",
                    "No version provided"
                )
            );
        }

        // validate the record
        if(!$record->hasExtension(Versioned::class)) {
            throw new \Exception(
                _t(
                    "ReviewAndRevert.RECORD_NOT_VERSIONED",
                    "The record is not versioned"
                )
            );
        }

        if(!$record->canView()) {
            throw new \Exception(
                _t(
                    "ReviewAndRevert.NO_ACCESS",
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
                    "ReviewAndRevert.VERSION_NOT_FOUND",
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
     * The form includes: a summary of changed fields, items from the changeset for this version
     * @return Form
     */
    public function ItemEditForm()
    {
        // the parent will call updateItemEditForm
        $form = parent::ItemEditForm();

        // record being viewed at the requested version
        $record = $this->getRecord();

        // Check for request in a versioned view
        $this->version = self::getRequestedRevertVersion();
        if($this->version) {

            $form->setFields( FieldList::create() );

            $created = ($record->Created ? DBField::create_field(DBDatetime::class, $record->Created) : null);
            $message = _t(
                'ReviewAndRevert.VIEWING_VERSION',
                "You are currently viewing version {version}, created {created}",
                [
                    'version' => $this->version,
                    'created' => $created ? $created->Nice() : '?'
                ]
            );

            $form->sessionMessage(
                DBField::create_field('HTMLFragment', $message),
                ValidationResult::TYPE_WARNING
            );

            // When in versioned view, remove other actions
            $actions = $form->Actions();
            $record->removeOtherActions($actions);

            $changeSetFieldList = $record->getChangedItems();
            foreach($changeSetFieldList as $field) {
                $form->Fields()->push( $field );
            }

            // Target revert version
            $form->Fields()->unshift(
                HiddenField::create(
                    self::getRevertRequestValueName(),
                    'Version',
                    $this->version
                )
            );

        } else {
            $form->setFields( FieldList::create() );
            $message = _t(
                'ReviewAndRevert.NO_VERSION',
                "No version was provided"
            );
            $form->sessionMessage(
                DBField::create_field('HTMLFragment', $message),
                ValidationResult::TYPE_WARNING
            );
        }

        return $form;
    }

}
