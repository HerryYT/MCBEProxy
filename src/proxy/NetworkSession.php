<?php


namespace proxy;


use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\BinaryStream;
use raklib\protocol\ACK;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;
use raklib\protocol\PacketSerializer;
use raklib\protocol\SplitPacketInfo;

abstract class NetworkSession
{
    public const MAX_MTU_SIZE = 1500;

    protected array $inputSequenceNumbers = [];
    protected array $nackSequenceNumbers = [];
    protected int $outputSequenceNumber = 0;
    protected int $outputReliableIndex = 0;
    protected array $outputOrderingIndexes = [];
    protected array $outputBackupQueue = [];
    protected int $outputSequenceIndex = 0;
    protected int $splitID = 0;
    protected array $splits = [];

    protected int $mtuSize = self::MAX_MTU_SIZE;

    // Dktapps facepalms :), like mojang ones
    // this shi just for some stupid items
    private PacketSerializerContext $serializerContext;

    public function __construct()
    {
        $this->outputOrderingIndexes = array_fill(0, 32, 0);
        $this->serializerContext = new PacketSerializerContext(new ItemTypeDictionary([
            new ItemTypeEntry('minecraft:shield', 358, false)
        ]));
    }

    /**
     * Tick is for RakNet stuff, while a process is for session things.
     *
     * @param float $currentTime
     */
    public function tick(float $currentTime): void {
        if (count($this->inputSequenceNumbers) > 0) {
            $ack = new ACK();
            $ack->packets = $this->inputSequenceNumbers;

            $this->sendBuffer(ProxyServer::encodePacket($ack));
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
        $ack = new ACK();
        $ack->decode(new PacketSerializer($buffer));
        foreach ($ack->packets as $seq) {
            if (isset($this->outputBackupQueue[$seq])) {
                unset($this->outputBackupQueue[$seq]);
            }
        }
    }

    private function handleNacknowledgement(string $buffer) {
        $nack = new NACK();
        $nack->decode(new PacketSerializer($buffer));
        foreach ($nack->packets as $seq) {
            if (isset($this->outputBackupQueue[$seq])) {
                $this->sendBuffer($this->outputBackupQueue[$seq]->getBuffer());
                unset($this->outputBackupQueue[$seq]);
            }
        }

        // Delete older backups from the queue
        // $min = min($nack->packets);
        // foreach ($this->outputBackupQueue as $valu)
    }

    private function handleConnectedDatagram(string $buffer): void {
        $datagram = new Datagram();
        $datagram->decode(new PacketSerializer($buffer));

        $this->inputSequenceNumbers[] = $datagram->seqNumber;

        if (in_array($datagram->seqNumber, $this->nackSequenceNumbers)) {
            $index = array_search($datagram->seqNumber, $this->nackSequenceNumbers);
            unset($this->nackSequenceNumbers[$index]);
        }

        // TODO: missing packets

        foreach ($datagram->packets as $packet) {
            if ($packet->splitInfo !== null) {
                $this->handleSplit($packet);
            } else {
                $this->handleEncapsulated($packet);
            }
        }
    }

    protected function handleSplit(EncapsulatedPacket $packet): void {
        $splitInfo = $packet->splitInfo;
        $this->splits[$splitInfo->getId()][$splitInfo->getPartIndex()] = $packet;
        if ($splitInfo->getTotalPartCount() == count($this->splits[$splitInfo->getId()])) {
            $buffer = "";
            for ($i = 0; $i < count($this->splits[$splitInfo->getId()]); $i++) {
                $split = $this->splits[$splitInfo->getId()][$i];
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
            unset($this->splits[$splitInfo->getId()]);
            $this->handleEncapsulated($encapsulated);
        }
    }

    public function sendEncapsulatedBuffer(string $buffer, int $reliability = PacketReliability::UNRELIABLE): void {
        $encapsulated = new EncapsulatedPacket();
        $encapsulated->reliability = $reliability;
        $encapsulated->buffer = $buffer;
        $encapsulated->orderChannel = 0;   // hack

        if (PacketReliability::isOrdered($reliability)) {
            // TODO: capire il perche'
            if (!isset($this->outputOrderingIndexes[$encapsulated->orderChannel])) {
                $this->outputOrderingIndexes[$encapsulated->orderChannel] = 0;
            }
            $encapsulated->orderIndex = $this->outputOrderingIndexes[$encapsulated->orderChannel]++;
        } else if (PacketReliability::isSequenced($reliability)) {
            $encapsulated->orderIndex = $this->outputOrderingIndexes[$encapsulated->orderChannel];
            $encapsulated->sequenceIndex = $this->outputSequenceIndex++;
        }

        $maxSize = $this->mtuSize - 36;
        if (strlen($encapsulated->buffer) + 4 > $maxSize) {
            $buffers = str_split($encapsulated->buffer, $maxSize);
            assert($buffers !== false);
            $bufferCount = count($buffers);

            $splitID = ++$this->splitID % 65536;
            foreach($buffers as $count => $buffer){
                $pk = new EncapsulatedPacket();
                $pk->splitInfo = new SplitPacketInfo(
                    $splitID,
                    $count,
                    $bufferCount
                );
                $pk->reliability = $encapsulated->reliability;
                $pk->buffer = $buffer;

                if (PacketReliability::isReliable($pk->reliability)) {
                    $pk->messageIndex = $this->outputReliableIndex++;
                }

                $pk->sequenceIndex = $encapsulated->sequenceIndex;
                $pk->orderChannel = $encapsulated->orderChannel;
                $pk->orderIndex = $encapsulated->orderIndex;

                $this->sendEncapsulated($pk);
            }
        } else {
            if(PacketReliability::isReliable($encapsulated->reliability)){
                $encapsulated->messageIndex = $this->outputReliableIndex++;
            }
            $this->sendEncapsulated($encapsulated);
        }
    }

    private function sendEncapsulated(EncapsulatedPacket $encapsulated): void {
        $packet = new Datagram();
        $packet->packets = [$encapsulated];
        $packet->seqNumber = $this->outputSequenceNumber++;
        $this->outputBackupQueue[$packet->seqNumber] = $packet;
        $this->sendBuffer(ProxyServer::encodePacket($packet));
    }

    // Not related to RakNet but meh... we don't like duplicates
    public function sendDataPacket(DataPacket $packet, bool $comp = true): void {
        $stream = new BinaryStream();
        PacketBatch::encodePackets($stream, $this->serializerContext, [$packet]);
        if ($comp) {
            $buffer = zlib_encode($stream->getBuffer(), ZLIB_ENCODING_RAW, 7);
        } else {
            $buffer = $stream->getBuffer();
        }
        $this->sendEncapsulatedBuffer("\xfe" . $buffer, PacketReliability::RELIABLE_ORDERED);
    }

    abstract function handleEncapsulated(EncapsulatedPacket $packet): void;

    abstract function sendBuffer(string $buffer): void;
}