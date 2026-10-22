<?php

namespace Wexample\PhpBpmn\Enum;

/**
 * What an event is about — its `*EventDefinition` element. No definition: a
 * plain start or end.
 */
enum EventDefinitionType: string
{
    case MESSAGE = 'messageEventDefinition';
    case TIMER = 'timerEventDefinition';
    case SIGNAL = 'signalEventDefinition';
    case ERROR = 'errorEventDefinition';
    case ESCALATION = 'escalationEventDefinition';
    case CONDITIONAL = 'conditionalEventDefinition';
    case TERMINATE = 'terminateEventDefinition';
    case LINK = 'linkEventDefinition';
    case COMPENSATE = 'compensateEventDefinition';
    case CANCEL = 'cancelEventDefinition';
}
