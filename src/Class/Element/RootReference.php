<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * What events refer to, declared once for the whole model: a message, a
 * signal, an error. Its kind is the element it is written as.
 */
class RootReference extends AbstractElement
{
    public const string MESSAGE = 'message';
    public const string SIGNAL = 'signal';
    public const string ERROR = 'error';
    public const string ESCALATION = 'escalation';

    public function __construct(
        string $id,
        ?string $name,
        private readonly string $kind,
        private ?string $code = null,
    ) {
        parent::__construct($id, $name);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** An error or escalation code. */
    public function getCode(): ?string
    {
        return $this->code;
    }
}
