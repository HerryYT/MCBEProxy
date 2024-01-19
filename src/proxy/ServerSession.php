<?php


namespace proxy;


use GlobalLogger;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
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
use raklib\protocol\PacketSerializer;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPong;
use raklib\server\ServerSocket;
use raklib\utils\InternetAddress;

class ServerSession extends NetworkSession
{
    private ProxyServer $proxyServer;
    private ConnectedClientHandler $connectedClient;
    private ?ConnectedServerHandler $connectedServer = null;
    private InternetAddress $fakeClientAddress;
    // The "client" socket
    public ServerSocket $socket;
    public InternetAddress $targetAddress;

    public const STATUS_UNCONNECTED = 0;
    public const STATUS_CONNECTING = 1;
    public const STATUS_CONNECTED = 2;

    public float $lastPacketTime;

    private int $status = self::STATUS_UNCONNECTED;
    private int $clientID;

    public function __construct(ProxyServer $server, ConnectedClientHandler $connectedClient)
    {
        parent::__construct();
        $this->proxyServer = $server;
        $this->connectedClient = $connectedClient;

        $this->clientID = mt_rand(0, PHP_INT_MAX);
        $clientRandomPort = mt_rand(50000, 65535);
        // was 0.0.0.0
        $this->fakeClientAddress = $bindAddress = new InternetAddress("0.0.0.0", $clientRandomPort, 4);
        $this->socket = new ServerSocket($bindAddress);
        $this->socket->setBlocking(false);

        $this->lastPacketTime = microtime(true);
    }

    public function readPacket(): void {
        if (($buffer = $this->socket->readPacket($senderIP, $senderPort)) != null) {
            // Maybe we switched server, and we're receiving packets from the old one
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
            GlobalLogger::get()->debug("Trying to ping target server...");
        } elseif ($this->status === self::STATUS_CONNECTED) {
            // FIXME: send just after some time, don't spam
            if ((int)$currentTime % 20 == 0) {
                $this->sendConnectedPing();
            }
        }

        // Timeout ping
        if ($currentTime - $this->lastPacketTime >= 10) {
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
                $connAccepted = new ConnectionRequestAccepted();
                $connAccepted->decode(new PacketSerializer($packet->buffer));

                $newIncomingConn = new NewIncomingConnection();
                $newIncomingConn->sendPingTime = $connAccepted->sendPingTime;
                $newIncomingConn->sendPongTime = time();
                $newIncomingConn->address = $this->fakeClientAddress;
                $this->sendEncapsulatedBuffer(ProxyServer::encodePacket($newIncomingConn));

                $this->connectedServer = new ConnectedServerHandler($this);

                $requestNetSettings = RequestNetworkSettingsPacket::create(ProtocolInfo::CURRENT_PROTOCOL);
                $this->sendDataPacket($requestNetSettings, false);

                // @var EncapsulatedPacket $encapsulated */
                // $encapsulated = $this->getConnectedClient()->cachedPackets[ProtocolInfo::LOGIN_PACKET];
                // $this->sendEncapsulatedBuffer($encapsulated->buffer);

                $this->status = self::STATUS_CONNECTED;
                break;
            case ProxyServer::MINECRAFT_HEADER:
                if (isset($this->connectedServer)) {
                    $this->connectedServer->handleMinecraft($packet);
                }
                break;
        }
    }

    private function handleOpenConnectionReplyOne(string $buffer): void {
        $this->status = self::STATUS_CONNECTING;

        $replyOne = new OpenConnectionReply1();
        $replyOne->decode(new PacketSerializer($buffer));

        $reqTwo = new OpenConnectionRequest2();
        $finalMtu = $replyOne->mtuSize;
        $reqTwo->mtuSize = $finalMtu;

        $this->mtuSize = $finalMtu;

        $reqTwo->clientID = $this->clientID;
        $reqTwo->serverAddress = $this->targetAddress;
        $this->sendBuffer(ProxyServer::encodePacket($reqTwo));
    }

    private function handleOpenConnectionReplyTwo(string $buffer): void {
        $replyTwo = new OpenConnectionReply2();
        $replyTwo->decode(new PacketSerializer($buffer));

        $connReq = new ConnectionRequest();
        $connReq->clientID = $this->clientID;
        $connReq->sendPingTime = time();
        $this->sendEncapsulatedBuffer(ProxyServer::encodePacket($connReq));
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
        $unconnectedPong = new UnconnectedPong();
        $unconnectedPong->decode(new PacketSerializer($buffer));

        $this->getConnectedClient()->sendMessage($unconnectedPong->serverName);

        $reqOne = new OpenConnectionRequest1();
        $reqOne->mtuSize = ($this->mtuSize -= 30) + 30;
        $reqOne->protocol = 11; // MC actual protocol
        $this->sendBuffer(ProxyServer::encodePacket($reqOne));
    }

    // Is the target server alive??
    public function tryUnconnectedPing(): void {
        $unconnectedPing = new UnconnectedPing();
        $unconnectedPing->sendPingTime = time();
        $unconnectedPing->clientId = $this->clientID;
        $this->sendBuffer(ProxyServer::encodePacket($unconnectedPing));
    }

    private function sendConnectedPing(): void {
        $connPing = new ConnectedPing();
        $connPing->sendPingTime = time();
        $this->sendEncapsulatedBuffer(ProxyServer::encodePacket($connPing));
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