<?php


namespace proxy;


use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;

abstract class NetworkSession
{
    public const MAX_MTU_SIZE = 1500;

    protected array $inputSequenceNumbers = [];
    protected array $nackSequenceNumbers = [];
    protected int $outputSequenceNumber = 0;
    protected int $outputReliableIndex = 0;
    protected array $outputOrderingIndexes = [];
    protected array $outputBackupQueue = [];
    protected int $splitID = 0;
    protected array $splits = [];

    protected int $mtuSize = self::MAX_MTU_SIZE;

    /**
     * Tick is for RakNet stuff, while process is for session things.
     *
     * @param float $currentTime
     */
    public function tick(float $currentTime): void {
        if (count($this->inputSequenceNumbers) > 0) {
            $ack = new ACK();
            $ack->packets = $this->inputSequenceNumbers;
            $ack->encode();
            $this->sendBuffer($ack->getBuffer());
            $this->inputSequenceNumbers = [];
        }

        $this->process($currentTime);
    }

    abstract function process(float $currentTime): void;

    public function handle(string $buffer): void {
        if (!$this->handleDatagram($buffer)) {
            $pid = ord($buffer[0]);
            if ($pid & Datagram::BITFLAG_ACK) {
                $this->handleAcknowledgement($buffer);
            } elseif ($pid & Datagram::BITFLAG_NAK) {
                $this->handleNacknowledgement($buffer);
            } else {
                $this->handleConnectedDatagram($buffer);
            }
        }
    }

    abstract function handleDatagram(string $buffer): bool;

    private function handleAcknowledgement(string $buffer) {
        $ack = new ACK($buffer);
        $ack->decode();
        foreach ($ack->packets as $seq) {
            if (isset($this->outputBackupQueue[$seq])) {
                unset($this->outputBackupQueue[$seq]);
            }
        }
    }

    private function handleNacknowledgement(string $buffer) {
        $nack = new NACK($buffer);
        $nack->decode();
        foreach ($nack->packets as $seq) {
            if (isset($this->outputBackupQueue[$seq])) {
                $this->sendBuffer($this->outputBackupQueue[$seq]->getBuffer());
                unset($this->outputBackupQueue[$seq]);
            }
        }
    }

    private function handleConnectedDatagram(string $buffer): void {
        $datagram = new Datagram($buffer);
        $datagram->decode();

        // Just because yes :)
        if ($datagram->seqNumber == null) return;

        if (in_array($datagram->seqNumber, $this->inputSequenceNumbers)) {
            return;
        }
        $this->inputSequenceNumbers[] = $datagram->seqNumber;

        if (in_array($datagram->seqNumber, $this->nackSequenceNumbers)) {
            $index = array_search($datagram->seqNumber, $this->nackSequenceNumbers);
            unset($this->nackSequenceNumbers[$index]);
        }

        // TODO: missing packets

        foreach ($datagram->packets as $packet) {
            if ($packet->hasSplit) {
                $this->handleSplit($packet);
            } else {
                $this->handleEncapsulated($packet);
            }
        }
    }

    protected function handleSplit(EncapsulatedPacket $packet): void {
        $this->splits[$packet->splitID][$packet->splitIndex] = $packet;
        if ($packet->splitCount == count($this->splits[$packet->splitID])) {
            $buffer = "";
            for ($i = 0; $i < count($this->splits[$packet->splitID]); $i++) {
                $split = $this->splits[$packet->splitID][$i];
                $buffer .= $split->buffer;
            }

            $reliability = $packet->reliability;
            $encapsulated = new EncapsulatedPacket();
            $encapsulated->buffer = $buffer;
            $encapsulated->reliability = $reliability;
            if (PacketReliability::isOrdered($reliability)) {
                $encapsulated->orderIndex = $packet->orderIndex;
                $encapsulated->orderChannel = $packet->orderChannel;
            }
            unset($this->splits[$packet->splitID]);
            $this->handleEncapsulated($encapsulated);
        }
    }

    public function sendEncapsulatedBuffer(string $buffer, int $reliability = PacketReliability::UNRELIABLE): void {
        $encapsulated = new EncapsulatedPacket();
        $encapsulated->reliability = $reliability;
        $encapsulated->buffer = $buffer;
        $encapsulated->orderChannel = 0;   // hack
        if (PacketReliability::isReliable($reliability)) {
            $encapsulated->messageIndex = $this->outputReliableIndex++;
            if (PacketReliability::isOrdered($reliability)) {
                if (!isset($this->outputOrderingIndexes[$encapsulated->orderChannel])) {
                    $this->outputOrderingIndexes[$encapsulated->orderChannel] = 0;
                }
                $encapsulated->orderIndex = $this->outputOrderingIndexes[$encapsulated->orderChannel]++;
            }
        }

        $maxSize = $this->mtuSize - 60;
        if (strlen($encapsulated->buffer) > $maxSize) {
            $buffers = str_split($encapsulated->buffer, $maxSize);
            assert($buffers !== false);
            $bufferCount = count($buffers);

            $splitID = ++$this->splitID % 65536;
            foreach($buffers as $count => $buffer){
                $pk = new EncapsulatedPacket();
                $pk->splitID = $splitID;
                $pk->hasSplit = true;
                $pk->splitCount = $bufferCount;
                $pk->reliability = $encapsulated->reliability;
                $pk->splitIndex = $count;
                $pk->buffer = $buffer;

                if(PacketReliability::isReliable($pk->reliability)){
                    $pk->messageIndex = $this->outputReliableIndex++;
                }

                $pk->sequenceIndex = $encapsulated->sequenceIndex;
                $pk->orderChannel = $encapsulated->orderChannel;
                $pk->orderIndex = $encapsulated->orderIndex;
                $this->sendEncapsulated($pk);
            }
            return;
        }
        $this->sendEncapsulated($encapsulated);
    }

    private function sendEncapsulated(EncapsulatedPacket $encapsulated): void {
        $packet = new Datagram();
        $packet->packets = [$encapsulated];
        $packet->seqNumber = $this->outputSequenceNumber++;
        $packet->encode();
        $this->outputBackupQueue[$packet->seqNumber] = $packet;
        $this->sendBuffer($packet->getBuffer());
    }

    // Not related to RakNet but meh... we don't like duplicates
    public function sendDataPacket(DataPacket $packet): void {
        $batch = new BatchPacket();
        $batch->addPacket($packet);
        $batch->encode();
        $this->sendEncapsulatedBuffer($batch->getBuffer(), PacketReliability::RELIABLE_ORDERED);
    }

    abstract function handleEncapsulated(EncapsulatedPacket $packet): void;

    abstract function sendBuffer(string $buffer): void;
}