<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use LeKoala\CmsActions\GridFieldRowButton;
use LeKoala\CmsActions\SilverStripeIcons;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;

/**
 * Review for revert button action for a grid field row
 * Drops the viewer at an edit screen they can use to review prior to reverting
 * The current version is checked based on the ?v= arg
 * @author James
 */
class ReviewForRevertButton extends GridFieldRowButton {

    protected $fontIcon = SilverStripeIcons::ICON_EYE;

    protected $hiddenOnHover = false;

    public function getActionName()
    {
        return strtolower('reviewForRevert');
    }

    public function getButtonLabel()
    {
        return _t(__CLASS__ . ".REVIEW", "Review");
    }

    /**
     * Return the column content for the action
     * If the record is the latest version, it will not display any actions
     * @return string
     */
    public function getColumnContent($gridField, $record, $columnName) {
        if($record->isLatestVersion()) {
            return '';
        }

        if($is_workflowed = $record->isRevertableRecordWorkflowed()) {
            return '';
        }

        $actionName = $this->getActionName();
        $field = GridField_FormAction::create(
            $gridField, // gridfield
            $actionName . '_' . $record->ID, // name
            '',
            $actionName,// action name
            [
                'RecordID' => $record->ID,
                'ParentID' => $this->parentID,
                'Version' => $record->Version
            ]
        )->addExtraClass(
            'gridfield-button-' . $actionName . ' no-ajax'
        )->setAttribute(
            'title',
            $this->getButtonLabel()
        );

        if ($this->hiddenOnHover) {
            $field->addExtraClass('grid-field__icon-action--hidden-on-hover');
        }

        if ($this->fontIcon) {
            $field->addExtraClass('grid-field__icon-action btn--icon-large font-icon-' . $this->fontIcon);
        } else {
            // TODO: add some way to do something nice
        }

        return $field->Field();
    }

    /**
     * Handle the action (redirects to the review screen if the request is validated)
     * This does not do the actual revert, rather  redirects the viewer to the version of the record requested
     * @return HttpResponse
     */
    public function doHandle(GridField $gridField, $actionName, $arguments, $data)
    {

        try {

            $link = false;
            $controller = Controller::curr();

            if(empty($arguments['Version'])) {
                throw new \Exception(_t(__CLASS__ . ".NO_VERSION_SUPPLIED", "No version provided"));
            }
            if(empty($arguments['RecordID'])) {
                throw new \Exception(_t(__CLASS__ . ".NO_RECORD_SUPPLIED", "No record provided"));
            }

            $record = $gridField->getList()
                    ->filter('ID', $arguments['RecordID'])
                    ->first();
            if(!$record || !($record instanceof DataObject)) {
                throw new \Exception(_t(__CLASS__ . ".NO_RECORD_FOUND", "No record found"));
            }

            if(!$record->hasMethod('getVersionedRevertLink')) {
                throw new \Exception(_t(__CLASS__ . ".NO_REVERT_LINK", "Record should provide the method getVersionedRevertLink"));
            }

            $link = $record->getVersionedRevertLink($arguments['Version']);

            if($is_workflowed = $record->isRevertableRecordWorkflowed()) {
                throw new \Exception(_t(__CLASS__ . ".RECORD_IN_WORKFLOW", "This record is currently is a workflow and cannot be reverted"));
            }

            if(!$record->canView()) {
                throw new \Exception(_t(__CLASS__ . ".NO_ACCESS_TO_RECORD", "You do not have access to this record"));
            }

            // render the record into an itemeditform
            return $controller->redirect($link);


        } catch (\Exception $e) {
            $form = $gridField->getForm();
            $form->sessionMessage(
                DBField::create_field('HTMLFragment', $e->getMessage()),
                ValidationResult::TYPE_BAD
            );
            if($link) {
                return $controller->redirect($link);
            } else {
                return $controller->redirectBack();
            }
        }
    }

}
