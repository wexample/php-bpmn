<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\EventPosition;

/**
 * Something that happens: a start, an end, something waited for or raised
 * in the flow, or — on the edge of an activity — while it runs.
 */
class Event extends AbstractFlowNode
{
    /** @var list<EventDefinition> */
    private array $definitions = [];

    /** For a boundary event: the activity it is attached to. */
    private ?string $attachedTo = null;

    /** For a boundary event: whether it stops the activity, or runs beside it. */
    private bool $cancelActivity = true;

    public function __construct(
        string $id,
        ?string $name = null,
        private EventPosition $position = EventPosition::START,
    ) {
        parent::__construct($id, $name);
    }

    public function getPosition(): EventPosition
    {
        return $this->position;
    }

    /**
     * @return list<EventDefinition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function addDefinition(EventDefinition $definition): static
    {
        $this->definitions[] = $definition;

        return $this;
    }

    public function getDefinition(?EventDefinitionType $type = null): ?EventDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($type === null || $definition->getType() === $type) {
                return $definition;
            }
        }

        return null;
    }

    public function is(EventDefinitionType $type): bool
    {
        return $this->getDefinition($type) !== null;
    }

    public function getAttachedTo(): ?string
    {
        return $this->attachedTo;
    }

    public function setAttachedTo(?string $activityId, bool $cancelActivity = true): static
    {
        $this->attachedTo = $activityId;
        $this->cancelActivity = $cancelActivity;

        return $this;
    }

    public function isCancelActivity(): bool
    {
        return $this->cancelActivity;
    }

    public function getElementName(): string
    {
        return $this->position->value;
    }
}
