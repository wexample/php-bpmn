<?php

namespace Wexample\PhpBpmn\Enum;

enum GatewayType: string
{
    /** One way out, the first whose condition holds — or the default. */
    case EXCLUSIVE = 'exclusiveGateway';

    /** Every way out at once; on the way in, waits for all. */
    case PARALLEL = 'parallelGateway';

    /** Every way out whose condition holds. */
    case INCLUSIVE = 'inclusiveGateway';

    /** The way of the first event to happen. */
    case EVENT_BASED = 'eventBasedGateway';

    case COMPLEX = 'complexGateway';
}
