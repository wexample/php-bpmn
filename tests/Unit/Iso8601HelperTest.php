<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Enum\TimerKind;
use Wexample\PhpBpmn\Helper\Iso8601Helper;

class Iso8601HelperTest extends TestCase
{
    public function testTimersAreReadAsIso8601(): void
    {
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::DURATION, 'P3D'));
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::DURATION, 'PT1H30M'));
        $this->assertFalse(Iso8601Helper::isValid(TimerKind::DURATION, '3 days'));
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::DATE, '2026-12-31T09:00:00Z'));
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::DATE, '2026-12-31T09:00:00+01:00'));
        $this->assertFalse(Iso8601Helper::isValid(TimerKind::DATE, 'tomorrow'));
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::CYCLE, 'R3/PT1H'));
        $this->assertTrue(Iso8601Helper::isValid(TimerKind::CYCLE, 'R/2026-01-01T00:00:00Z/P1M'));
        $this->assertFalse(Iso8601Helper::isValid(TimerKind::CYCLE, 'every hour'));
    }

    public function testATimerSaysWhenItFires(): void
    {
        $from = new DateTimeImmutable('2026-10-09T10:00:00+00:00');

        $this->assertSame('2026-10-12T10:00:00+00:00', Iso8601Helper::getFireDate(TimerKind::DURATION, 'P3D', $from)->format(DATE_ATOM));
        $this->assertSame('2026-10-09T11:00:00+00:00', Iso8601Helper::getFireDate(TimerKind::CYCLE, 'R3/PT1H', $from)->format(DATE_ATOM));
        $this->assertSame('2026-12-31T09:00:00+00:00', Iso8601Helper::getFireDate(TimerKind::DATE, '2026-12-31T09:00:00Z', $from)->format(DATE_ATOM));
    }
}
