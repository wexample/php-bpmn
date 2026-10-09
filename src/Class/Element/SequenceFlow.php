<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * A way from one node to the next, taken under its condition when it has
 * one — an expression the engine running the process evaluates.
 */
class SequenceFlow extends AbstractElement
{
    public function __construct(
        string $id,
        private readonly string $source,
        private readonly string $target,
        private ?string $condition = null,
        ?string $name = null,
    ) {
        parent::__construct($id, $name);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function setCondition(?string $condition): static
    {
        $this->condition = $condition;

        return $this;
    }
}
