<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * Several pools and the messages between them.
 */
class Collaboration extends AbstractElement
{
    /** @var list<Participant> */
    private array $participants = [];

    /** @var list<MessageFlow> */
    private array $messageFlows = [];

    /**
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    public function addParticipant(Participant $participant): Participant
    {
        $this->participants[] = $participant;

        return $participant;
    }

    /**
     * @return list<MessageFlow>
     */
    public function getMessageFlows(): array
    {
        return $this->messageFlows;
    }

    public function addMessageFlow(MessageFlow $flow): MessageFlow
    {
        $this->messageFlows[] = $flow;

        return $flow;
    }
}
