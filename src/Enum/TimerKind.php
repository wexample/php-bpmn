<?php

namespace Wexample\PhpBpmn\Enum;

/**
 * How a timer is given, ISO 8601: a duration (`P3D`), a date
 * (`2026-12-31T09:00:00Z`), or a cycle (`R3/PT1H`).
 */
enum TimerKind: string
{
    case DURATION = 'timeDuration';
    case DATE = 'timeDate';
    case CYCLE = 'timeCycle';
}
