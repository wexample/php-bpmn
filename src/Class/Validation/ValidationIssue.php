<?php

namespace Wexample\PhpBpmn\Class\Validation;

use Wexample\PhpBpmn\Enum\IssueSeverity;

/**
 * One thing wrong, or doubtful, in a model: a stable code, the element it is
 * about, and a sentence for whoever fixes it.
 */
final readonly class ValidationIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public ?string $elementId,
        public string $message,
        public ?string $processId = null,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s%s: %s', $this->severity->value, $this->code, $this->elementId ? ' (' . $this->elementId . ')' : '', $this->message);
    }
}
