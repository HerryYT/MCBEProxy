<?php


namespace proxy;


use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectionRequest;
use raklib\protocol\ConnectionRequestAccepted;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NewIncomingConnection;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPong;
use raklib\server\UDPServerSocket;
use raklib\utils\InternetAddress;

class ServerSession extends NetworkSession
{
    private ProxyServer $proxyServer;
    private ConnectedClientHandler $connectedClient;
    private ?ConnectedServerHandler $connectedServer = null;
    private InternetAddress $fakeClientAddress;
    // The "client" socket
    public UDPServerSocket $socket;
    public InternetAddress $targetAddress;

    public const STATUS_UNCONNECTED = 0;
    public const STATUS_CONNECTING = 1;
    public const STATUS_CONNECTED = 2;

    public float $lastPacketTime;

    private int $status = self::STATUS_UNCONNECTED;
    private int $clientID;

    public function __construct(ProxyServer $server, ConnectedClientHandler $connectedClient)
    {
        $this->proxyServer = $server;
        $this->connectedClient = $connectedClient;

        $this->clientID = mt_rand(0, PHP_INT_MAX);
        $clientRandomPort = mt_rand(50000, 65535);
        $this->fakeClientAddress = $bindAddress = new InternetAddress("0.0.0.0", $clientRandomPort, 4);
        $this->socket = new UDPServerSocket($bindAddress);

        $this->lastPacketTime = microtime(true);
    }

    public function readPacket(): void {
        if ($this->socket->readPacket($buffer, $senderIP, $senderPort)) {
            // Maybe we switched server and we're receiving packets from the old one
            // if ($senderIP != $this->targetAddress->getIp() && $senderPort != $this->targetAddress->getPort()) {
            //    return;
            // }

            $this->lastPacketTime = microtime(true);

            if (!$this->handleUnconnected($buffer)) {
                $this->handle($buffer);
            }
        }
    }

    public function process(float $currentTime): void {
        if ($this->status === self::STATUS_UNCONNECTED) {
            $this->tryUnconnectedPing();
        } elseif ($this->status === self::STATUS_CONNECTED) {
            // FIXME: send just after some time, don't spam
            $this->sendConnectedPing();
        }

        // Timeout ping
        if ($currentTime - $this->lastPacketTime >= 5) {
            $this->getConnectedClient()->cancelConnection();
        }
    }

    public function handleDatagram(string $buffer): bool {
        $pid = ord($buffer[0]);
        if ($pid == MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_1) {
            $this->handleOpenConnectionReplyOne($buffer);
            return true;
        } elseif ($pid == MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_2) {
            $this->handleOpenConnectionReplyTwo($buffer);
            return true;
        }
        return false;
    }

    public function handleEncapsulated(EncapsulatedPacket $packet): void
    {
        $pid = ord($packet->buffer[0]);
        switch ($pid) {
            case MessageIdentifiers::ID_CONNECTION_REQUEST_ACCEPTED:
                $connAccepted = new ConnectionRequestAccepted($packet->buffer);
                $connAccepted->decode();

                $newIncomingConn = new NewIncomingConnection();
                $newIncomingConn->sendPingTime = $connAccepted->sendPingTime;
                $newIncomingConn->sendPongTime = time();
                $newIncomingConn->address = $this->fakeClientAddress;
                $newIncomingConn->encode();
                $this->sendEncapsulatedBuffer($newIncomingConn->getBuffer());

                $this->connectedServer = new ConnectedServerHandler($this);

                /** @var BatchPacket $login */
                $login = $this->getConnectedClient()->cachedPackets[ProtocolInfo::LOGIN_PACKET];
                $this->sendEncapsulatedBuffer($login->getBuffer());

                $this->status = self::STATUS_CONNECTED;
                break;
            case ProxyServer::MINECRAFT_HEADER:
                $this->connectedServer->handleMinecraft($packet);
                break;
        }
    }

    private function handleOpenConnectionReplyOne(string $buffer): void {
        $this->status = self::STATUS_CONNECTING;

        $replyOne = new OpenConnectionReply1($buffer);
        $replyOne->decode();

        $reqTwo = new OpenConnectionRequest2();
        $finalMtu = $replyOne->mtuSize;
        $reqTwo->mtuSize = $finalMtu;

        $this->mtuSize = $finalMtu;

        $reqTwo->clientID = $this->clientID;
        $reqTwo->serverAddress = $this->targetAddress;
        $reqTwo->encode();
        $this->sendBuffer($reqTwo->getBuffer());
    }

    private function handleOpenConnectionReplyTwo(string $buffer): void {
        $replyTwo = new OpenConnectionReply2($buffer);
        $replyTwo->decode();

        $connReq = new ConnectionRequest();
        $connReq->clientID = $this->clientID;
        $connReq->sendPingTime = time();
        $connReq->encode();
        $this->sendEncapsulatedBuffer($connReq->getBuffer());
    }

    public function handleUnconnected(string $buffer): bool {
        $pid = ord($buffer[0]);

        if ($pid == MessageIdentifiers::ID_UNCONNECTED_PONG) {
            $this->handleUnconnectedPong($buffer);
            return true;
        }
        return false;
    }

    private function handleUnconnectedPong(string $buffer): void {
        $unconnectedPong = new UnconnectedPong($buffer);
        $unconnectedPong->decode();

        $this->getConnectedClient()->sendMessage($unconnectedPong->serverName);

        $reqOne = new OpenConnectionRequest1();
        $reqOne->mtuSize = ($this->mtuSize -= 30) + 30;
        $reqOne->protocol = 10; // MC actual protocol
        $reqOne->encode();
        $this->sendBuffer($reqOne->getBuffer());
    }

    // Is the target server alive??
    public function tryUnconnectedPing(): void {
        $unconnectedPing = new UnconnectedPing();
        $unconnectedPing->pingID = time();
        $unconnectedPing->encode();
        $this->sendBuffer($unconnectedPing->getBuffer());
    }

    private function sendConnectedPing(): void {
        $connPing = new ConnectedPing();
        $connPing->sendPingTime = time();
        $connPing->encode();
        $this->sendEncapsulatedBuffer($connPing->getBuffer());
    }

    public function connect(InternetAddress $targetAddress): void {
        // clear queues and general data
        $this->inputSequenceNumbers = [];
        $this->nackSequenceNumbers = [];
        $this->outputSequenceNumber = 0;
        $this->outputReliableIndex = 0;
        $this->outputOrderingIndexes = [];
        $this->outputBackupQueue = [];
        $this->splitID = 0;
        $this->splits = [];
        
        $this->connectedServer = null;

        $this->targetAddress = $targetAddress;

        // clear entities mappings
        EntityMappings::clear();

        $this->lastPacketTime = microtime(true);
        $this->status = self::STATUS_UNCONNECTED;
    }

    public function getProxy(): ProxyServer {
        return $this->proxyServer;
    }

    public function getConnectedClient(): ConnectedClientHandler {
        return $this->connectedClient;
    }

    public function getConnectedServer(): ?ConnectedServerHandler {
        return $this->connectedServer;
    }

    public function isConnected(): bool {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function sendBuffer(string $buffer): void {
        $this->socket->writePacket($buffer, $this->targetAddress->getIp(), $this->targetAddress->getPort());
    }
}