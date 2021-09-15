<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use LeKoala\CmsActions\CustomAction;
use LeKoala\CmsActions\SilverStripeIcons;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionMenu;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldPageCount;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Versioned\ChangeSet;
use SilverStripe\Versioned\ChangeSetItem;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;

/**
 * Provides the ability for a Versioned record to be reverted to a selected version
 * This extension provides a 'History' tab
 * @author James
 */
class Revertable extends DataExtension {

    private $_cache_updatedcmsactions = false;

    /**
     * Returns the revert key/value (if supplied) for use in URL and form fields
     */
    public function getRevertTargetForUrl($value = null)  : string {
        $key = Revertable_VersionedGridFieldItemRequest::getRevertRequestValueName();
        if($value) {
            return "{$key}={$value}";
        } else {
            return $key;
        }
    }

    /**
     * Returns the link to all review and revert of this record
     * @return string|boolean
     */
    public function getVersionedRevertLink($version) {

        $controller = Controller::curr();

        // the current controller must be a modeladmin
        if(!$controller || !$controller instanceof ModelAdmin) {
            return false;
        }

        // record does not yet exist, cannot revert
        if(!$this->owner->exists()) {
            return false;
        }

        $class = str_replace('\\', '-', get_class($this->owner));
        $link = Controller::join_links(
            $controller->Link(
                Controller::join_links(

                    // the base link to
                    $class,
                    "EditForm",
                    "field",
                    $class,
                    "item",
                    $this->owner->ID,

                    "ItemEditForm",
                    "field",
                    $this->owner->getHistoryViewerFieldName(),
                    "item",
                    $this->owner->ID,
                    "view",
                    "?" . $this->owner->getRevertTargetForUrl($version)
                )
            )
        );
        return $link;
    }

    /**
     * Determine if the record is in a workflow (via advanced workflow module)
     * Records in a workflow cannot be reverted
     */
    public function isRevertableRecordWorkflowed() {
        if(!class_exists(WorkflowApplicable::class)) {
            return false;
        }
        $active = $this->owner->getWorkflowService()->getWorkflowFor($this->owner);
        return $active instanceof WorkflowInstance;
    }

    /**
     * Remove all other actions when the version is present
     */
    public function onAfterUpdateCMSActions(Fieldlist &$actions) {
        $version = Revertable_VersionedGridFieldItemRequest::getRequestedRevertVersion();
        if($version) {
            $this->removeOtherActions($actions);
        }
    }

    /**
     * Rmemove actions from the Fieldlist, excluding the revert custom action
     */
    public function removeOtherActions(Fieldlist $actions) {
        // remove all actions, apart from te revert action
        $revert_action = $this->owner->getRevertToVersionActionName();
        foreach($actions as $action) {
            // do not remove this
            if($action instanceof CustomAction && $action->actionName() == $revert_action) {
                continue;
            }
            $actions->remove($action);
        }
    }

    /**
     * See {@link LeKoala\CmsActions\ActionsGridFieldItemRequest::forwardActionToRecord()}
     * for action processing, which eventually calls self::doRevertToVersion()
     */
    public function updateCmsActions(Fieldlist $actions) {

        $push = true;
        if($is_workflowed = $this->owner->isRevertableRecordWorkflowed()) {
            // Rule: items in a workflow cannot be reverted
            $push = false;
        }

        $version = Revertable_VersionedGridFieldItemRequest::getRequestedRevertVersion();
        if(!$version) {
            // Rule: when no version, do not push the revert action
            $push = false;
        } else {
            // Rule: in a version view ... clear any other actions
            $this->removeOtherActions($actions);
        }

        if($push) {

            $actions->push( CustomAction::create(
                    $this->owner->getRevertToVersionActionName(),
                    _t(
                        "ReviewAndRevert.REVERT_TO_VERSION_VERSION",
                        "Revert to version {version}",
                        [
                            'title' => $this->owner->Title,
                            'version' => $version
                        ]
                    )
                )->setButtonIcon(SilverStripeIcons::ICON_BACK_IN_TIME)
                ->addExtraClass('btn-warning')
            );
        }
    }

    /**
     * Return the name of the action
     */
    public function getRevertToVersionActionName() : string {
        return "doRevertToVersion";
    }

    /**
     * Attempt to get the latest version for this record
     */
    public function getLatestVersionForRevert() {
        if(!$this->owner->exists()) {
            return false;
        } else {
            return Versioned::get_latest_version(get_class($this->owner), $this->owner->ID);
        }
    }

    /**
     * Handle the revert to the version submitted in the request
     * See {@link LeKoala\CmsActions\ActionsGridFieldItemRequest::forwardActionToRecord()}
     * This method returns an HTTPResponse in order to trigger response handling in forwardActionToRecord()
     * @return string the successful message when revert occurs
     * @throws \Exception
     */
    public function doRevertToVersion($data, $form, $controller) {

        try {
            // initially no error
            $error = false;
            // no link target is set
            $link = "";
            // the version to revert to  - store the original version (raw request) and the version (will be validated)
            $original_version = $version = Revertable_VersionedGridFieldItemRequest::getRequestedRevertVersion();

            /**
             * @var string
             */
            if(!$version) {
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.VERSION_NOT_PROVIDED",
                        "The version you wish to revert to was not provided"
                    )
                );
            }

            // the version can be a stage or a version number - this action requires the latter
            $version = intval($version);
            if($version <= 1) {
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.INVALID_VERSION_NUMBER",
                        "The requested version '{original_version}' is not a valid version",
                        [
                            'original_version' => $original_version
                        ]
                    )
                );
            }

            // controller must be a ModelAdmin
            if(!$controller instanceof ModelAdmin) {
                throw new \Exception(
                    _t(
                        "ReviewAndRevert.NOT_A_MODELADMIN",
                        "Revert can only happen in a ModelAdmin"
                    )
                );
            }

            // Get the view screen for this version of the record as a link target
            $link = $this->getVersionedRevertLink($version);

            // On successful reverts, the user will be redirected to the return value of this method
            if(!$this->owner->hasMethod('CMSEditLink')) {
                throw new \Exception(
                    _t(
                        "ReviewAndRevert.NO_CMS_EDIT_LINK",
                        "Revert requires a record that implements CMSEditLink"
                    )
                );
            }

            // workflow check
            if($is_workflowed = $this->owner->isRevertableRecordWorkflowed()) {
                // Rule: items in a workflow cannot be reverted
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.IN_A_WORKFLOW",
                        "This item is in a workflow, please approve or reject the workflow prior to reverting it"
                    )
                );
            }

            // initial permission checks
            if(!$this->owner->canView() || !$this->owner->canEdit()) {
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.NO_ACCESS_TO_RECORD",
                        "You do not have access to this record"
                    )
                );
            }

            $latest = $this->owner->getLatestVersionForRevert();
            if(!$latest) {
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.NO_LATEST_VERSION",
                        "The latest version of this record could not be found, this record cannot be reverted"
                    )
                );
            }

            if($latest->Version == $version) {
                throw new RevertException(
                    _t(
                        "ReviewAndRevert.CANNOT_REVERT_TO_LATEST_VERSION",
                        "Sorry, you cannot revert to the latest version of this record!"
                    )
                );
            }

        } catch (RevertException $e) {
            // handle initial error checking
            $error = true;
            $message = $e->getMessage();

        } catch (\Exception $e) {
            // general error
            $error = true;
            $message = _t(
                "ReviewAndRevert.GENERAL_ERROR_ON_REVERT",
                "The record could not be reverted to the requested verion"
            );
        }

        // check for an initial error in the setup
        if($error) {

            // set the form session message
            $form->sessionMessage($message, ValidationResult::TYPE_ERROR, ValidationResult::CAST_HTML);

            if(!$link) {
                // no link was set, something failed early on
                return $this->httpError(404);
            } else {
                // redirect back to the link provided - which should be the version of the record
                return $controller->redirect($link);
            }
        }

        // attempt the rollback
        try {
            // run revert via {@link \SilverStripe\Versioned\Versioned::rollbackRecursive}
            $rollback = false;
            $current = $this->owner->rollbackRecursive($version);
            if(empty($current->Version)) {
                throw new \Exception(
                    _t(
                        'ReviewAndRevert.NO_CURRENT_VERSION',
                        "No current version returned post-rollback"
                    )
                );
            }
            $rollback = true;
        } catch (\InvalidArgumentException $e) {
            // catch invalid argument exceptions - Versioned doesn't like the input
            $message = _t(
                "ReviewAndRevert.VERSIONED_ROLLBACK_FAILED_INVALID_INPUT",
                "Sorry, we failed to revert to version {version} - there was a system error.",
                [
                    'version' => $version
                ]
            );
        } catch(\Exception $e) {
            // catch general exceptions in the rollback
            $message = _t(
                "ReviewAndRevert.VERSIONED_ROLLBACK_FAILED",
                "Sorry, we could not revert to version {version} at this time.",
                [
                    'version' => $version
                ]
            );
        }

        if(!$rollback) {
            // rollback failed, set the message to provide feedback
            $form->sessionMessage($message, ValidationResult::TYPE_ERROR, ValidationResult::CAST_HTML);
        } else {

            // the link target after a successul revert will be the draft/unversioned view of the record
            $link = $this->owner->CMSEditLink();

            // all good...
            $message = _t(
                "ReviewAndRevert.REVERTED_OK",
                "Reverted to version {version}, the new version is {new} - redirecting to {link}",
                [
                    'version' => $version,
                    'new' => $current->Version,
                    'link' => $link
                ]
            );
            // set a successful message
            $form->sessionMessage($message, ValidationResult::TYPE_GOOD, ValidationResult::CAST_HTML);
        }

        if($link) {
            // redirect to the link set
            $controller->redirect($link);
            // get the response object from the controller
            $response = $controller->getResponse();
        } else {
            // if no link is provided, then something is v. wrong
            // note: revert may have been successul, we just cannot redirect to the record
            $result = $controller->httpError(404);
            //@var HTTPResponse_Exception
            $response = $result->getResponse();
        }

        return $response;

    }

    /**
     * Update CMS fields to provide a history listing
     * This is hit both in the current draft view and the ?rv= version view
     */
    public function updateCMSFields(Fieldlist $fields)
    {

        // Check if versioned
        if(!$this->owner->hasExtension(Versioned::class)) {
            return;
        }

        // if the owner does not exist .. cannot revert !
        if(!$this->owner->exists()) {
            return;
        }

        /**
         * The current version being viewed, may be empty
         * @var string
         */
        $version = Revertable_VersionedGridFieldItemRequest::getRequestedRevertVersion();

        /**
         * The most recent version of the record
         * @var DataObject|Versioned
         */
        $latest = $this->owner->getLatestVersionForRevert();
        $title = "";
        if(!empty($latest->Version)) {
            $title = _t(
                "ReviewAndRevert.HISTORY_VERSION",
                "History (v{version})",
                [
                    'version' => $latest->Version
                ]
            );
        }

        // The history fields must be present in order to handle the request to view a versioned record
        $historyFields = $this->owner->getHistoryFields();
        $tab = $fields->findOrMakeTab('Root.History');
        if($title) {
            $tab->setTitle( $title );
        }
        $fields->addFieldsToTab('Root.History', $historyFields);

    }

    /**
     * Get fields used to display the History listing
     * Includes a LiteralField message, an optional; GridField of versions, an optional hidden field
     */
    public function getHistoryFields() : FieldList {

        /**
         * Cannot show history fields for a non-existent record
         */
        if(!$this->owner->exists()) {
            return FieldList::create(
                LiteralField::create(
                    $this->owner->getHistoryViewerFieldName() . 'Literal',
                    '<p class="message warning">'
                    . _t(
                        "ReviewAndRevert.REVERT_NOT_AVAILABLE_RECORD_DOES_NOT_EXIST",
                        "This record does not exist and cannot be reverted."
                    )
                    . '</p>'
                )
            );
        }

        /**
         * @var DataObject|Versioned
         */
        $latest = $this->owner->getLatestVersionForRevert();

        /**
        * The version, if being requested, taken from the URL
         * @var string
         */
        $version = Revertable_VersionedGridFieldItemRequest::getRequestedRevertVersion();

        // available fields
        $listing = $literal = $hidden = null;

        if($is_workflowed = $this->owner->isRevertableRecordWorkflowed()) {

            // Rule: items in a workflow cannot be reverted
            $literal = LiteralField::create(
                        $this->owner->getHistoryViewerFieldName() . 'Literal',
                        '<p class="message warning">'
                        . _t(
                            "ReviewAndRevert.REVERT_NOT_AVAILABLE_WORKFLOW",
                            "'{title}' is currently in a workflow and cannot be reverted.<br>"
                            . "To revert this record to a previous version, the workflow request must first be approved or rejected.",
                            [
                                'title' => $this->owner->Title
                            ]
                        )
                        . '</p>'
            );

        } else {


            if($version) {

                $literal = LiteralField::create(
                    $this->owner->getHistoryViewerFieldName() . 'Literal',
                    '<p class="message info">'
                    . _t(
                        "ReviewAndRevert.AVAILABLE_VERSIONS_HELP",
                        "Previous versions of this record"
                    )
                    . '</p>'
                );

            } else {

                $literal = LiteralField::create(
                    $this->owner->getHistoryViewerFieldName() . 'Literal',
                    '<p class="message info">'
                    . _t(
                        "ReviewAndRevert.AVAILABLE_VERSIONS_HELP",
                        "These versions are available as rollback points"
                    )
                    . '</p>'
                );
            }

            // retrieve the listing of versions for this record
            $listing = $this->getHistoryListingField($latest->Version);

        }// end else

        $fields = FieldList::create();
        $fields->push($literal);
        if($listing) {
            $fields->push($listing);
        }
        if($hidden) {
            $fields->push($hidden);
        }

        return $fields;

    }

    /**
     * Gridfield with versions of this record
     * Note that a field *must* be returned even in a versioned view, otherwise the request will return a 404
     * @return GridField
     */
    protected function getHistoryListingField($excludeVersion = null) : GridField {
        $list = $this->owner->VersionsList()
                            ->filter(['WasDeleted' => 0])//ignore deleted deleted versions
                            ->sort(['Version' => 'DESC'])
                            ->setQueriedColumns([
                                "Version",
                                "WasPublished",
                                "ClassName",
                                "LastEdited",
                                "AuthorID"
                            ]);
        if($excludeVersion) {
            $list = $list->exclude(['Version' => $excludeVersion]);
        }

        $field = GridField::create(
            $this->owner->getHistoryViewerFieldName(),
            _t('ReviewAndRevert.HISTORY', 'History'),
            $list
        );

        // Config for this grid field is just a record viewer
        $config = new GridFieldConfig_Base();

        // Remove any componets not required in the
        $config->removeComponentsByType([
            GridFieldFilterHeader::class,
            GridFieldToolbarHeader::class,
            GridFieldPaginator::class,
            GridFieldPageCount::class,
            GridFieldEditButton::class,
            GridFieldViewButton::class
        ]);

        // Add an action menu
        $config->addComponent( new GridField_ActionMenu() );

        // ensure review actions are handled by this item request class
        $config->addComponent( new GridFieldDetailForm() );
        $config->getComponentByType(
            GridFieldDetailForm::class
        )->setItemRequestClass(Revertable_VersionedGridFieldItemRequest::class);

        // Update display fields with basic versioned information
        $config->getComponentByType(GridFieldDataColumns::class)
                    ->setDisplayFields([
                        'Version' => '#',
                        'LastEdited.Nice' => _t('ReviewAndRevert.WHEN', 'When'),
                        'Title' => _t('ReviewAndRevert.TITLE', 'Title'),
                        'Author.Name' => _t('ReviewAndRevert.AUTHOR', 'Author'),
                        'WasPublished.Nice'  => _t('ReviewAndRevert.WAS_PUBLISHED', 'Was published?')
                    ]);

        // Add the Review/revert button to the GridField
        $config->addComponent( new ReviewForRevertButton() );

        $field->setConfig( $config );

        return $field;
    }

    /**
     * Return the field name for the history viewer
     */
    public function getHistoryViewerFieldName() : string {
        return "ReviewAndRevert";
    }

    /**
     * Get the ChangeSet record for a version of this record
     * @return ChangeSet|null
     */
    public function getChangeSet(string $version) {
        if(!$this->owner->exists()) {
            return null;
        }
        $changeSetItem = ChangeSetItem::get()->filter([
            'ObjectClass' => get_class($this->owner),
            'ObjectID' => $this->owner->ID,
            'VersionAfter'=> $version
        ])->first();

        if(!empty($changeSetItem->ID)) {
            return $changeSetItem->ChangeSet();
        } else {
            return null;
        }
    }

    /**
     * Get all items in this version's changeset
     * @return DataList|null
     */
    public function getChangeSetItems($version) {
        if($changeSet = $this->owner->getChangeSet($version)) {
            return $changeSet->Changes();
        } else {
            return null;
        }
    }

    /**
     * Return changes field values and items in the changeset relevent to this version of the record
     * The return value is a FieldList with changes as templated Literal Fields
     * @return FieldList
     */
    public function getChangedItems() : FieldList {
        // attempt to find changed fields
        try {

            $fieldList = FieldList::create();

            // Nothing changed if it doesn't exist yet
            if(!$this->owner->exists()) {
                throw new \Exception("Record does not exist");
            }

            // Latest version
            $latest = $this->owner->getLatestVersionForRevert();
            if(empty($latest->ID)) {
                throw new \Exception("Record does not exist with latest version");
            }

            // If the same version ...
            if($this->owner->Version == $latest->Version) {
                throw new \Exception("Record is the latest version, no changes");
            }

            // A bunch of ignored fields
            $ignoredFields = [
                // 'Version',
                'WasPublished',
                'PublisherID',
                'LastEdited',
            ];

            // The available hasOne relations
            $recordHasOne = $this->owner->hasOne();

            // get array keys to remove hasOnes
            $recordHasOneKeys = array_keys($recordHasOne);
            array_walk($recordHasOneKeys,  function(&$v) {
                $v = "{$v}ID";
            });
            // Add to ignored fields
            $ignoredFields = array_merge($ignoredFields, $recordHasOneKeys);
            // Remove ignored fields
            $recordFields = $this->owner->getQueriedDatabaseFields();
            foreach($ignoredFields as $ignoredField) {
                unset($recordFields [ $ignoredField ] );
            }

            // Collect all the change for this field
            $data = [];
            $data['ChangedFields'] = ArrayList::create();

            foreach($recordFields as $fieldName => $fieldValue) {

                try {

                    // on error, just get value
                    $recordValue = $this->owner->{$fieldName};
                    $latestValue = $latest->{$fieldName};

                    if($recordValue instanceof DBField) {
                        $recordValue = $recordValue->getValue();
                    }
                    if($latestValue instanceof DBField) {
                        $latestValue = $latestValue->getValue();
                    }

                    if($recordValue != $latestValue) {

                        // push difference
                        $data['ChangedFields']->push(
                            ArrayData::create([
                                'FieldName' => $fieldName,
                                'Title' => FormField::name_to_label($fieldName),
                                'Type' => 'Field',
                                'RecordValue' =>  $recordValue,
                                'LatestValue' => $latestValue
                            ])
                        );
                    }

                } catch (\Exception $e) {
                    // handle errors in field value diffs
                }

            }

            // Create field summary
            if(!empty($data)) {
                $changedFields = ArrayData::create($data)
                    ->renderWith('NSWDPC/Utilities/VersionedRecordDiscovery/ChangedFields');
                $fieldList->push(
                    LiteralField::create(
                        'ChangedFieldsLiteral',
                        $changedFields
                    )
                );
            }

            /**
             * @var DataList|null
             */
            if($changeSetItems = $this->owner->getChangeSetItems( $this->owner->Version ) ) {
                $data = [];
                $data['ChangedItems'] = $changeSetItems;
                $changedItems = ArrayData::create($data)
                    ->renderWith('NSWDPC/Utilities/VersionedRecordDiscovery/ChangedItems');
                $fieldList->push(
                    LiteralField::create(
                        'ChangedItemsLiteral',
                        $changedItems
                    )
                );
            }


        } catch (\Exception $e) {
            $fieldList = FieldList::create();
            $fieldList->push(
                LiteralField::create(
                    'ChangedFieldLiteralError',
                    '<p class="message warning">' . htmlspecialchars($e->getMessage()) . '</p>'
                )
            );
        }

        return $fieldList;
    }

}
