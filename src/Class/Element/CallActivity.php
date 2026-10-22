<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * Another process, run as one step of this one.
 */
class CallActivity extends AbstractFlowNode
{
    public function __construct(
        string $id,
        ?string $name = null,
        private ?string $calledElement = null,
    ) {
        parent::__construct($id, $name);
    }

    public function getCalledElement(): ?string
    {
        return $this->calledElement;
    }

    public function setCalledElement(?string $calledElement): static
    {
        $this->calledElement = $calledElement;

        return $this;
    }

    public function getElementName(): string
    {
        return 'callActivity';
    }
}
