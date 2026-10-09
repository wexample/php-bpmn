<?php

namespace Wexample\PhpBpmn\Enum;

/**
 * Who, or what, does a task — its BPMN element, `userTask`, `serviceTask`…
 */
enum TaskType: string
{
    /** Undefined: somebody does it, the model does not say how. */
    case TASK = 'task';

    /** A person, through the application. */
    case USER = 'userTask';

    /** A person, outside of any application. */
    case MANUAL = 'manualTask';

    /** The application, on its own. */
    case SERVICE = 'serviceTask';

    /** A message sent: a mail, a notification. */
    case SEND = 'sendTask';

    /** A message waited for. */
    case RECEIVE = 'receiveTask';

    case SCRIPT = 'scriptTask';

    case BUSINESS_RULE = 'businessRuleTask';

    public function isAutomatic(): bool
    {
        return in_array($this, [self::SERVICE, self::SEND, self::SCRIPT, self::BUSINESS_RULE], true);
    }

    public function isHuman(): bool
    {
        return in_array($this, [self::TASK, self::USER, self::MANUAL], true);
    }
}
