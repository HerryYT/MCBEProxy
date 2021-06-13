<?php


namespace proxy;


use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\MainLogger;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\PacketReliability;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPong;
use raklib\server\UDPServerSocket;
use raklib\utils\InternetAddress;

class ProxyServer
{
    private UDPServerSocket $socket;
    private array $clientSessions = [];
    private MainLogger $logger;

    private float $lastTick;
    private int $serverID;

    public const MINECRAFT_HEADER = 0xFE;

    public function __construct()
    {
        // Start the server always on locale
        $address = new InternetAddress("0.0.0.0", 19132, 4);
        $this->socket = new UDPServerSocket($address);

        $this->lastTick = microtime(true);
        $this->serverID = mt_rand(0, PHP_INT_MAX);

        $this->logger = new MainLogger(__DIR__ . "/../../log.txt", false);
    }

    /**
     * Handles all network and tick stuff.
     */
    public function start(): void {
        while (true) {
            if ($this->socket->readPacket($buffer, $ip, $port)) {
                $address = new InternetAddress($ip, $port, 4);
                if (!$this->handleUnconnected($buffer, $address)) {
                    if (($session = $this->getSession($buffer, $address)) != null) {
                        $session->handle($buffer);
                    }
                }
            }

            /** @var ClientSession $clientSession */
            foreach ($this->clientSessions as $clientSession) {
                if ($clientSession->isConnected()) {
                    if (($session = $clientSession->getConnectedClient()->getServerSession()) != null) {
                        $session->readPacket();
                    }
                }

                // Tick every 1/20 seconds
                if (microtime(true) - $this->lastTick >= 0.05) {
                    $clientSession->tick(microtime(true));
                    $this->lastTick = microtime(true);
                }
            }
        }
    }

    private function getSession(string $buffer, InternetAddress $address): ?ClientSession {
        $pid = ord($buffer[0]);
        if ($pid == MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1) {
            if (!isset($this->clientSessions[$address->toString()])) {
                $session = new ClientSession($this, $address);
                $this->clientSessions[$address->toString()] = $session;
            }
            $this->logger->info("Creating new session for {$address->toString()}...");
        }
        return $this->clientSessions[$address->toString()] ?? null;
    }

    public function deleteSession(ClientSession $session): void {
        if (isset($this->clientSessions[$session->getClientAddress()->toString()])) {
            /** @var ClientSession $session */
            $session = $this->clientSessions[$session->getClientAddress()->toString()];
            // If was connected to a server, disconnect him
            if ($session->isConnected()) {
                // TODO: not working... wtf
                $disconnect = new DisconnectPacket();
                $disconnect->encode();
                $session->sendEncapsulatedBuffer($disconnect->getBuffer(), PacketReliability::RELIABLE_ORDERED);
            }
            $this->getLogger()->info("Player with address {$session->getClientAddress()->toString()} disconnected!");
            unset($this->clientSessions[$session->getClientAddress()->toString()]);
        }
    }

    private function handleUnconnected(string $buffer, InternetAddress $address): bool {
        $pid = ord($buffer[0]);
        if ($pid === MessageIdentifiers::ID_UNCONNECTED_PING) {
            $this->handleUnconnectedPing($buffer, $address);
            return true;
        }
        return false;
    }

    private function handleUnconnectedPing(string $buffer, InternetAddress $address): void {
        $unconnectedPing = new UnconnectedPing($buffer);
        $unconnectedPing->decode();

        $unconnectedPong = new UnconnectedPong();
        $unconnectedPong->pingID = $unconnectedPing->pingID;
        $unconnectedPong->serverID = $this->serverID;
        $unconnectedPong->serverName = $this->getMOTD();
        $unconnectedPong->encode();
        $this->sendBuffer($unconnectedPong->getBuffer(), $address);
    }

    private function getMOTD(): string {
        return join(";", [
            "MCPE",
            "Proxy",
            ProtocolInfo::CURRENT_PROTOCOL,
            ProtocolInfo::MINECRAFT_VERSION_NETWORK,
            count($this->clientSessions),
            100,
            $this->serverID,
            "Second line",
            "Creative"
        ]) . ";";
    }

    public function sendBuffer(string $buffer, InternetAddress $address): void {
        $this->socket->writePacket($buffer, $address->getIp(), $address->getPort());
    }

    public function getServerID(): int {
        return $this->serverID;
    }

    public function getLogger(): MainLogger {
        return $this->logger;
    }
}