<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * Steps grouped as one, inside the process: a flow of its own, run as a
 * single step from outside.
 */
class SubProcess extends AbstractFlowNode
{
    private FlowContainer $flow;

    public function __construct(string $id, ?string $name = null)
    {
        parent::__construct($id, $name);

        $this->flow = new FlowContainer();
    }

    public function getFlow(): FlowContainer
    {
        return $this->flow;
    }

    public function getElementName(): string
    {
        return 'subProcess';
    }
}
