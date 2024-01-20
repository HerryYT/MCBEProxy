<?php


namespace proxy;


use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GlobalLogger;
use OpenSSLAsymmetricKey;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientCacheStatusPacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\TickSyncPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\LevelSettings;
use pocketmine\network\mcpe\protocol\types\NetworkPermissions;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use pocketmine\utils\BinaryStream;
use raklib\protocol\EncapsulatedPacket;
use raklib\utils\InternetAddress;
use Ramsey\Uuid\Uuid;

class ConnectedClientHandler
{
    public const MOJANG_PUBLIC_KEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAECRXueJeTDqNRRgJi/vlRufByu/2G0i2Ebt6YMar5QX/R0DIIyrJMcUpruK4QveTfJSTp3Shlq4Gk34cD/4GUWwkv0DVuzeuB+tXija7HBxii03NHDbPAD0AKnLr2wdAp";

    private ClientSession $session;
    private bool $isLoggedIn = false;
    private string $username = '';

    public array $cachedPackets = [];

    public ?ServerSession $serverSession = null;

    public OpenSSLAsymmetricKey|null $clientPublicKey = null;

    public array|null $cachedChainData = null;

    public function __construct(ClientSession $session)
    {
        $this->session = $session;
    }

    public function handleMinecraft(EncapsulatedPacket $encapsulated): void {
        // TODO: encryption (ez)

        $buffer = substr($encapsulated->buffer, 1);
        if ($this->isLoggedIn) {
            $buffer = zlib_decode($buffer);
        }

        /** @var DataPacket $packet */
        foreach (PacketBatch::decodePackets(new BinaryStream($buffer), ProxyServer::getPacketSerializerContext(), PacketPool::getInstance()) as $packet) {
            if (($session = $this->getServerSession()) != null) {
                if ($session->isConnected()) {
                    switch ($packet->pid()) {
                        case ProtocolInfo::TEXT_PACKET:
                            // Handled in the next switch
                            break;
                        case ProtocolInfo::MOVE_PLAYER_PACKET:
                            /** @var MovePlayerPacket $packet */
                            $packet->actorRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->actorRuntimeId;
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::ANIMATE_PACKET:
                            // Forward with fixed entity runtime id
                            /** @var AnimatePacket $packet */
                            $packet->actorRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->actorRuntimeId;
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::PLAYER_ACTION_PACKET:
                            // Forward with fixed entity runtime id
                            /** @var PlayerActionPacket $packet */
                            $packet->actorRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->actorRuntimeId;
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::INVENTORY_TRANSACTION_PACKET:
                            /** @var InventoryTransactionPacket $packet */
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::LEVEL_SOUND_EVENT_PACKET:
                            /** @var LevelSoundEventPacket $packet */
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::LEVEL_EVENT_PACKET:
                            /** @var LevelEventPacket $packet */
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::INTERACT_PACKET:
                            /** @var InteractPacket $packet */

                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::MOB_EQUIPMENT_PACKET:
                            /** @var MobEquipmentPacket $packet */
                            $packet->actorRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->actorRuntimeId;
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::MODAL_FORM_RESPONSE_PACKET:
                            /** @var ModalFormResponsePacket $packet */
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        case ProtocolInfo::COMMAND_REQUEST_PACKET:
                            /** @var CommandRequestPacket $packet */
                            $this->getServerSession()->sendDataPacket($packet);
                            break;
                        default:
                            $this->session->getProxy()->getLogger()->info("Not implemented C->P {$packet->getName()}");
                            return;
                    }
                }
            }

            // To always handle
            switch ($packet->pid()) {
                case ProtocolInfo::REQUEST_NETWORK_SETTINGS_PACKET:
                    /** @var RequestNetworkSettingsPacket $packet */
                    $netSettings = NetworkSettingsPacket::create(1, 0, false, 0,0);
                    $this->session->sendDataPacket($netSettings, false);
                    $this->isLoggedIn = true;
                    break;
                case ProtocolInfo::LOGIN_PACKET:
                    /** @var LoginPacket $packet */

                    // Cache the login packet, so it's easier for use to resend packets
                    $this->cachedPackets[ProtocolInfo::LOGIN_PACKET] = $packet;

                    // JWT token verification
                    // Source(s):
                    // https://github.com/PrismarineJS/bedrock-protocol/blob/master/src/handshake/loginVerify.js

                    $getX5U = function (string $token) {
                        [$header] = explode(".", $token);
                        $decodedHeader = mb_convert_encoding(base64_decode($header), 'UTF-8');
                        $jsonHeader = json_decode($decodedHeader, true);
                        return $jsonHeader["x5u"];
                    };

                    $chain = $packet->chainDataJwt->chain;

                    // get X5U from token
                    $x5u = $getX5U($chain[0]);  // client signed one

                    // get public key from X5U
                    $publicKey = EncryptionHandler::getDer($x5u);
                    if ($publicKey === false) {
                        GlobalLogger::get()->error("Failed to get public key from X5U!");
                        // TODO: disable encryption
                    }

                    // verify chain and decode data
                    $data = [];
                    foreach ($chain as $token) {
                        $decodedToken = (array)JWT::decode($token, new Key($publicKey, 'ES384'));

                        $x5u = $getX5U($token);
                        if ($x5u === self::MOJANG_PUBLIC_KEY && !isset($data['extraData']['XUID'])) {
                            GlobalLogger::get()->debug(
                                "Verified token for {$this->session->getClientAddress()->toString()} with mojang public key"
                            );
                        }

                        $publicKey = $decodedToken['identityPublicKey'] ? EncryptionHandler::getDer($decodedToken['identityPublicKey']) : $x5u;
                        if ($publicKey === false) {
                            GlobalLogger::get()->error('Failed to get public key from X5U!');
                        }

                        $data = [...$data, ...$decodedToken];
                    }

                    $this->cachedChainData = $data;

                    // Save the last client public key in the format we need
                    $this->clientPublicKey = $publicKey;

                    // TODO: if client pub key is null, avoid encryption once again

                    $this->username = ((array)$data['extraData'])['displayName'];

                    GlobalLogger::get()->info("$this->username is trying to join proxy...");

                    $playStatus = new PlayStatusPacket();
                    $playStatus->status = PlayStatusPacket::LOGIN_SUCCESS;
                    $this->session->sendDataPacket($playStatus);

                    $resInfo = new ResourcePacksInfoPacket();
                    $resInfo->mustAccept = false;
                    $resInfo->hasScripts = false;
                    $this->session->sendDataPacket($resInfo);
                    break;
                case ProtocolInfo::RESOURCE_PACK_CLIENT_RESPONSE_PACKET:
                    /** @var ResourcePackClientResponsePacket $packet */

                    if ($packet->status == ResourcePackClientResponsePacket::STATUS_HAVE_ALL_PACKS) {
                        $resourcePackStack = new ResourcePackStackPacket();
                        $resourcePackStack->mustAccept = false;
                        $resourcePackStack->experiments = new Experiments([], false);
                        $this->session->sendDataPacket($resourcePackStack);
                    } elseif ($packet->status == ResourcePackClientResponsePacket::STATUS_COMPLETED) {
                        $eid = $this->getProxyRuntimeID();
                        $this->getClientSession()->getProxy()->getLogger()->info("Entity ID for $this->username is $eid");

                        $levelSettings = new LevelSettings();
                        $levelSettings->time = 0;
                        $levelSettings->seed = 0;
                        $levelSettings->spawnPosition = new BlockPosition(0,0,0);
                        $levelSettings->difficulty = 0;
                        $levelSettings->spawnSettings = new SpawnSettings(
                            SpawnSettings::BIOME_TYPE_DEFAULT, "", DimensionIds::OVERWORLD
                        );
                        $levelSettings->hasAchievementsDisabled = false;
                        $levelSettings->worldGamemode = GameMode::SURVIVAL;
                        $levelSettings->commandsEnabled = true;
                        $levelSettings->isTexturePacksRequired = false;
                        $levelSettings->rainLevel = 0;
                        $levelSettings->lightningLevel = 0;
                        $levelSettings->experiments = new Experiments([], false);

                        $startGame = StartGamePacket::create(
                            $eid,
                            $eid,
                            GameMode::SURVIVAL,
                            new Vector3(0, 0, 0),
                            0,
                            0,
                            new CacheableNbt(new CompoundTag()),
                            $levelSettings,
                            "",
                            "Proxy server",
                            "",
                            false,
                            new PlayerMovementSettings(PlayerMovementType::LEGACY, 0, false),
                            0,
                            0,
                            "",
                            false,
                            "*",
                            Uuid::uuid4(),
                            false,
                            false,
                            new NetworkPermissions(false),
                            [],
                            0,
                            []
                        );

                        $this->getClientSession()->sendDataPacket($startGame);

                        $creativeContent = CreativeContentPacket::create([]);
                        $this->getClientSession()->sendDataPacket($creativeContent);

                        $biomeDefinition = BiomeDefinitionListPacket::create(new CacheableNbt(
                            (new NetworkNbtSerializer())->read(
                                file_get_contents('vendor/pocketmine/bedrock-data/biome_definitions.nbt'))->mustGetCompoundTag()
                            )
                        );
                        $this->session->sendDataPacket($biomeDefinition);

                        $playStatus = new PlayStatusPacket();
                        $playStatus->status = PlayStatusPacket::PLAYER_SPAWN;
                        $this->session->sendDataPacket($playStatus);
                    }
                    break;
                case ProtocolInfo::CLIENT_CACHE_STATUS_PACKET:
                    /** @var ClientCacheStatusPacket $packet */

                    // NOTE: not a batch
                    $this->cachedPackets[ProtocolInfo::CLIENT_CACHE_STATUS_PACKET] = $packet;
                    break;
                case ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET:
                    /** @var RequestChunkRadiusPacket $packet */

                    $this->cachedPackets[ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET] = $packet;

                    $updated = new ChunkRadiusUpdatedPacket();
                    $updated->radius = $packet->radius;
                    $this->session->sendDataPacket($updated);
                    break;
                case ProtocolInfo::TICK_SYNC_PACKET:
                    /** @var TickSyncPacket $packet */

                    $newTickSync = TickSyncPacket::response($packet->getClientSendTime(), time());
                    $this->session->sendDataPacket($newTickSync);
                    break;
                case ProtocolInfo::SET_LOCAL_PLAYER_AS_INITIALIZED_PACKET:
                    $this->session->getProxy()->getLogger()->info("Player $this->username successfully logged-ín!");

                    $this->sendMessage("§bWelcome to the Proxy! Type */help for a list of commands!");
                    break;
                case ProtocolInfo::TEXT_PACKET:
                    /** @var TextPacket $packet */

                    $message = $packet->message;
                    if (substr($message, 0, 2) === "*/") {
                        // Message is proxy request
                        $actualMessage = str_replace("*/", "", $message);
                        $arguments = explode(" ", $actualMessage);
                        $commandName = $arguments[0];
                        array_shift($arguments);
                        switch ($commandName) {
                            case "connect":
                                if (count($arguments) < 1) {
                                    $this->sendMessage("Invalid usage! please use */help");
                                    return;
                                }

                                $targetIp = $arguments[0];
                                $targetPort = $arguments[1] ?? 19132;

                                // If it's a DNS, convert to IP
                                if (!filter_var($arguments[0], FILTER_VALIDATE_IP)) {
                                    $foundIP = gethostbyname($targetIp);
                                    if ($targetIp === $foundIP) {
                                        $this->sendMessage("Invalid IP/DNS inserted!");
                                        return;
                                    }
                                    $targetIp = $foundIP;
                                }

                                $targetAddress = new InternetAddress($targetIp, $targetPort, 4);
                                if ($this->serverSession) {
                                    $this->sendMessage("Transferring to {$targetAddress->toString()}...");
                                    $this->session->getProxy()->getLogger()->info("Transferring $this->username to {$targetAddress->toString()}...");
                                } else {
                                    $this->serverSession = new ServerSession($this->session->getProxy(), $this);
                                    $this->sendMessage("Connecting to {$targetAddress->toString()}...");
                                    $this->session->getProxy()->getLogger()->info("Connecting $this->username to {$targetAddress->toString()}...");
                                }
                                $this->serverSession->connect($targetAddress);
                                break;
                            case "help":
                                $this->sendMessage("*/connect <ip> <port> -> connects to a server");
                                break;
                            default:
                                $this->sendMessage("Command not found! try using */help for a list of commands");
                        }
                    } else {
                        // Sadly, we have to handle that shit here
                        if (($session = $this->getServerSession()) != null) {
                            if ($session->isConnected()) {
                                $session->sendDataPacket($packet);
                            }
                        }
                    }
            }
        }
    }

    public function cancelConnection(): void {
        $this->sendMessage("Target server failed to ping!");
        $this->serverSession = null;
    }

    public function sendMessage(string $text): void {
        $packet = new TextPacket();
        $packet->sourceName = "§cPROXY§f";
        $packet->message = $text;
        $packet->type = TextPacket::TYPE_CHAT;
        $this->getClientSession()->sendDataPacket($packet);
    }

    public function getProxyRuntimeID(): int {
        return 1; // convention
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function getClientSession(): ClientSession {
        return $this->session;
    }

    public function getServerSession(): ?ServerSession {
        return $this->serverSession;
    }
}