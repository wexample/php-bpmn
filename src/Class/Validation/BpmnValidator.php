<?php

namespace Wexample\PhpBpmn\Class\Validation;

use Wexample\PhpBpmn\Class\Element\AbstractFlowNode;
use Wexample\PhpBpmn\Class\Element\CallActivity;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\FlowContainer;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Process;
use Wexample\PhpBpmn\Class\Element\SubProcess;
use Wexample\PhpBpmn\Class\Element\Task;
use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\EventPosition;
use Wexample\PhpBpmn\Enum\GatewayType;
use Wexample\PhpBpmn\Enum\IssueSeverity;
use Wexample\PhpBpmn\Enum\TaskType;
use Wexample\PhpBpmn\Helper\Iso8601Helper;

/**
 * Checks a model: errors where it is not sound — a flow to nothing, a node
 * no start reaches, a split with no way to choose —, warnings where it uses
 * BPMN beyond what is run here (the executable subset), or where it likely
 * says something else than meant.
 *
 * The executable subset: tasks of every type, call activities, sub-processes;
 * exclusive, parallel and event-based gateways; start and end events, plain,
 * message, timer, signal or terminate; intermediate message, timer and signal
 * events; interrupting timer and message boundary events.
 */
class BpmnValidator
{
    private ValidationReport $report;

    private string $processId = '';

    public function validate(Definitions $definitions): ValidationReport
    {
        $this->report = new ValidationReport();

        if (! $definitions->getProcesses()) {
            $this->issue(IssueSeverity::ERROR, 'no_process', null, 'The document holds no process.');
        }

        foreach ($definitions->getUnmodelled() as [$element, $id]) {
            $this->issue(IssueSeverity::WARNING, 'unmodelled_element', $id, sprintf('A %s is read but not modelled here: it is dropped when written back.', $element));
        }

        foreach ($definitions->getProcesses() as $process) {
            $this->processId = $process->getId();
            $this->validateProcess($process, $definitions);
        }

        $this->processId = '';
        $this->validateCollaborations($definitions);

        return $this->report;
    }

    private function validateProcess(Process $process, Definitions $definitions): void
    {
        $flow = $process->getFlow();
        $this->validateFlow($flow, $definitions);

        // Lanes: each node in one lane at most, every lane naming existing nodes.
        $seen = [];

        foreach ($process->getAllLanes() as $lane) {
            if ($lane->getChildren()) {
                continue;
            }

            foreach ($lane->getNodeIds() as $nodeId) {
                if (! $flow->hasNode($nodeId)) {
                    $this->issue(IssueSeverity::ERROR, 'lane_unknown_node', $lane->getId(), sprintf('The lane holds "%s", which is no node of the process.', $nodeId));
                } elseif (isset($seen[$nodeId])) {
                    $this->issue(IssueSeverity::ERROR, 'node_in_two_lanes', $nodeId, sprintf('In lanes "%s" and "%s".', $seen[$nodeId], $lane->getId()));
                }

                $seen[$nodeId] = $lane->getId();
            }
        }

        if ($process->getLanes()) {
            foreach ($flow->getNodes() as $node) {
                if ($process->getLaneOf($node->getId()) === null) {
                    $this->issue(IssueSeverity::WARNING, 'node_without_lane', $node->getId(), 'The process has lanes, and nobody is said to do this.');
                }
            }
        }
    }

    private function validateFlow(FlowContainer $flow, Definitions $definitions): void
    {
        $nodes = $flow->getNodes();
        $starts = array_filter($nodes, static fn (AbstractFlowNode $node) => $node instanceof Event && $node->getPosition() === EventPosition::START);
        $ends = array_filter($nodes, static fn (AbstractFlowNode $node) => $node instanceof Event && $node->getPosition() === EventPosition::END);

        if (! $starts) {
            $this->issue(IssueSeverity::ERROR, 'no_start_event', null, 'Nothing starts it: it has no start event.');
        }

        if (! $ends) {
            $this->issue(IssueSeverity::WARNING, 'no_end_event', null, 'Nothing ends it explicitly: it has no end event.');
        }

        foreach ($flow->getFlows() as $sequenceFlow) {
            foreach ([$sequenceFlow->getSource(), $sequenceFlow->getTarget()] as $end) {
                if (! $flow->hasNode($end)) {
                    $this->issue(IssueSeverity::ERROR, 'flow_unknown_node', $sequenceFlow->getId(), sprintf('It leads from or to "%s", which is no node here.', $end));
                }
            }
        }

        foreach ($nodes as $node) {
            $this->validateNode($node, $flow, $definitions);
        }

        // Every node reached from a start, boundary events through their activity.
        $reached = [];
        $pending = array_keys($starts);

        while ($pending) {
            $id = array_pop($pending);

            if (isset($reached[$id])) {
                continue;
            }

            $reached[$id] = true;

            foreach ($flow->getOutgoing($id) as $outgoing) {
                $pending[] = $outgoing->getTarget();
            }

            foreach ($flow->getBoundaryEvents($id) as $boundary) {
                $pending[] = $boundary->getId();
            }
        }

        if ($starts) {
            foreach ($nodes as $id => $node) {
                if (! isset($reached[$id])) {
                    $this->issue(IssueSeverity::ERROR, 'unreachable_node', $id, 'No start leads to it.');
                }
            }
        }
    }

    private function validateNode(AbstractFlowNode $node, FlowContainer $flow, Definitions $definitions): void
    {
        $id = $node->getId();
        $incoming = $flow->getIncoming($id);
        $outgoing = $flow->getOutgoing($id);

        if ($node instanceof Event) {
            $this->validateEvent($node, $flow, $definitions, $incoming, $outgoing);

            return;
        }

        if (! $incoming) {
            $this->issue(IssueSeverity::ERROR, 'no_incoming_flow', $id, 'Nothing leads to it.');
        }

        if (! $outgoing) {
            $this->issue(IssueSeverity::ERROR, 'no_outgoing_flow', $id, 'Nothing leaves it: end the flow with an end event.');
        }

        if ($node instanceof Gateway) {
            $this->validateGateway($node, $flow, $outgoing);
        } elseif ($node instanceof CallActivity) {
            if ($node->getCalledElement() === null) {
                $this->issue(IssueSeverity::ERROR, 'call_without_process', $id, 'It calls no process.');
            } elseif ($definitions->getProcess($node->getCalledElement()) === null) {
                $this->issue(IssueSeverity::WARNING, 'call_external_process', $id, sprintf('It calls "%s", which is not in this document.', $node->getCalledElement()));
            }
        } elseif ($node instanceof SubProcess) {
            $this->validateFlow($node->getFlow(), $definitions);
        } elseif ($node instanceof Task && in_array($node->getType(), [TaskType::SEND, TaskType::RECEIVE], true)
            && $node->getMessageRef() !== null && $definitions->getReference($node->getMessageRef()) === null) {
            $this->issue(IssueSeverity::ERROR, 'unknown_message', $id, sprintf('Its message "%s" is not declared.', $node->getMessageRef()));
        }

        // Conditions straight out of an activity: BPMN allows them, they are
        // easy to misread — a gateway says it plainly.
        if (! $node instanceof Gateway) {
            foreach ($outgoing as $flowOut) {
                if ($flowOut->getCondition() !== null) {
                    $this->issue(IssueSeverity::WARNING, 'conditional_flow_out_of_activity', $flowOut->getId(), 'A condition straight out of an activity: an exclusive gateway says it plainly, and is what is run here.');
                }
            }
        }
    }

    /**
     * @param list<\Wexample\PhpBpmn\Class\Element\SequenceFlow> $incoming
     * @param list<\Wexample\PhpBpmn\Class\Element\SequenceFlow> $outgoing
     */
    private function validateEvent(Event $event, FlowContainer $flow, Definitions $definitions, array $incoming, array $outgoing): void
    {
        $id = $event->getId();
        $position = $event->getPosition();

        if ($position === EventPosition::START && $incoming) {
            $this->issue(IssueSeverity::ERROR, 'start_with_incoming_flow', $id, 'A start event is led to by nothing.');
        }

        if ($position === EventPosition::END && $outgoing) {
            $this->issue(IssueSeverity::ERROR, 'end_with_outgoing_flow', $id, 'An end event leads nowhere.');
        }

        if ($position !== EventPosition::END && ! $outgoing) {
            $this->issue(IssueSeverity::ERROR, 'no_outgoing_flow', $id, 'Nothing leaves it: end the flow with an end event.');
        }

        if (in_array($position, [EventPosition::INTERMEDIATE_CATCH, EventPosition::INTERMEDIATE_THROW, EventPosition::END], true) && ! $incoming) {
            $this->issue(IssueSeverity::ERROR, 'no_incoming_flow', $id, 'Nothing leads to it.');
        }

        if ($position === EventPosition::BOUNDARY) {
            $activity = $event->getAttachedTo() ? $flow->getNode($event->getAttachedTo()) : null;

            if ($activity === null || $activity instanceof Event || $activity instanceof Gateway) {
                $this->issue(IssueSeverity::ERROR, 'boundary_without_activity', $id, 'It is attached to no activity.');
            }

            if ($incoming) {
                $this->issue(IssueSeverity::ERROR, 'boundary_with_incoming_flow', $id, 'A boundary event is reached through its activity, never by a flow.');
            }

            if (! $event->isCancelActivity()) {
                $this->issue(IssueSeverity::WARNING, 'non_interrupting_boundary', $id, 'A non-interrupting boundary event is not run here: the activity would go on beside it.');
            }
        }

        if ($position === EventPosition::INTERMEDIATE_CATCH && ! $event->getDefinitions()) {
            $this->issue(IssueSeverity::ERROR, 'catch_without_definition', $id, 'It waits for nothing: give it a message, a timer, a signal.');
        }

        foreach ($event->getDefinitions() as $definition) {
            $type = $definition->getType();

            if ($type === EventDefinitionType::TIMER) {
                if ($definition->getTimerKind() === null || $definition->getExpression() === null) {
                    $this->issue(IssueSeverity::ERROR, 'timer_without_expression', $id, 'Its timer says no duration, date or cycle.');
                } elseif (! Iso8601Helper::isValid($definition->getTimerKind(), $definition->getExpression())) {
                    $this->issue(IssueSeverity::ERROR, 'timer_invalid', $id, sprintf('"%s" is no ISO 8601 %s.', $definition->getExpression(), $definition->getTimerKind()->value));
                }
            }

            if ($definition->getRef() !== null
                && in_array($type, [EventDefinitionType::MESSAGE, EventDefinitionType::SIGNAL, EventDefinitionType::ERROR, EventDefinitionType::ESCALATION], true)
                && $definitions->getReference($definition->getRef()) === null) {
                $this->issue(IssueSeverity::ERROR, 'unknown_reference', $id, sprintf('It refers to "%s", which is not declared.', $definition->getRef()));
            }

            $supported = match ($position) {
                EventPosition::START, EventPosition::END => in_array($type, [EventDefinitionType::MESSAGE, EventDefinitionType::TIMER, EventDefinitionType::SIGNAL, EventDefinitionType::TERMINATE], true),
                EventPosition::INTERMEDIATE_CATCH, EventPosition::INTERMEDIATE_THROW => in_array($type, [EventDefinitionType::MESSAGE, EventDefinitionType::TIMER, EventDefinitionType::SIGNAL], true),
                EventPosition::BOUNDARY => in_array($type, [EventDefinitionType::MESSAGE, EventDefinitionType::TIMER], true),
            };

            if (! $supported) {
                $this->issue(IssueSeverity::WARNING, 'unsupported_event', $id, sprintf('A %s %s is drawn, not run here.', str_replace('EventDefinition', '', $type->value), $position->value));
            }
        }
    }

    /**
     * @param list<\Wexample\PhpBpmn\Class\Element\SequenceFlow> $outgoing
     */
    private function validateGateway(Gateway $gateway, FlowContainer $flow, array $outgoing): void
    {
        $id = $gateway->getId();
        $type = $gateway->getType();

        if (in_array($type, [GatewayType::INCLUSIVE, GatewayType::COMPLEX], true)) {
            $this->issue(IssueSeverity::WARNING, 'unsupported_gateway', $id, sprintf('An %s is drawn, not run here: use exclusive and parallel gateways.', $type->value));
        }

        if ($gateway->getDefaultFlow() !== null && ! in_array($gateway->getDefaultFlow(), array_map(static fn ($f) => $f->getId(), $outgoing), true)) {
            $this->issue(IssueSeverity::ERROR, 'default_not_outgoing', $id, sprintf('Its default flow "%s" does not leave it.', $gateway->getDefaultFlow()));
        }

        if (count($outgoing) > 1 && in_array($type, [GatewayType::EXCLUSIVE, GatewayType::INCLUSIVE], true)) {
            foreach ($outgoing as $flowOut) {
                if ($flowOut->getCondition() === null && $flowOut->getId() !== $gateway->getDefaultFlow()) {
                    $this->issue(IssueSeverity::ERROR, 'split_without_condition', $flowOut->getId(), 'A way out of a choice with no condition, and not the default: there is no telling when it is taken.');
                }
            }
        }

        if ($type === GatewayType::PARALLEL) {
            foreach ($outgoing as $flowOut) {
                if ($flowOut->getCondition() !== null) {
                    $this->issue(IssueSeverity::WARNING, 'condition_out_of_parallel', $flowOut->getId(), 'Every way out of a parallel gateway is taken: its condition is ignored.');
                }
            }
        }

        if ($type === GatewayType::EVENT_BASED) {
            foreach ($outgoing as $flowOut) {
                $target = $flow->getNode($flowOut->getTarget());
                $waits = ($target instanceof Event && $target->getPosition() === EventPosition::INTERMEDIATE_CATCH)
                    || ($target instanceof Task && $target->getType() === TaskType::RECEIVE);

                if (! $waits) {
                    $this->issue(IssueSeverity::ERROR, 'event_gateway_target', $flowOut->getId(), 'Out of an event-based gateway, each way leads to an event waited for, or a receive task.');
                }
            }
        }

        if (count($flow->getIncoming($id)) > 1 && count($outgoing) > 1) {
            $this->issue(IssueSeverity::WARNING, 'gateway_joins_and_splits', $id, 'One gateway both joins and splits: two say it more plainly.');
        }
    }

    private function validateCollaborations(Definitions $definitions): void
    {
        foreach ($definitions->getCollaborations() as $collaboration) {
            $owners = [];

            foreach ($collaboration->getParticipants() as $participant) {
                $owners[$participant->getId()] = $participant->getId();

                if ($participant->getProcessRef() !== null) {
                    $process = $definitions->getProcess($participant->getProcessRef());

                    if ($process === null) {
                        $this->issue(IssueSeverity::ERROR, 'participant_unknown_process', $participant->getId(), sprintf('Its process "%s" is not in the document.', $participant->getProcessRef()));

                        continue;
                    }

                    foreach (array_keys($process->getFlow()->getNodes()) as $nodeId) {
                        $owners[$nodeId] = $participant->getId();
                    }
                }
            }

            foreach ($collaboration->getMessageFlows() as $messageFlow) {
                $source = $owners[$messageFlow->getSource()] ?? null;
                $target = $owners[$messageFlow->getTarget()] ?? null;

                if ($source === null || $target === null) {
                    $this->issue(IssueSeverity::ERROR, 'message_flow_unknown_end', $messageFlow->getId(), 'It leads from or to nothing of the collaboration.');
                } elseif ($source === $target) {
                    $this->issue(IssueSeverity::ERROR, 'message_flow_inside_pool', $messageFlow->getId(), 'A message flow goes from one pool to another, never inside one.');
                }
            }
        }
    }

    private function issue(IssueSeverity $severity, string $code, ?string $elementId, string $message): void
    {
        $this->report->add(new ValidationIssue($severity, $code, $elementId, $message, $this->processId ?: null));
    }
}
