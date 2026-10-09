<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\TimerKind;

/**
 * What an event is about: a message (`ref` to it), a timer (`timerKind` and
 * its ISO 8601 `expression`), a signal or an error (`ref`), a condition
 * (`expression`), a termination.
 */
class EventDefinition
{
    public function __construct(
        private readonly EventDefinitionType $type,
        private ?string $ref = null,
        private ?string $expression = null,
        private ?TimerKind $timerKind = null,
        private ?string $id = null,
    ) {
    }

    public static function message(?string $messageRef = null): self
    {
        return new self(EventDefinitionType::MESSAGE, $messageRef);
    }

    public static function timer(TimerKind $kind, string $expression): self
    {
        return new self(EventDefinitionType::TIMER, expression: $expression, timerKind: $kind);
    }

    public static function signal(?string $signalRef = null): self
    {
        return new self(EventDefinitionType::SIGNAL, $signalRef);
    }

    public static function error(?string $errorRef = null): self
    {
        return new self(EventDefinitionType::ERROR, $errorRef);
    }

    public static function conditional(string $condition): self
    {
        return new self(EventDefinitionType::CONDITIONAL, expression: $condition);
    }

    public static function terminate(): self
    {
        return new self(EventDefinitionType::TERMINATE);
    }

    public function getType(): EventDefinitionType
    {
        return $this->type;
    }

    public function getRef(): ?string
    {
        return $this->ref;
    }

    public function getExpression(): ?string
    {
        return $this->expression;
    }

    public function getTimerKind(): ?TimerKind
    {
        return $this->timerKind;
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}
