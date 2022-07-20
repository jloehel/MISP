<?php
include_once APP . 'Model/WorkflowModules/WorkflowBaseModule.php';

class Module_published_if extends WorkflowBaseLogicModule
{
    public $id = 'published-if';
    public $name = 'IF :: Published';
    public $description = 'Published IF / ELSE condition block. The `then` output will be used if the encoded conditions is satisfied, otherwise the `else` output will be used.';
    public $icon = 'code-branch';
    public $inputs = 1;
    public $outputs = 2;
    public $html_template = 'if';
    public $expect_misp_core_format = true;
    public $params = [];

    private $operators = [
        'equals' => 'Event is published',
        'not_equals' => 'Event is not published',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->params = [
            [
                'type' => 'select',
                'label' => 'Condition',
                'default' => 'equals',
                'options' => $this->operators,
            ],
        ];
    }

    public function exec(array $node, WorkflowRoamingData $roamingData, array &$errors=[]): bool
    {
        parent::exec($node, $roamingData, $errors);
        $params = $this->getParamsWithValues($node);

        $operator = $params['Condition']['value'];
        $data = $roamingData->getData();
        $path = 'Event.published';
        $is_published = !empty(Hash::get($data, $path));
        $eval = $this->evaluateCondition($is_published, $operator, true);
        return $eval;
    }
}
