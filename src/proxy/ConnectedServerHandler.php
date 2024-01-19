<?php


namespace proxy;


use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientCacheStatusPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\TickSyncPacket;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\utils\BinaryStream;
use raklib\protocol\EncapsulatedPacket;

class ConnectedServerHandler
{
    private ServerSession $session;
    public ?StartGamePacket $startGamePacket = null;
    private bool $isLoggedIn = false;

    private int $proxyEntityID;
    // public array $nearbyPlayers = [];

    public function __construct(ServerSession $session)
    {
        $this->session = $session;
        $this->proxyEntityID = $session->getConnectedClient()->getProxyRuntimeID();
    }

    public function handleMinecraft(EncapsulatedPacket $encapsulated) {
        $buffer = substr($encapsulated->buffer, 1);
        if ($this->isLoggedIn) {
            $buffer = zlib_decode($buffer);
        }

        /** @var DataPacket $packet */
        foreach (PacketBatch::decodePackets(
            new BinaryStream($buffer), ProxyServer::getPacketSerializerContext(), PacketPool::getInstance()
        ) as $packet) {
            switch ($packet->pid()) {
                case ProtocolInfo::NETWORK_SETTINGS_PACKET:
                    /** @var NetworkSettingsPacket $packet */
                    // TODO: handle algorithms and compression, for now let's force zlib
                    $this->isLoggedIn = true;

                    /** @var EncapsulatedPacket $encapsulated */
                    $encapsulated = $this->session->getConnectedClient()->cachedPackets[ProtocolInfo::LOGIN_PACKET];
                    $this->session->sendEncapsulatedBuffer($encapsulated->buffer);
                    break;
                case ProtocolInfo::PLAY_STATUS_PACKET:
                    /** @var PlayStatusPacket $packet */
                    if ($packet->status === PlayStatusPacket::LOGIN_SUCCESS) {
                        /** @var ClientCacheStatusPacket $clientCache */
                        $clientCache = $this->session->getConnectedClient()->cachedPackets[ProtocolInfo::CLIENT_CACHE_STATUS_PACKET];
                        $this->session->sendDataPacket($clientCache);
                        $this->session->getProxy()->getLogger()->info("Login success on target server!");
                    } elseif ($packet->status === PlayStatusPacket::PLAYER_SPAWN) {
                        $movePlayer = new MovePlayerPacket();
                        $movePlayer->actorRuntimeId = $this->session->getConnectedClient()->getProxyRuntimeID();
                        $movePlayer->position = $this->startGamePacket->playerPosition;
                        $movePlayer->mode = MovePlayerPacket::MODE_TELEPORT;
                        $movePlayer->pitch = 0;
                        $movePlayer->yaw = 0;
                        $movePlayer->headYaw = 0;
                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($movePlayer);
                    }
                    break;
                case ProtocolInfo::RESOURCE_PACKS_INFO_PACKET:
                    $packResponse = new ResourcePackClientResponsePacket();
                    $packResponse->status = ResourcePackClientResponsePacket::STATUS_HAVE_ALL_PACKS;
                    $this->session->sendDataPacket($packResponse);
                    break;
                case ProtocolInfo::RESOURCE_PACK_STACK_PACKET:
                    $packResponse = new ResourcePackClientResponsePacket();
                    $packResponse->status = ResourcePackClientResponsePacket::STATUS_COMPLETED;
                    $this->session->sendDataPacket($packResponse);
                    break;
                case ProtocolInfo::START_GAME_PACKET:
                    /** @var StartGamePacket $packet */
                    $this->startGamePacket = $packet;

                    // I think I need to teleport player to spawn point of start game
                    // and even before forward chunks and network chunk publisher

                    // Here I am following the legacy client sequence

                    /** @var DataPacket $requestChunkRadius */
                    $requestChunkRadius = $this->session->getConnectedClient()->cachedPackets[ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET];
                    $this->session->sendDataPacket($requestChunkRadius);

                    $tickSync = TickSyncPacket::request(time());
                    $this->session->sendDataPacket($tickSync);

                    $movePlayer = new MovePlayerPacket();
                    $movePlayer->actorRuntimeId = $this->startGamePacket->actorRuntimeId;
                    $movePlayer->position = $this->startGamePacket->playerPosition;
                    $movePlayer->pitch = 0;
                    $movePlayer->yaw = 0;
                    $movePlayer->headYaw = 0;
                    $this->session->sendDataPacket($movePlayer);
                    break;
                case ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET:
                    /** @var ChunkRadiusUpdatedPacket $packet */
                    // can hook :P
                    $this->session->getProxy()->getLogger()->info("Chunk radius is $packet->radius");

                    $initPlayer = new SetLocalPlayerAsInitializedPacket();
                    $initPlayer->actorRuntimeId = $this->startGamePacket->actorRuntimeId;
                    $this->session->sendDataPacket($initPlayer);
                    break;
                case ProtocolInfo::NETWORK_CHUNK_PUBLISHER_UPDATE_PACKET:
                    /** @var NetworkChunkPublisherUpdatePacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::LEVEL_CHUNK_PACKET:
                    /** @var LevelChunkPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::DISCONNECT_PACKET:
                    $this->session->getConnectedClient()->cancelConnection();
                    break;
                case ProtocolInfo::AVAILABLE_COMMANDS_PACKET:
                    /** @var AvailableCommandsPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::UPDATE_ATTRIBUTES_PACKET:
                    /** @var UpdateAttributesPacket $packet */
                    $packet->actorRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::ADD_PLAYER_PACKET:
                    /** @var AddPlayerPacket $packet */

                    // Let's go!
                    // TODO $this->nearbyPlayers[$addPlayer->username] = $packet->actorRuntimeId;

                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::SET_TIME_PACKET:
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                // case ProtocolInfo::ADVENTURE_SETTINGS_PACKET:
                //    $adventureSettings = new AdventureSettingsPacket($buffer);
                //    $adventureSettings->entityUniqueId = $this->proxyEntityID;
                //    $adventureSettings->decode();
                    // can hook :P
                //    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($adventureSettings);
                //    break;
                case ProtocolInfo::SET_ACTOR_DATA_PACKET:
                    /** @var SetActorDataPacket $packet */
                    $packet->actorRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::TEXT_PACKET:
                    /** @var TextPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::MOB_EFFECT_PACKET:
                    /** @var MobEffectPacket $packet */
                    // can hook :P
                    $packet->actorRuntimeId = $this->proxyEntityID;
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::MOVE_PLAYER_PACKET:
                    // lol we can actually modify other player positions to a client
                    break;
                case ProtocolInfo::INVENTORY_CONTENT_PACKET:
                    /** @var InventoryContentPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::INVENTORY_SLOT_PACKET:
                    /** @var InventorySlotPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::CREATIVE_CONTENT_PACKET:
                    /** @var CreativeContentPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::CRAFTING_DATA_PACKET:
                    /** @var CraftingDataPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::SET_DISPLAY_OBJECTIVE_PACKET:
                    /** @var SetDisplayObjectivePacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::REMOVE_OBJECTIVE_PACKET:
                    /** @var RemoveObjectivePacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::SET_SCORE_PACKET:
                    /** @var SetScorePacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::LEVEL_EVENT_PACKET:
                    /** @var LevelEventPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::LEVEL_SOUND_EVENT_PACKET:
                    /** @var LevelSoundEventPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::MOB_EQUIPMENT_PACKET:
                    /** @var MobEquipmentPacket $packet */
                    $packet->actorRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::UPDATE_BLOCK_PACKET:
                    /** @var UpdateBlockPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::SET_TITLE_PACKET:
                    /** @var SetTitlePacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::PLAYER_LIST_PACKET:
                    /** @var PlayerListPacket $packet */
                    // can hook :P
                    // TODO: cache player IDS and remove when switching server
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::MODAL_FORM_REQUEST_PACKET:
                    /** @var ModalFormRequestPacket $packet */
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                    // TODO: transfer
                case ProtocolInfo::SET_ACTOR_MOTION_PACKET:
                    /** @var SetActorMotionPacket $packet */
                    $packet->actorRuntimeId = $this->proxyEntityID;
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::AVAILABLE_ACTOR_IDENTIFIERS_PACKET:
                case ProtocolInfo::BIOME_DEFINITION_LIST_PACKET:
                    // we already sent it before
                    // $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    break;
                case ProtocolInfo::SERVER_TO_CLIENT_HANDSHAKE_PACKET:
                    /** @var ServerToClientHandshakePacket $packet */
                    $jwt = JWT::decode($packet->jwt, new Key(null, 'ES384'));
                    break;
                default:
                    $this->session->getProxy()->getLogger()->info("Not implemented S->P {$packet->getName()}");
                    // forward
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($packet);
                    return;
            }
        }
    }
}