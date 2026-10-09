<?php

namespace Wexample\PhpBpmn\Enum;

/**
 * Where an event stands in its process — its BPMN element.
 */
enum EventPosition: string
{
    case START = 'startEvent';
    case END = 'endEvent';

    /** Waited for, in the flow. */
    case INTERMEDIATE_CATCH = 'intermediateCatchEvent';

    /** Raised, in the flow, which goes on at once. */
    case INTERMEDIATE_THROW = 'intermediateThrowEvent';

    /** On the edge of an activity: happening while it runs, it leaves by its own way. */
    case BOUNDARY = 'boundaryEvent';

    public function isCatching(): bool
    {
        return in_array($this, [self::START, self::INTERMEDIATE_CATCH, self::BOUNDARY], true);
    }
}
