<?php

namespace NSWDPC\Utilities\VersionedRecordDiscovery;

use LeKoala\CmsActions\CustomAction;
use LeKoala\CmsActions\SilverStripeIcons;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldPageCount;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldViewButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
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
        $key = RevertableVersionedGridFieldItemRequest::getRevertRequestValueName();
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
     * Ensure the revert action is correctly placed into the actions area
     */
    public function onAfterUpdateCMSActions(Fieldlist &$actions) {
        $version = RevertableVersionedGridFieldItemRequest::getRequestedRevertVersion();
        if($version) {
            $this->removeOtherActions($actions);
        }
    }

    public function removeOtherActions($actions) {
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

        $version = RevertableVersionedGridFieldItemRequest::getRequestedRevertVersion();
        if(!$version) {
            // requests not specifying a version cannot be workflowed
            $push = false;
        } else {
            // in a versioned view ... clear any other actions
            $this->removeOtherActions($actions);
        }

        if($push) {

            $actions->push( CustomAction::create(
                    $this->owner->getRevertToVersionActionName(),
                    _t(
                        __CLASS__ . ".REVERT_TO_VERSION_VERSION",
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
    public function getRevertToVersionActionName() {
        return "doRevertToVersion";
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
            $original_version = $version = RevertableVersionedGridFieldItemRequest::getRequestedRevertVersion();

            /**
             * @var string
             */
            if(!$version) {
                throw new RevertException(
                    _t(
                        __CLASS__ . ".VERSION_NOT_PROVIDED",
                        "The version you wish to revert to was not provided"
                    )
                );
            }

            // the version can be a stage or a version number - this action requires the latter
            $version = intval($version);
            if($version <= 1) {
                throw new RevertException(
                    _t(
                        __CLASS__ . ".INVALID_VERSION_NUMBER",
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
                        __CLASS__ . ".NOT_A_MODELADMIN",
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
                        __CLASS__ . ".NOT_A_MODELADMIN",
                        "Revert requires a record that implements CMSEditLink"
                    )
                );
            }

            // workflow check
            if($is_workflowed = $this->owner->isRevertableRecordWorkflowed()) {
                // Rule: items in a workflow cannot be reverted
                throw new RevertException(
                    _t(
                        __CLASS__ . ".IN_A_WORKFLOW",
                        "This item is in a workflow, please approve or reject the workflow prior to reverting it"
                    )
                );
            }

            // initial permission checks
            if(!$this->owner->canView() || !$this->owner->canEdit()) {
                throw new RevertException(
                    _t(
                        __CLASS__ . ".NO_ACCESS_TO_RECORD",
                        "You do not have access to this record"
                    )
                );
            }

            $latest = Versioned::get_latest_version(get_class($this->owner), $this->owner->ID);
            if(!$latest) {
                throw new RevertException(
                    _t(
                        __CLASS__ . ".NO_LATEST_VERSION",
                        "The latest version of this record could not be found, this record cannot be reverted"
                    )
                );
            }

            if($latest->Version == $version) {
                throw new RevertException(
                    _t(
                        __CLASS__ . ".CANNOT_REVERT_TO_LATEST_VERSION",
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
                __CLASS__ . ".GENERAL_ERROR_ON_REVERT",
                "The record could not be reverted to the requested verion"
            );
        }

        // check for an initial error in the setup
        if($error) {

            // set the form session message
            $form->sessionMessage($message, ValidationResult::TYPE_BAD, ValidationResult::CAST_HTML);

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
                throw new \Exception("No current version returned post-rollback");
            }
            $rollback = true;
        } catch (\InvalidArgumentException $e) {
            // catch invalid argument exceptions - Versioned doesn't like the input
            $message = _t(
                __CLASS__ . ".VERSIONED_ROLLBACK_FAILED_INVALID_INPUT",
                "Sorry, we failed to revert to version {version} - there was a system error.",
                [
                    'version' => $version
                ]
            );
        } catch(\Exception $e) {
            // catch general exceptions in the rollback
            $message = _t(
                __CLASS__ . ".VERSIONED_ROLLBACK_FAILED",
                "Sorry, we could not revert to version {version} at this time.",
                [
                    'version' => $version
                ]
            );
        }

        if(!$rollback) {
            // rollback failed, set the message to provide feedback
            $form->sessionMessage($message, ValidationResult::TYPE_BAD, ValidationResult::CAST_HTML);
        } else {
            // all good...
            $message = _t(
                __CLASS__ . ".REVERTED_OK",
                "Reverted to version #{version}, the new version is #{new}",
                [
                    'version' => $version,
                    'new' => $current->Version
                ]
            );
            // the link target after a successul revert will be the draft/unversioned view of the record
            $link = $this->owner->CMSEditLink();
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

    public function updateCMSFields(Fieldlist $fields)
    {

        // performReadonlyTransformation

        /**
         * @var string
         */
        $version = RevertableVersionedGridFieldItemRequest::getRequestedRevertVersion();

        // Check if versioned
        if(!$this->owner->hasExtension(Versioned::class)) {
            return;
        }

        /**
         * @var DataObject|Versioned
         */
        $latest = Versioned::get_latest_version(get_class($this->owner), $this->owner->ID);

        if($is_workflowed = $this->owner->isRevertableRecordWorkflowed()) {
            // Rule: items in a workflow cannot be reverted
            $tab = $fields->findOrMakeTab('Root.History');
            $tab->setTitle("History (v{$latest->Version})");
            $fields->addFieldsToTab(
                'Root.History', [
                    LiteralField::create(
                        $this->getHistoryViewerFieldName() . 'Literal',
                        '<p class="message warning">'
                        . _t(
                            __CLASS__ . ".REVERT_NOT_AVAILABLE_WORKFLOW",
                            "'{title}' is currently in a workflow and cannot be reverted.<br>"
                            . "To revert this record to a previous version, the workflow request must first be approved or rejected.",
                            [
                                'title' => $this->owner->Title
                            ]
                        )
                        . '</p>'
                    )
                ]
            );

        } else {

            // if in versioned mode -> no need for a history listing
            if($version) {

                // on the versioned screen -> apply a hidden field with the version number
                $fields->addFieldToTab(
                    'Root.Main',
                    HiddenField::create(
                        $this->owner->getRevertTargetForUrl(),// just the field name
                        'Version', // the title
                        $version
                    )
                );

            }

            // On the the unversioned screen -> show a list of versions, most recent first
            $tab = $fields->findOrMakeTab('Root.History');
            $tab->setTitle("History (v{$latest->Version})");

            if($version) {
                $literal = LiteralField::create(
                    $this->getHistoryViewerFieldName() . 'Literal',
                    '<p class="message info">'
                    . _t(
                        __CLASS__ . ".AVAILABLE_VERSIONS_HELP",
                        "Previous versions of this record"
                    )
                    . '</p>'
                );
            } else {
                $literal = LiteralField::create(
                    $this->getHistoryViewerFieldName() . 'Literal',
                    '<p class="message info">'
                    . _t(
                        __CLASS__ . ".AVAILABLE_VERSIONS_HELP",
                        "These versions are available as rollback points"
                    )
                    . '</p>'
                );
            }

            // retrieve the listing of versions for this record
            $listing = $this->getHistoryListingField($version);
            $fields->addFieldsToTab(
                'Root.' . _t(__CLASS__ . '.HISTORY_TAB_NAME', 'History'),
                [
                    $literal,
                    $listing
                ]
            );


        }

    }

    /**
     * Gridfield with versions of this record
     * Note that a field *must* be returned even in a versioned view, otherwise the request will return a 404
     * @return FormField
     */
    protected function getHistoryListingField(string $version = null) : FormField {

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
        $field = GridField::create(
            $this->getHistoryViewerFieldName(),
            _t(__CLASS__ . '.HISTORY', 'History'),
            $list
        );

        // Config for this grid field is just a record viewer
        $config = new GridFieldConfig_RecordViewer();
        // ensure review actions are handled by this item request class
        $config->addComponent( new GridFieldDetailForm() );
        $config->getComponentByType(
            GridFieldDetailForm::class
        )->setItemRequestClass(RevertableVersionedGridFieldItemRequest::class);
        $config->getComponentByType(GridFieldDataColumns::class)
                    ->setDisplayFields([
                        'Version' => '#',
                        'LastEdited.Nice' => _t(__CLASS__ . '.WHEN', 'When'),
                        'Title' => _t(__CLASS__ . '.TITLE', 'Title'),
                        'Author.Name' => _t(__CLASS__ . '.AUTHOR', 'Author'),
                        'WasPublished.Nice'  => _t(__CLASS__ . '.WAS_PUBLISHED', 'Was published?')
                    ]);
        $config->removeComponentsByType([
            GridFieldFilterHeader::class,
            GridFieldToolbarHeader::class,
            GridFieldPaginator::class,
            GridFieldPageCount::class,
            GridFieldViewButton::class
        ]);

        // This causes all manner of double scroll badness!
        // it would be useful to go to this URL directly
        //$config->addComponent( new GridFieldViewButton() );

        if(!$version) {
            $config->addComponent( new ReviewForRevertButton() );
        }

        $field->setConfig($config);

        return $field;
    }

    public function getHistoryViewerFieldName() {
        return "ReviewAndRevert";
    }

}
