<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * A message from one pool to another: between participants, or nodes of
 * their processes.
 */
class MessageFlow extends AbstractElement
{
    public function __construct(
        string $id,
        private readonly string $source,
        private readonly string $target,
        ?string $name = null,
        private ?string $messageRef = null,
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

    public function getMessageRef(): ?string
    {
        return $this->messageRef;
    }
}
