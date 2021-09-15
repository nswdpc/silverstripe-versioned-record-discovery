<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use LeKoala\CmsActions\GridFieldRowButton;
use LeKoala\CmsActions\SilverStripeIcons;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_ActionMenuLink;
use SilverStripe\Forms\GridField\GridField_ActionMenuItem;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;

/**
 * Review for revert button action for a grid field row
 * Drops the viewer at an edit screen they can use to review prior to reverting
 * The current version is checked based on the ?v= arg
 * @author James
 */
class ReviewForRevertButton implements GridField_ColumnProvider, GridField_ActionMenuLink {

    /**
     * Return the column content for the action
     * If the record is the latest version, it will not display any actions
     * @return string
     */
    public function getColumnContent($gridField, $record, $columnName) {

        if (!$record->canView()) {
            return null;
        } else if($record->isLatestVersion()) {
            return _t('ReviewAndRevert.LATEST_VERSION', 'Latest version');
        } else if($is_workflowed = $record->isRevertableRecordWorkflowed()) {
            return _t('ReviewAndRevert.WORKFLOWED', 'Workflowed');
        } else {
            $data = new ArrayData([
                'Link' => $this->getUrl($gridField, $record, $columnName)
            ]);
            return $data->renderWith(GridFieldViewButton::class);
        }
    }

    /**
     * @inheritdoc
     */
    public function getTitle($gridField, $record, $columnName) {
        return _t("ReviewAndRevert.REVIEW", "Review");
    }

    /**
     * @inheritdoc
     */
    public function getGroup($gridField, $record, $columnName)
    {
        return GridField_ActionMenuItem::DEFAULT_GROUP;
    }

    /**
     * @inheritdoc
     */
    public function getExtraData($gridField, $record, $columnName)
    {
        return [
            "classNames" => "font-icon-eye action-detail view-link"
        ];
    }

    /**
     * @inheritdoc
     */
    public function getUrl($gridField, $record, $columnName)
    {
        if($record->isLatestVersion()) {
            return "";
        } else if($is_workflowed = $record->isRevertableRecordWorkflowed()) {
            return "";
        } else {
            return $record->getVersionedRevertLink($record->Version);
        }
    }

    public function augmentColumns($field, &$columns)
    {
        if (!in_array('Actions', $columns)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnsHandled($field)
    {
        return ['Actions'];
    }

    public function getColumnAttributes($field, $record, $col)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $col)
    {
        return ['title' => null];
    }

}
