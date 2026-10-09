<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Enum\TaskType;

/**
 * A unit of work, done by someone or by the application.
 */
class Task extends AbstractFlowNode
{
    /** The message a send or receive task carries. */
    private ?string $messageRef = null;

    public function __construct(
        string $id,
        ?string $name = null,
        private TaskType $type = TaskType::TASK,
    ) {
        parent::__construct($id, $name);
    }

    public function getType(): TaskType
    {
        return $this->type;
    }

    public function setType(TaskType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getMessageRef(): ?string
    {
        return $this->messageRef;
    }

    public function setMessageRef(?string $messageRef): static
    {
        $this->messageRef = $messageRef;

        return $this;
    }

    public function getElementName(): string
    {
        return $this->type->value;
    }
}
