<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * Anything of a model with an id: what BPMN calls a base element. Its
 * properties are ours, written as `wex:property` in its extension elements:
 * what BPMN has no word for — the role a lane stands for.
 */
abstract class AbstractElement
{
    /** @var array<string, string> */
    private array $properties = [];

    private ?string $documentation = null;

    public function __construct(
        private readonly string $id,
        private ?string $name = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * The name, or the id where it has none: what a list shows.
     */
    public function getLabel(): string
    {
        return $this->name ?? $this->id;
    }

    public function getDocumentation(): ?string
    {
        return $this->documentation;
    }

    public function setDocumentation(?string $documentation): static
    {
        $this->documentation = $documentation;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getProperty(string $name, ?string $default = null): ?string
    {
        return $this->properties[$name] ?? $default;
    }

    public function setProperty(string $name, ?string $value): static
    {
        if ($value === null) {
            unset($this->properties[$name]);
        } else {
            $this->properties[$name] = $value;
        }

        return $this;
    }
}
