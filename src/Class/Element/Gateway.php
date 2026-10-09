<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Enum\GatewayType;

/**
 * Where the flow splits or joins.
 */
class Gateway extends AbstractFlowNode
{
    /** The flow taken when no condition of the others holds. */
    private ?string $defaultFlow = null;

    public function __construct(
        string $id,
        ?string $name = null,
        private GatewayType $type = GatewayType::EXCLUSIVE,
    ) {
        parent::__construct($id, $name);
    }

    public function getType(): GatewayType
    {
        return $this->type;
    }

    public function getDefaultFlow(): ?string
    {
        return $this->defaultFlow;
    }

    public function setDefaultFlow(?string $flowId): static
    {
        $this->defaultFlow = $flowId;

        return $this;
    }

    public function getElementName(): string
    {
        return $this->type->value;
    }
}
