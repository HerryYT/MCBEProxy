<?php


namespace proxy;


use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientCacheStatusPacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\TickSyncPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use raklib\protocol\EncapsulatedPacket;
use raklib\utils\InternetAddress;

class ConnectedClientHandler
{
    private ClientSession $session;
    private string $username;

    public array $cachedPackets = [];

    public ?ServerSession $serverSession = null;

    public function __construct(ClientSession $session)
    {
        $this->session = $session;
    }

    public function handleMinecraft(EncapsulatedPacket $encapsulated): void {
        // TODO: encryption (ez)
        $batch = new BatchPacket($encapsulated->buffer);
        $batch->decode();

        foreach ($batch->getPackets() as $buffer) {
            $pid = ord($buffer[0]);
            if (($session = $this->getServerSession()) != null) {
                if ($session->isConnected()) {
                    switch ($pid) {
                        case ProtocolInfo::TEXT_PACKET:
                            // Handled in the next switch
                            break;
                        case ProtocolInfo::MOVE_PLAYER_PACKET:
                            $movePlayer = new MovePlayerPacket($buffer);
                            $movePlayer->decode();

                            $movePlayer->entityRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->entityRuntimeId;
                            $this->getServerSession()->sendDataPacket($movePlayer);
                            break;
                        case ProtocolInfo::ANIMATE_PACKET:
                            $animate = new AnimatePacket($buffer);
                            $animate->decode();

                            // Forward with fixed entity runtime id
                            $animate->entityRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->entityRuntimeId;
                            $this->getServerSession()->sendDataPacket($animate);
                            break;
                        case ProtocolInfo::PLAYER_ACTION_PACKET:
                            $action = new PlayerActionPacket($buffer);
                            $action->decode();

                            // Forward with fixed entity runtime id
                            $action->entityRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->entityRuntimeId;
                            $this->getServerSession()->sendDataPacket($action);
                            break;
                        case ProtocolInfo::INVENTORY_TRANSACTION_PACKET:
                            $invTransaction = new InventoryTransactionPacket($buffer);
                            $invTransaction->decode();
                            $this->getServerSession()->sendDataPacket($invTransaction);
                            break;
                        case ProtocolInfo::LEVEL_SOUND_EVENT_PACKET:
                            $levelSound = new LevelSoundEventPacket($buffer);
                            $levelSound->decode();
                            $this->getServerSession()->sendDataPacket($levelSound);
                            break;
                        case ProtocolInfo::LEVEL_EVENT_PACKET:
                            $levelEvent = new LevelEventPacket($buffer);
                            $levelEvent->decode();
                            $this->getServerSession()->sendDataPacket($levelEvent);
                            break;
                        case ProtocolInfo::INTERACT_PACKET:
                            $interact = new InteractPacket($buffer);
                            $interact->decode();

                            $this->getServerSession()->sendDataPacket($interact);
                            break;
                        case ProtocolInfo::MOB_EQUIPMENT_PACKET:
                            $mobEquip = new MobEquipmentPacket($buffer);
                            $mobEquip->decode();

                            $mobEquip->entityRuntimeId = $this->getServerSession()->getConnectedServer()->startGamePacket->entityRuntimeId;
                            $this->getServerSession()->sendDataPacket($mobEquip);
                            break;
                        case ProtocolInfo::MODAL_FORM_RESPONSE_PACKET:
                            $modalRes = new ModalFormResponsePacket($buffer);
                            $modalRes->decode();

                            $this->getServerSession()->sendDataPacket($modalRes);
                            break;
                        case ProtocolInfo::COMMAND_REQUEST_PACKET:
                            $cmdReq = new CommandRequestPacket($buffer);
                            $cmdReq->decode();

                            $this->getServerSession()->sendDataPacket($cmdReq);
                            break;
                        default:
                            $packet = PacketPool::getPacket($buffer);
                            $this->getClientSession()->getProxy()->getLogger()->info("Not implemented C->P {$packet->getName()}");
                            return;
                    }
                }
            }

            // To always handle
            switch ($pid) {
                case ProtocolInfo::LOGIN_PACKET:
                    $login = new LoginPacket($buffer);
                    $login->decode();

                    // Cache the login packet so it's easier for use to resend packets
                    $this->cachedPackets[ProtocolInfo::LOGIN_PACKET] = $batch;

                    $this->username = $login->username;
                    $this->getClientSession()->getProxy()->getLogger()->info("{$login->username} is trying to join proxy...");

                    $playStatus = new PlayStatusPacket();
                    $playStatus->status = PlayStatusPacket::LOGIN_SUCCESS;
                    $this->getClientSession()->sendDataPacket($playStatus);

                    $resInfo = new ResourcePacksInfoPacket();
                    $resInfo->mustAccept = false;
                    $resInfo->hasScripts = false;
                    $this->getClientSession()->sendDataPacket($resInfo);
                    break;
                case ProtocolInfo::RESOURCE_PACK_CLIENT_RESPONSE_PACKET:
                    $response = new ResourcePackClientResponsePacket($buffer);
                    $response->decode();

                    if ($response->status == ResourcePackClientResponsePacket::STATUS_HAVE_ALL_PACKS) {
                        $resourcePackStack = new ResourcePackStackPacket();
                        $resourcePackStack->mustAccept = false;
                        $resourcePackStack->experiments = new Experiments([], false);
                        $this->getClientSession()->sendDataPacket($resourcePackStack);
                    } elseif ($response->status == ResourcePackClientResponsePacket::STATUS_COMPLETED) {
                        $eid = $this->getProxyRuntimeID();
                        $this->getClientSession()->getProxy()->getLogger()->info("Entity ID for $this->username is $eid");
                        $startGame = new StartGamePacket();
                        $startGame->entityUniqueId = $eid;
                        $startGame->entityRuntimeId = $eid;

                        $startGame->playerGamemode = GameMode::SURVIVAL;
                        $startGame->playerPosition = new Vector3(0, 0, 0);

                        $startGame->pitch = 0;
                        $startGame->yaw = 0;

                        $startGame->seed = -1;
                        $startGame->spawnSettings = new SpawnSettings(SpawnSettings::BIOME_TYPE_DEFAULT, "", DimensionIds::OVERWORLD);
                        $startGame->worldGamemode = GameMode::SURVIVAL;
                        $startGame->difficulty = 0;
                        $startGame->spawnX = 0;
                        $startGame->spawnY = 0;
                        $startGame->spawnZ = 0;

                        $startGame->hasAchievementsDisabled = true;
                        $startGame->time = 0;

                        $startGame->eduEditionOffer = 0;

                        $startGame->rainLevel = 0;
                        $startGame->lightningLevel = 0;

                        $startGame->commandsEnabled = true;
                        $startGame->levelId = "";
                        $startGame->worldName = "Proxy server";
                        $startGame->experiments = new Experiments([], false);
                        $startGame->itemTable = [];

                        $startGame->isTexturePacksRequired = false;

                        $startGame->playerMovementSettings = new PlayerMovementSettings(PlayerMovementType::LEGACY, 0, false);
                        $startGame->serverSoftwareVersion = "Proxy v1";
                        $this->getClientSession()->sendDataPacket($startGame);

                        $creativeContent = CreativeContentPacket::create([]);
                        $this->getClientSession()->sendDataPacket($creativeContent);

                        $biomeDefinition = new BiomeDefinitionListPacket();
                        $biomeDefinition->namedtag = file_get_contents('vendor/pocketmine/bedrock-data/biome_definitions.nbt');
                        $this->getClientSession()->sendDataPacket($biomeDefinition);

                        $playStatus = new PlayStatusPacket();
                        $playStatus->status = PlayStatusPacket::PLAYER_SPAWN;
                        $this->getClientSession()->sendDataPacket($playStatus);
                    }
                    break;
                case ProtocolInfo::CLIENT_CACHE_STATUS_PACKET:
                    $clientCache = new ClientCacheStatusPacket($buffer);
                    $clientCache->decode();

                    // NOTE: not a batch
                    $this->cachedPackets[ProtocolInfo::CLIENT_CACHE_STATUS_PACKET] = $clientCache;
                    break;
                case ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET:
                    $request = new RequestChunkRadiusPacket($buffer);
                    $request->decode();

                    $this->cachedPackets[ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET] = $batch;

                    $updated = new ChunkRadiusUpdatedPacket();
                    $updated->radius = $request->radius;
                    $this->getClientSession()->sendDataPacket($updated);
                    break;
                case ProtocolInfo::TICK_SYNC_PACKET:
                    $tickSync = new TickSyncPacket($buffer);
                    $tickSync->decode();

                    $newTickSync = TickSyncPacket::response($tickSync->getClientSendTime(), time());
                    $this->getClientSession()->sendDataPacket($newTickSync);
                    break;
                case ProtocolInfo::SET_LOCAL_PLAYER_AS_INITIALIZED_PACKET:
                    $this->session->getProxy()->getLogger()->info("Player {$this->username} successfully logged-ín!");

                    $this->sendMessage("§bWelcome to the Proxy! Type */help for a list of commands!");
                    break;
                case ProtocolInfo::TEXT_PACKET:
                    $textPacket = new TextPacket($buffer);
                    $textPacket->decode();

                    $message = $textPacket->message;
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
                                    $this->session->getProxy()->getLogger()->info("Transferring {$this->username} to {$targetAddress->toString()}...");
                                } else {
                                    $this->serverSession = new ServerSession($this->session->getProxy(), $this);
                                    $this->sendMessage("Connecting to {$targetAddress->toString()}...");
                                    $this->session->getProxy()->getLogger()->info("Connecting {$this->username} to {$targetAddress->toString()}...");
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
                        // Sadly we have to handle that shit here
                        if (($session = $this->getServerSession()) != null) {
                            if ($session->isConnected()) {
                                $session->sendDataPacket($textPacket);
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