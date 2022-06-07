<?php
App::uses('AppController', 'Controller');

class WorkflowsController extends AppController
{
    public $components = array(
        'RequestHandler'
    );

    public function beforeFilter()
    {
        parent::beforeFilter();
        $this->Security->unlockedActions[] = 'hasAcyclicGraph';
        try {
            $this->Workflow->setupRedisWithException();
        } catch (Exception $e) {
            $this->set('error', $e->getMessage());
            $this->render('error');
        }
    }

    public function index()
    {
        $params = [
            'filters' => ['name', 'uuid'],
            'quickFilters' => ['name', 'uuid'],
        ];
        $this->CRUD->index($params);
        if ($this->IndexFilter->isRest()) {
            return $this->restResponsePayload;
        }
        $this->set('menuData', array('menuList' => 'workflows', 'menuItem' => 'index'));
    }

    public function rebuildRedis()
    {
        $this->Workflow->rebuildRedis();
    }

    public function edit($id)
    {
        $this->set('id', $id);
        $savedWorkflow = $this->Workflow->fetchWorkflow($id);
        if ($this->request->is('post') || $this->request->is('put')) {
            $newWorkflow = $this->request->data;
            $newWorkflow['Workflow']['data'] = JsonTool::decode($newWorkflow['Workflow']['data']);
            $newWorkflow = $this->__applyDataFromSavedWorkflow($newWorkflow, $savedWorkflow);
            $errors = $this->Workflow->editWorkflow($newWorkflow);
            $redirectTarget = ['action' => 'view', $id];
            if (!empty($errors)) {
                return $this->__getFailResponseBasedOnContext($errors, null, 'edit', $this->Workflow->id, $redirectTarget);
            } else {
                $successMessage = __('Workflow saved.');
                $savedWorkflow =$this->Workflow->fetchWorkflow($id);
                return $this->__getSuccessResponseBasedOnContext($successMessage, $savedWorkflow, 'edit', false, $redirectTarget);
            }
        } else {
            $savedWorkflow['Workflow']['data'] = JsonTool::encode($savedWorkflow['Workflow']['data']);
            $this->request->data = $savedWorkflow;
        }

        $this->set('menuData', array('menuList' => 'workflows', 'menuItem' => 'edit'));
        $this->render('add');
    }

    public function delete($id)
    {
        $params = [
        ];
        $this->CRUD->delete($id, $params);
        if ($this->IndexFilter->isRest()) {
            return $this->restResponsePayload;
        }
    }

    public function view($id)
    {
        $this->CRUD->view($id, [
        ]);
        if ($this->IndexFilter->isRest()) {
            return $this->restResponsePayload;
        }
        $this->set('id', $id);
        $this->set('menuData', array('menuList' => 'workflows', 'menuItem' => 'view'));
    }

    public function editor($trigger_id)
    {
        $modules = $this->Workflow->getModulesByType();
        $trigger_ids = Hash::extract($modules['blocks_trigger'], '{n}.id');
        if (!in_array($trigger_id, $trigger_ids)) {
            return $this->__getFailResponseBasedOnContext(
                [__('Unkown trigger %s', $trigger_id)],
                null,
                'add',
                $trigger_id,
                ['controller' => 'workflows', 'action' => 'triggers']
            );
        }
        $workflow = $this->Workflow->fetchWorkflowByTrigger($trigger_id, false);
        if (empty($workflow)) { // Workflow do not exists yet. Create it.
            $this->Workflow->create();
            $savedWorkflow = $this->Workflow->save([
                'name' => sprintf('Workflow for trigger %s', $trigger_id),
                'trigger_id' => $trigger_id,
            ]);
            if (empty($savedWorkflow)) {
                return $this->__getFailResponseBasedOnContext(
                    [__('Could not create workflow for trigger %s', $trigger_id), $this->validationErrors],
                    null,
                    'add',
                    $trigger_id,
                    ['controller' => 'workflows', 'action' => 'editor']
                );
            }
            $workflow = $savedWorkflow;
        }
        $modules = $this->Workflow->attachNotificationToModules($modules, $workflow);
        $this->set('selectedWorkflow', $workflow);
        $this->set('modules', $modules);
    }

    public function triggers()
    {
        $triggers = $this->Workflow->getModulesByType('trigger');
        $triggers = $this->Workflow->attachWorkflowToTriggers($triggers);
        $data = $triggers;
        if ($this->_isRest()) {
            return $this->RestResponse->viewData($data, $this->response->type());
        }
        $this->set('data', $data);
        $this->set('menuData', ['menuList' => 'workflows', 'menuItem' => 'index_trigger']);
    }

    public function moduleIndex()
    {
        $modules = $this->Workflow->getModulesByType();
        $this->Module = ClassRegistry::init('Module');
        $mispModules = $this->Module->getModules('Action');
        $this->set('module_service_error', !is_array($mispModules));
        $filters = $this->IndexFilter->harvestParameters(['type']);
        $moduleType = $filters['type'] ?? 'action';
        if ($moduleType == 'all') {
            $data = array_merge(
                $modules["blocks_action"],
                $modules["blocks_logic"]
            );
        } else {
            $data = $modules["blocks_{$moduleType}"];
        }
        if ($this->_isRest()) {
            return $this->RestResponse->viewData($data, $this->response->type());
        }
        $this->set('data', $data);
        $this->set('indexType', $moduleType);
        $this->set('menuData', ['menuList' => 'workflows', 'menuItem' => 'index_module']);
    }

    public function moduleView($module_id)
    {
        $module = $this->Workflow->getModuleByID($module_id);
        if (empty($module)) {
            throw new NotFoundException(__('Invalid trigger ID'));
        }
        $is_trigger = $module['module_type'] == 'trigger';
        if ($is_trigger) {
            $module = $this->Workflow->attachWorkflowToTriggers([$module])[0];
        }
        if ($this->_isRest()) {
            return $this->RestResponse->viewData($module, $this->response->type());
        }
        $this->set('data', $module);
        $this->set('menuData', ['menuList' => 'workflows', 'menuItem' => 'view_module']);
    }

    public function import()
    {
        if ($this->request->is('post') || $this->request->is('put')) {
            $data = $this->request->data['Workflow'];
            $text = FileAccessTool::getTempUploadedFile($data['submittedjson'], $data['json']);
            $workflow = JsonTool::decode($text);
            if ($workflow === null) {
                throw new MethodNotAllowedException(__('Error while decoding JSON'));
            }
            $workflow['Workflow']['enabled'] = false;
            $workflow['Workflow']['data'] = JsonTool::encode($workflow['Workflow']['data']);
            $this->request->data = $workflow;
            $this->add();
        }
    }

    public function export($id)
    {
        $workflow = $this->Workflow->fetchWorkflow($id);
        $content = JsonTool::encode($workflow, JSON_PRETTY_PRINT);
        $this->response->body($content);
        $this->response->type('json');
        $this->response->download(sprintf('workflow_%s_%s.json', $workflow['Workflow']['name'], time()));
        return $this->response;
    }

    private function __getSuccessResponseBasedOnContext($message, $data = null, $action = '', $id = false, $redirect = array())
    {
        if ($this->_isRest()) {
            if (!is_null($data)) {
                return $this->RestResponse->viewData($data, $this->response->type());
            } else {
                return $this->RestResponse->saveSuccessResponse('Workflow', $action, $id, false, $message);
            }
        } elseif ($this->request->is('ajax')) {
            return $this->RestResponse->saveSuccessResponse('Workflow', $action, $id, false, $message, $data);
        } else {
            $this->Flash->success($message);
            $this->redirect($redirect);
        }
        return;
    }

    private function __getFailResponseBasedOnContext($message, $data = null, $action = '', $id = false, $redirect = array())
    {
        if (is_array($message)) {
            $message = implode(', ', $message);
        }
        if ($this->_isRest()) {
            if ($data !== null) {
                return $this->RestResponse->viewData($data, $this->response->type());
            } else {
                return $this->RestResponse->saveFailResponse('Workflow', $action, $id, $message);
            }
        } elseif ($this->request->is('ajax')) {
            return $this->RestResponse->saveFailResponse('Workflow', $action, $id, $message, false, $data);
        } else {
            $this->Flash->error($message);
            $this->redirect($redirect);
        }
    }

    private function __applyDataFromSavedWorkflow($newWorkflow, $savedWorkflow)
    {
        if (!isset($newReport['Workflow'])) {
            $newReport = ['Workflow' => $newWorkflow];
        }
        $ignoreFieldList = ['id', 'uuid'];
        foreach (Workflow::CAPTURE_FIELDS as $field) {
            if (!in_array($field, $ignoreFieldList) && isset($newWorkflow['Workflow'][$field])) {
                $savedWorkflow['Workflow'][$field] = $newWorkflow['Workflow'][$field];
            }
        }
        return $savedWorkflow;
    }

    public function hasAcyclicGraph()
    {
        $this->request->allowMethod(['post']);
        $graphData = $this->request->data;
        $cycles = [];
        $isAcyclic = $this->Workflow->workflowGraphTool->isAcyclic($graphData, $cycles);
        $data = [
            'is_acyclic' => $isAcyclic,
            'cycles' => $cycles,
        ];
        return $this->RestResponse->viewData($data, 'json');
    }
}
