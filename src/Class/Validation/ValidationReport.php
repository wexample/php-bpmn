<?php

namespace Wexample\PhpBpmn\Class\Validation;

use Wexample\PhpBpmn\Enum\IssueSeverity;

/**
 * Everything a validation found.
 */
class ValidationReport
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    public function add(ValidationIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function getIssues(?IssueSeverity $severity = null): array
    {
        return $severity === null
            ? $this->issues
            : array_values(array_filter($this->issues, static fn (ValidationIssue $issue) => $issue->severity === $severity));
    }

    /**
     * @return list<ValidationIssue>
     */
    public function getErrors(): array
    {
        return $this->getIssues(IssueSeverity::ERROR);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function getWarnings(): array
    {
        return $this->getIssues(IssueSeverity::WARNING);
    }

    public function isValid(): bool
    {
        return ! $this->getErrors();
    }

    /**
     * @return list<string> the codes, for a test
     */
    public function getCodes(?IssueSeverity $severity = null): array
    {
        return array_map(static fn (ValidationIssue $issue) => $issue->code, $this->getIssues($severity));
    }

    public function has(string $code, ?string $elementId = null): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code && ($elementId === null || $issue->elementId === $elementId)) {
                return true;
            }
        }

        return false;
    }
}
