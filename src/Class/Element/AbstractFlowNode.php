<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * What the flow goes through: an activity, an event, a gateway.
 */
abstract class AbstractFlowNode extends AbstractElement
{
    /**
     * The BPMN element it is written as: `userTask`, `exclusiveGateway`…
     */
    abstract public function getElementName(): string;
}
