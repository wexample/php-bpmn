<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * A pool: someone taking part in a collaboration, running a process of the
 * model — or a black box, with none.
 */
class Participant extends AbstractElement
{
    public function __construct(
        string $id,
        ?string $name = null,
        private ?string $processRef = null,
    ) {
        parent::__construct($id, $name);
    }

    public function getProcessRef(): ?string
    {
        return $this->processRef;
    }
}
