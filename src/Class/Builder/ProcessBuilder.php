<?php

namespace Wexample\PhpBpmn\Class\Builder;

use Wexample\PhpBpmn\Class\Element\AbstractFlowNode;
use Wexample\PhpBpmn\Class\Element\CallActivity;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\EventDefinition;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Lane;
use Wexample\PhpBpmn\Class\Element\Process;
use Wexample\PhpBpmn\Class\Element\RootReference;
use Wexample\PhpBpmn\Class\Element\SequenceFlow;
use Wexample\PhpBpmn\Class\Element\Task;
use Wexample\PhpBpmn\Enum\EventPosition;
use Wexample\PhpBpmn\Enum\GatewayType;
use Wexample\PhpBpmn\Enum\TaskType;
use Wexample\PhpBpmn\Enum\TimerKind;
use Wexample\PhpBpmn\Exception\BpmnModelException;

/**
 * A process written in code. Each node follows the previous one unless told
 * otherwise; `from()` goes back to a node — a gateway — to start another
 * way out of it, under a condition:
 *
 *     ProcessBuilder::create('review', 'Article review')
 *         ->lane('author', 'Author')->lane('editor', 'Editor')
 *         ->startEvent('submitted', 'Article submitted', 'author')
 *         ->userTask('review', 'Review the article', 'editor')
 *         ->exclusiveGateway('verdict', 'Verdict?', 'editor')
 *         ->endEvent('published', 'Published', 'editor', condition: "variables['approved']")
 *         ->from('verdict', default: true)
 *         ->userTask('revise', 'Revise the article', 'author')
 *         ->connectTo('review')
 *         ->build();
 */
class ProcessBuilder
{
    private Definitions $definitions;

    private Process $process;

    /** @var array<string, Lane> */
    private array $lanes = [];

    private ?string $cursor = null;

    private ?string $pendingCondition = null;

    private bool $pendingDefault = false;

    private ?string $pendingName = null;

    public function __construct(string $processId, ?string $name = null, ?string $definitionsId = null)
    {
        $this->definitions = new Definitions($definitionsId ?? $processId . '_definitions');
        $this->process = $this->definitions->addProcess(new Process($processId, $name));
    }

    public static function create(string $processId, ?string $name = null): self
    {
        return new self($processId, $name);
    }

    /**
     * @param array<string, string> $properties what BPMN has no word for: `role`, `grant`
     */
    public function lane(string $id, ?string $name = null, array $properties = []): self
    {
        $lane = new Lane($id, $name);

        foreach ($properties as $key => $value) {
            $lane->setProperty($key, $value);
        }

        $this->lanes[$id] = $this->process->addLane($lane);

        return $this;
    }

    public function documentation(string $text): self
    {
        $this->process->setDocumentation($text);

        return $this;
    }

    public function message(string $id, ?string $name = null): self
    {
        $this->definitions->addReference(new RootReference($id, $name, RootReference::MESSAGE));

        return $this;
    }

    public function signal(string $id, ?string $name = null): self
    {
        $this->definitions->addReference(new RootReference($id, $name, RootReference::SIGNAL));

        return $this;
    }

    public function startEvent(string $id, ?string $name = null, ?string $lane = null, ?EventDefinition $definition = null): self
    {
        $event = new Event($id, $name, EventPosition::START);

        if ($definition) {
            $event->addDefinition($definition);
        }

        // A start follows nothing.
        $this->cursor = null;

        return $this->add($event, $lane);
    }

    public function endEvent(string $id, ?string $name = null, ?string $lane = null, ?EventDefinition $definition = null, ?string $condition = null): self
    {
        $event = new Event($id, $name, EventPosition::END);

        if ($definition) {
            $event->addDefinition($definition);
        }

        $this->when($condition);
        $this->add($event, $lane);
        $this->cursor = null;

        return $this;
    }

    public function task(string $id, ?string $name = null, ?string $lane = null, TaskType $type = TaskType::TASK, ?string $condition = null): self
    {
        $this->when($condition);

        return $this->add(new Task($id, $name, $type), $lane);
    }

    public function userTask(string $id, ?string $name = null, ?string $lane = null, ?string $condition = null): self
    {
        return $this->task($id, $name, $lane, TaskType::USER, $condition);
    }

    public function manualTask(string $id, ?string $name = null, ?string $lane = null, ?string $condition = null): self
    {
        return $this->task($id, $name, $lane, TaskType::MANUAL, $condition);
    }

    public function serviceTask(string $id, ?string $name = null, ?string $lane = null, ?string $condition = null): self
    {
        return $this->task($id, $name, $lane, TaskType::SERVICE, $condition);
    }

    public function sendTask(string $id, ?string $name = null, ?string $lane = null, ?string $messageRef = null, ?string $condition = null): self
    {
        $this->task($id, $name, $lane, TaskType::SEND, $condition);
        $this->lastTask()->setMessageRef($messageRef);

        return $this;
    }

    public function receiveTask(string $id, ?string $name = null, ?string $lane = null, ?string $messageRef = null, ?string $condition = null): self
    {
        $this->task($id, $name, $lane, TaskType::RECEIVE, $condition);
        $this->lastTask()->setMessageRef($messageRef);

        return $this;
    }

    public function callActivity(string $id, ?string $name, string $calledElement, ?string $lane = null, ?string $condition = null): self
    {
        $this->when($condition);

        return $this->add(new CallActivity($id, $name, $calledElement), $lane);
    }

    public function exclusiveGateway(string $id, ?string $name = null, ?string $lane = null, ?string $condition = null): self
    {
        $this->when($condition);

        return $this->add(new Gateway($id, $name, GatewayType::EXCLUSIVE), $lane);
    }

    public function parallelGateway(string $id, ?string $name = null, ?string $lane = null, ?string $condition = null): self
    {
        $this->when($condition);

        return $this->add(new Gateway($id, $name, GatewayType::PARALLEL), $lane);
    }

    public function eventBasedGateway(string $id, ?string $name = null, ?string $lane = null): self
    {
        return $this->add(new Gateway($id, $name, GatewayType::EVENT_BASED), $lane);
    }

    /**
     * Waits for a duration (`P1D`), a date, or a cycle.
     */
    public function timerEvent(string $id, ?string $name, string $expression, ?string $lane = null, TimerKind $kind = TimerKind::DURATION, ?string $condition = null): self
    {
        $this->when($condition);
        $event = new Event($id, $name, EventPosition::INTERMEDIATE_CATCH);
        $event->addDefinition(EventDefinition::timer($kind, $expression));

        return $this->add($event, $lane);
    }

    /**
     * Waits for a message.
     */
    public function messageCatchEvent(string $id, ?string $name, ?string $messageRef = null, ?string $lane = null, ?string $condition = null): self
    {
        $this->when($condition);
        $event = new Event($id, $name, EventPosition::INTERMEDIATE_CATCH);
        $event->addDefinition(EventDefinition::message($messageRef));

        return $this->add($event, $lane);
    }

    /**
     * Sends a message, and goes on.
     */
    public function messageThrowEvent(string $id, ?string $name, ?string $messageRef = null, ?string $lane = null, ?string $condition = null): self
    {
        $this->when($condition);
        $event = new Event($id, $name, EventPosition::INTERMEDIATE_THROW);
        $event->addDefinition(EventDefinition::message($messageRef));

        return $this->add($event, $lane);
    }

    /**
     * A timer on the edge of an activity: when it fires while the activity
     * runs, the flow leaves through it — the activity interrupted, or not.
     * What follows is built from it.
     */
    public function boundaryTimer(string $id, ?string $name, string $attachedTo, string $expression, bool $interrupting = true, TimerKind $kind = TimerKind::DURATION): self
    {
        $event = new Event($id, $name, EventPosition::BOUNDARY);
        $event->setAttachedTo($attachedTo, $interrupting);
        $event->addDefinition(EventDefinition::timer($kind, $expression));
        $this->process->getFlow()->addNode($event);
        $this->cursor = $id;

        return $this;
    }

    public function boundaryMessage(string $id, ?string $name, string $attachedTo, ?string $messageRef = null, bool $interrupting = true): self
    {
        $event = new Event($id, $name, EventPosition::BOUNDARY);
        $event->setAttachedTo($attachedTo, $interrupting);
        $event->addDefinition(EventDefinition::message($messageRef));
        $this->process->getFlow()->addNode($event);
        $this->cursor = $id;

        return $this;
    }

    /**
     * Goes back to a node, to build another way out of it: under a
     * condition, or as its gateway's default way.
     */
    public function from(string $nodeId, ?string $condition = null, bool $default = false, ?string $name = null): self
    {
        if (! $this->process->getFlow()->hasNode($nodeId)) {
            throw new BpmnModelException(sprintf('No node "%s" to go on from.', $nodeId));
        }

        $this->cursor = $nodeId;
        $this->pendingCondition = $condition;
        $this->pendingDefault = $default;
        $this->pendingName = $name;

        return $this;
    }

    /**
     * Joins the flow to a node built already — a loop back, a merge — and
     * leaves the cursor on it.
     */
    public function connectTo(string $nodeId, ?string $condition = null, ?string $name = null): self
    {
        if ($this->cursor === null) {
            throw new BpmnModelException(sprintf('Nothing to connect to "%s" from.', $nodeId));
        }

        $this->when($condition);
        $this->pendingName ??= $name;
        $this->connectCursor($nodeId);
        $this->cursor = $nodeId;

        return $this;
    }

    /**
     * A flow between two nodes, wherever the cursor stands.
     */
    public function connect(string $source, string $target, ?string $condition = null, bool $default = false, ?string $name = null): self
    {
        $flow = $this->process->getFlow()->addFlow(new SequenceFlow($this->flowId($source, $target), $source, $target, $condition, $name));

        if ($default) {
            $this->setDefault($source, $flow->getId());
        }

        return $this;
    }

    /**
     * A property of ours on a node, a lane or the process: `notification`,
     * `role`, `grant`.
     */
    public function property(string $elementId, string $name, string $value): self
    {
        $element = $this->process->getFlow()->getNode($elementId)
            ?? $this->process->getLane($elementId)
            ?? ($elementId === $this->process->getId() ? $this->process : null)
            ?? throw new BpmnModelException(sprintf('No element "%s".', $elementId));

        $element->setProperty($name, $value);

        return $this;
    }

    public function build(): Definitions
    {
        return $this->definitions;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    private function when(?string $condition): void
    {
        if ($condition !== null) {
            $this->pendingCondition = $condition;
        }
    }

    private function add(AbstractFlowNode $node, ?string $lane): self
    {
        $this->process->getFlow()->addNode($node);

        if ($lane !== null) {
            ($this->lanes[$lane] ?? throw new BpmnModelException(sprintf('No lane "%s": declare it first.', $lane)))->addNode($node->getId());
        }

        if ($this->cursor !== null) {
            $this->connectCursor($node->getId());
        }

        $this->cursor = $node->getId();

        return $this;
    }

    private function connectCursor(string $target): void
    {
        $this->connect($this->cursor, $target, $this->pendingCondition, $this->pendingDefault, $this->pendingName);
        $this->pendingCondition = null;
        $this->pendingDefault = false;
        $this->pendingName = null;
    }

    private function setDefault(string $gatewayId, string $flowId): void
    {
        $gateway = $this->process->getFlow()->getNode($gatewayId);

        if (! $gateway instanceof Gateway) {
            throw new BpmnModelException(sprintf('"%s" is no gateway: it has no default flow.', $gatewayId));
        }

        $gateway->setDefaultFlow($flowId);
    }

    private function flowId(string $source, string $target): string
    {
        $id = 'flow_' . $source . '_' . $target;
        $suffix = 2;

        while ($this->process->getFlow()->getFlow($id) !== null) {
            $id = 'flow_' . $source . '_' . $target . '_' . $suffix++;
        }

        return $id;
    }

    private function lastTask(): Task
    {
        $node = $this->process->getFlow()->getNode((string) $this->cursor);

        return $node instanceof Task ? $node : throw new BpmnModelException('No task to set a message on.');
    }
}
