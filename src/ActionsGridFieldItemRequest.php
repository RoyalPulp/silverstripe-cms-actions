<?php

namespace LeKoala\CmsActions;

use Exception;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Control\Director;
use SilverStripe\Forms\FormAction;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;

/**
 * Decorates GridDetailForm_ItemRequest to use new form actions and buttons.
 *
 * This is a lightweight version of BetterButtons that use default getCMSActions functionnality
 * on DataObjects
 *
 * @link https://github.com/unclecheese/silverstripe-gridfield-betterbuttons
 * @link https://github.com/unclecheese/silverstripe-gridfield-betterbuttons/blob/master/src/Extensions/GridFieldBetterButtonsItemRequest.php
 * @property \SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest $owner
 */
class ActionsGridFieldItemRequest extends DataExtension
{
    use Configurable;

    /**
     * @config
     * @var boolean
     */
    private static $enable_save_prev_next = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_save_close = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_delete_right = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_utils_prev_next = false;

    /**
     * @var array Allowed controller actions
     */
    private static $allowed_actions = array(
        'doSaveAndClose',
        'doSaveAndNext',
        'doSaveAndPrev',
        'doCustomAction', // For CustomAction
        'doCustomLink', // For CustomLink
    );

    /**
     * @return array
     */
    protected function getAvailableActions($actions)
    {
        $list = [];
        foreach ($actions as $action) {
            $list[] = $action->getName();
        }
        return $list;
    }

    /**
     * Updates the detail form to include new form actions and buttons
     *
     * Reorganize things a bit
     *
     * @param Form The ItemEditForm object
     */
    public function updateItemEditForm($form)
    {
        $itemRequest = $this->owner;
        $record = $itemRequest->record;
        if (!$record) {
            $record = $form->getRecord();
        }
        if (!$record) {
            return;
        }

        // We get the actions as defined on our record
        $CMSActions = $record->getCMSActions();

        // We can the actions from the GridFieldDetailForm_ItemRequest
        // It sets the Save and Delete buttons + Right Group
        $actions = $form->Actions();

        // The default button group that contains the Save or Create action
        // @link https://docs.silverstripe.org/en/4/developer_guides/customising_the_admin_interface/how_tos/extend_cms_interface/#extending-the-cms-actions
        $MajorActions = $actions->fieldByName('MajorActions');

        // If it doesn't exist, push to default group
        if (!$MajorActions) {
            $MajorActions = $actions;
        }

        // Push our actions that are otherwise ignored by SilverStripe
        foreach ($CMSActions as $action) {
            $actions->push($action);
        }

        // Add extension hook
        $record->extend('onBeforeUpdateCMSActions', $actions);

        // We have a 4.4 setup, before that there was no RightGroup
        $RightGroup = $actions->fieldByName('RightGroup');

        // Insert again to make sure our actions are properly placed after apply changes
        if ($RightGroup) {
            $actions->remove($RightGroup);
            $actions->push($RightGroup);
        }

        if (self::config()->enable_save_close) {
            $this->addSaveAndClose($actions, $record);
        }

        if (self::config()->enable_save_prev_next) {
            $this->addSaveNextAndPrevious($actions, $record);
        }

        if (self::config()->enable_delete_right) {
            $this->moveCancelAndDelete($actions, $record);
        }

        // Add extension hook
        $record->extend('onAfterUpdateCMSActions', $actions);
    }

    /**
     * @param FieldList $actions
     * @param DataObject $record
     * @return void
     */
    public function moveCancelAndDelete(FieldList $actions, DataObject $record)
    {
        // We have a 4.4 setup, before that there was no RightGroup
        $RightGroup = $actions->fieldByName('RightGroup');

        // Move delete at the end
        $deleteAction = $actions->fieldByName('action_doDelete');
        if ($deleteAction) {
            // Move at the end of the stack
            $actions->remove($deleteAction);
            $actions->push($deleteAction);

            if ($RightGroup) {
                // Stack position is enough to have it on the left
            } else {
                $deleteAction->addExtraClass('align-right');
            }
            // Set custom titlte
            if ($record->hasMethod('getDeleteButtonTitle')) {
                $deleteAction->setTitle($record->getDeleteButtonTitle());
            }
        }
        // Move cancel at the end
        $cancelButton = $actions->fieldByName('cancelbutton');
        if ($cancelButton) {
            // Move at the end of the stack
            $actions->remove($cancelButton);
            $actions->push($cancelButton);
            if ($RightGroup) {
                // Stack position is enough to have it on the left
            } else {
                $deleteAction->addExtraClass('align-right');
            }
            // Set custom titlte
            if ($record->hasMethod('getCancelButtonTitle')) {
                $cancelButton->setTitle($record->getCancelButtonTitle());
            }
        }
    }

    /**
     * @param FieldList $actions
     * @param DataObject $record
     * @return void
     */
    public function addSaveNextAndPrevious(FieldList $actions, DataObject $record)
    {
        if (!$record->canEdit()) {
            return;
        }
        if (!$record->ID) {
            return;
        }

        $MajorActions = $actions->fieldByName('MajorActions');

        // If it doesn't exist, push to default group
        if (!$MajorActions) {
            $MajorActions = $actions;
        }

        $getPreviousRecordID = $this->owner->getPreviousRecordID();
        $getNextRecordID = $this->owner->getNextRecordID();

        // Coupling for HasPrevNextUtils
        if (Controller::has_curr()) {
            $request =  Controller::curr()->getRequest();
            $routeParams = $request->routeParams();
            $routeParams['PreviousRecordID'] = $getPreviousRecordID;
            $routeParams['NextRecordID'] = $getNextRecordID;
            $request->setRouteParams($routeParams);
        }

        if ($getPreviousRecordID) {
            $doSaveAndPrev = new FormAction('doSaveAndPrev', _t('ActionsGridFieldItemRequest.SAVEANDPREVIOUS', 'Save and Previous'));
            $doSaveAndPrev->addExtraClass($this->getBtnClassForRecord($record));
            $doSaveAndPrev->addExtraClass('font-icon-angle-double-left');
            $doSaveAndPrev->setUseButtonTag(true);
            $MajorActions->push($doSaveAndPrev);
        }
        if ($getNextRecordID) {
            $doSaveAndNext = new FormAction('doSaveAndNext', _t('ActionsGridFieldItemRequest.SAVEANDNEXT', 'Save and Next'));
            $doSaveAndNext->addExtraClass($this->getBtnClassForRecord($record));
            $doSaveAndNext->addExtraClass('font-icon-angle-double-right');
            $doSaveAndNext->setUseButtonTag(true);
            $MajorActions->push($doSaveAndNext);
        }
    }

    /**
     * @param FieldList $actions
     * @param DataObject $record
     * @return void
     */
    public function addSaveAndClose(FieldList $actions, DataObject $record)
    {
        if (!$record->canEdit()) {
            return;
        }

        $MajorActions = $actions->fieldByName('MajorActions');

        // If it doesn't exist, push to default group
        if (!$MajorActions) {
            $MajorActions = $actions;
        }

        if ($record->ID) {
            $label = _t('ActionsGridFieldItemRequest.SAVEANDCLOSE', 'Save and Close');
        } else {
            $label = _t('ActionsGridFieldItemRequest.CREATEANDCLOSE', 'Create and Close');
        }
        $saveAndClose = new FormAction('doSaveAndClose', $label);
        $saveAndClose->addExtraClass($this->getBtnClassForRecord($record));
        $saveAndClose->addExtraClass('font-icon-level-up');
        $saveAndClose->setUseButtonTag(true);
        $MajorActions->push($saveAndClose);
    }

    /**
     * New and existing records have different classes
     *
     * @param DataObject $record
     * @return string
     */
    protected function getBtnClassForRecord(DataObject $record)
    {
        if ($record->ID) {
            return 'btn-outline-primary';
        }
        return 'btn-primary';
    }

    /**
     * TODO: move to softdelete module
     *
     * @param [type] $actions
     * @return void
     */
    public function softDeleteOnAfterUpdateCMSActions($actions)
    {
        $RightGroup = $actions->fieldByName('RightGroup');
        $deleteAction = $actions->fieldByName('action_doDelete');
        $undoDelete = $actions->fieldByName('action_doCustomAction[undoDelete]');
        $forceDelete = $actions->fieldByName('action_doCustomAction[forceDelete]');
        $softDelete = $actions->fieldByName('action_doCustomAction[softDelete]');
        if ($softDelete) {
            if ($deleteAction) {
                $actions->remove($deleteAction);
            }
            if ($RightGroup) {
                // Move at the end of the stack
                $actions->remove($softDelete);
                $actions->push($softDelete);
                // Without this positionning fails and button is stuck near +
                if ($RightGroup) {
                    $softDelete->addExtraClass('default-position');
                }
            }
        }
        if ($forceDelete) {
            if ($deleteAction) {
                $actions->remove($deleteAction);
            }
            if ($RightGroup) {
                // Move at the end of the stack
                $actions->remove($forceDelete);
                $actions->push($forceDelete);
                // Without this positionning fails and button is stuck near +
                if ($RightGroup) {
                    $forceDelete->addExtraClass('default-position');
                }
            }
        }
    }

    /**
     * Forward a given action to a DataObject
     *
     * Action must be declared in getCMSActions to be called
     *
     * @param string $action
     * @param array $data
     * @param Form $form
     * @return HTTPResponse|DBHTMLText
     */
    protected function forwardActionToRecord($action, $data = [], $form = null)
    {
        $controller = $this->getToplevelController();
        $record = $this->owner->record;
        $definedActions = $record->getCMSActions();
        // Check if the action is indeed available
        $clickedAction = null;
        if (!empty($definedActions)) {
            foreach ($definedActions as $definedAction) {
                $definedActionName = $definedAction->getName();
                if ($definedAction->hasMethod('actionName')) {
                    $definedActionName = $definedAction->actionName();
                }
                if ($definedActionName == $action) {
                    $clickedAction = $definedAction;
                }
            }
        }
        if (!$clickedAction) {
            $class = get_class($record);
            return $this->owner->httpError(403, 'Action not available on ' . $class . '. It must be one of :' . implode(',', $this->getAvailableActions($definedActions)));
        }
        $message = null;
        $error = false;
        try {
            $result = $record->$action($data, $form, $controller);

            // We have a response
            if ($result && $result instanceof HTTPResponse) {
                return $result;
            }

            if ($result === false) {
                // Result returned an error (false)
                $error = true;
                $message = _t(
                    'ActionsGridFieldItemRequest.FAILED',
                    'Action {action} failed on {name}',
                    array(
                        'action' => $clickedAction->getTitle(),
                        'name' => $record->i18n_singular_name(),
                    )
                );
            } elseif (is_string($result)) {
                // Result is a message
                $message = $result;
            }
        } catch (Exception $ex) {
            $error = true;
            $message = $ex->getMessage();
        }
        $isNewRecord = $record->ID == 0;
        // Build default message
        if (!$message) {
            $message = _t(
                'ActionsGridFieldItemRequest.DONE',
                'Action {action} was done on {name}',
                array(
                    'action' => $clickedAction->getTitle(),
                    'name' => $record->i18n_singular_name(),
                )
            );
        }
        $status = 'good';
        if ($error) {
            $status = 'bad';
        }
        // We don't have a form, simply return the result
        if (!$form) {
            if ($error) {
                return $this->owner->httpError(403, $message);
            }
            return $message;
        }
        if (Director::is_ajax()) {
            $controller = $this->getToplevelController();
            $controller->getResponse()->addHeader('X-Status', rawurlencode($message));
            if (method_exists($clickedAction, 'getShouldRefresh') && $clickedAction->getShouldRefresh()) {
                $controller->getResponse()->addHeader('X-Reload', true);
            }
        } else {
            $form->sessionMessage($message, $status, ValidationResult::CAST_HTML);
        }
        // Redirect after action
        return $this->redirectAfterAction($isNewRecord);
    }

    /**
     * Handles custom links
     *
     * Use CustomLink with default behaviour to trigger this
     *
     * See:
     * DefaultLink::getModelLink
     * GridFieldCustomLink::getLink
     *
     * @param HTTPRequest $request
     * @return HTTPResponse|DBHTMLText
     */
    public function doCustomLink(HTTPRequest $request)
    {
        $action = $request->getVar('CustomLink');
        return $this->forwardActionToRecord($action);
    }

    /**
     * Handles custom actions
     *
     * Use CustomAction class to trigger this
     *
     * @param array The form data
     * @param Form The form object
     * @return HTTPResponse|DBHTMLText
     */
    public function doCustomAction($data, $form)
    {
        $action = key($data['action_doCustomAction']);
        return $this->forwardActionToRecord($action, $data, $form);
    }

    /**
     * Saves the form and goes back to list view
     *
     * @param array The form data
     * @param Form The form object
     */
    public function doSaveAndClose($data, $form)
    {
        $result = $this->owner->doSave($data, $form);
        // Redirect after save
        $controller = $this->getToplevelController();
        $controller->getResponse()->addHeader("X-Pjax", "Content");
        return $controller->redirect($this->getBackLink());
    }

    /**
     * Saves the form and goes back to the next item
     *
     * @param array The form data
     * @param Form The form object
     */
    public function doSaveAndNext($data, $form)
    {
        $record = $this->owner->record;
        $result = $this->owner->doSave($data, $form);
        // Redirect after save
        $controller = $this->getToplevelController();
        $controller->getResponse()->addHeader("X-Pjax", "Content");

        $getNextRecordID = $this->owner->getNextRecordID();
        $class = get_class($record);
        $next = $class::get()->byID($getNextRecordID);

        $link = $this->owner->getEditLink($getNextRecordID);

        // Link to a specific tab if set
        if ($next && !empty($data['_activetab'])) {
            $link .= '#' . $data['_activetab'];
        }
        return $controller->redirect($link);
    }

    /**
     * Saves the form and goes to the previous item
     *
     * @param array The form data
     * @param Form The form object
     */
    public function doSaveAndPrev($data, $form)
    {
        $record = $this->owner->record;
        $result = $this->owner->doSave($data, $form);
        // Redirect after save
        $controller = $this->getToplevelController();
        $controller->getResponse()->addHeader("X-Pjax", "Content");

        $getPreviousRecordID = $this->owner->getPreviousRecordID();
        $class = get_class($record);
        $prev = $class::get()->byID($getPreviousRecordID);

        $link = $this->owner->getEditLink($getPreviousRecordID);

        // Link to a specific tab if set
        if ($prev && !empty($data['_activetab'])) {
            $link .= '#' . $data['_activetab'];
        }
        return $controller->redirect($link);
    }

    /**
     * Gets the top level controller.
     *
     * @return Controller
     * @todo  This had to be directly copied from {@link GridFieldDetailForm_ItemRequest}
     * because it is a protected method and not visible to a decorator!
     */
    protected function getToplevelController()
    {
        $c = $this->owner->getController();
        while ($c && $c instanceof GridFieldDetailForm_ItemRequest) {
            $c = $c->getController();
        }
        return $c;
    }

    /**
     * Gets the back link
     *
     * @return string
     * @todo This had to be directly copied from {@link GridFieldDetailForm_ItemRequest}
     * because it is a protected method and not visible to a decorator!
     */
    public function getBackLink()
    {
        // TODO Coupling with CMS
        $backlink = '';
        $toplevelController = $this->getToplevelController();
        if ($toplevelController && $toplevelController instanceof LeftAndMain) {
            if ($toplevelController->hasMethod('Backlink')) {
                $backlink = $toplevelController->Backlink();
            } elseif ($this->owner->getController()->hasMethod('Breadcrumbs')) {
                $parents = $this->owner->getController()->Breadcrumbs(false)->items;
                $backlink = array_pop($parents)->Link;
            }
        }
        if (!$backlink) {
            $backlink = $toplevelController->Link();
        }
        return $backlink;
    }

    /**
     * Response object for this request after a successful save
     *
     * @param bool $isNewRecord True if this record was just created
     * @return HTTPResponse|DBHTMLText
     * @todo  This had to be directly copied from {@link GridFieldDetailForm_ItemRequest}
     * because it is a protected method and not visible to a decorator!
     */
    protected function redirectAfterAction($isNewRecord)
    {
        $controller = $this->getToplevelController();
        if ($isNewRecord) {
            return $controller->redirect($this->owner->Link());
        } elseif ($this->owner->gridField->getList()->byID($this->owner->record->ID)) {
            // Return new view, as we can't do a "virtual redirect" via the CMS Ajax
            // to the same URL (it assumes that its content is already current, and doesn't reload)
            return $this->owner->edit($controller->getRequest());
        } else {
            // Changes to the record properties might've excluded the record from
            // a filtered list, so return back to the main view if it can't be found
            $url = $controller->getRequest()->getURL();
            $noActionURL = $controller->removeAction($url);
            $controller->getRequest()->addHeader('X-Pjax', 'Content');
            return $controller->redirect($noActionURL, 302);
        }
    }
}