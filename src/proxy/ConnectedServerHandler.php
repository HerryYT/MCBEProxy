<?php


namespace proxy;


use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientCacheStatusPacket;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
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
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
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
use raklib\protocol\EncapsulatedPacket;

class ConnectedServerHandler
{
    private ServerSession $session;
    public ?StartGamePacket $startGamePacket = null;

    private int $proxyEntityID;
    public array $nearbyPlayers = [];

    public function __construct(ServerSession $session)
    {
        $this->session = $session;
        $this->proxyEntityID = $session->getConnectedClient()->entityID;
    }

    public function handleMinecraft(EncapsulatedPacket $encapsulated) {
        $batch = new BatchPacket($encapsulated->buffer);
        $batch->decode();
        $batch->getPackets();
        foreach ($batch->getPackets() as $buffer) {
            $pid = ord($buffer[0]);
            // $packet = PacketPool::getPacket($buffer);
            // var_dump($packet->getName());
            switch ($pid) {
                case ProtocolInfo::PLAY_STATUS_PACKET:
                    $playStatus = new PlayStatusPacket($buffer);
                    $playStatus->decode();

                    if ($playStatus->status === PlayStatusPacket::LOGIN_SUCCESS) {
                        /** @var ClientCacheStatusPacket $clientCache */
                        $clientCache = $this->session->getConnectedClient()->cachedPackets[ProtocolInfo::CLIENT_CACHE_STATUS_PACKET];
                        $this->session->sendDataPacket($clientCache);
                        $this->session->getProxy()->getLogger()->info("Login success on server!");
                    } elseif ($playStatus->status === PlayStatusPacket::PLAYER_SPAWN) {
//                        $dim0 = new ChangeDimensionPacket();
//                        $dim0->dimension = DimensionIds::OVERWORLD;
//                        $dim0->position = $this->startGamePacket->playerPosition;
//
//                        $dim1 = new ChangeDimensionPacket();
//                        $dim1->dimension = DimensionIds::NETHER;
//                        $dim1->position = $this->startGamePacket->playerPosition;
//
//                        $playStatus0 = new PlayStatusPacket();
//                        $playStatus0->status = PlayStatusPacket::PLAYER_SPAWN;
//
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($dim0);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($playStatus0);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($dim1);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($playStatus0);
//
//                        for ($x = -3; $x < 3; $x++) {
//                            for ($z = -3; $z < 3; $z++) {
//                                $levelChunk = LevelChunkPacket::withoutCache($x, $z, 0, "");
//                                $this->session->getConnectedClient()->getClientSession()->sendDataPacket($levelChunk);
//                            }
//                        }
//
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($dim1);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($playStatus0);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($dim0);
//                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($playStatus0);
                        $movePlayer = new MovePlayerPacket();
                        $movePlayer->entityRuntimeId = $this->session->getConnectedClient()->entityID;
                        $movePlayer->position = $this->startGamePacket->playerPosition;
                        $movePlayer->mode = MovePlayerPacket::MODE_TELEPORT;
                        $movePlayer->pitch = 0;
                        $movePlayer->yaw = 0;
                        $movePlayer->headYaw = 0;
                        $this->session->getConnectedClient()->getClientSession()->sendDataPacket($movePlayer);
                    }
                    break;
                case ProtocolInfo::RESOURCE_PACKS_INFO_PACKET:
                    $resourceInfo = new ResourcePacksInfoPacket($buffer);
                    $resourceInfo->decode();

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
                    $this->startGamePacket = new StartGamePacket($buffer);
                    $this->startGamePacket->decode();

                    // I think i need to teleport player to spawn point of start game
                    // and even before forward chunks and network chunk publisher

                    // Here i am following the legacy client sequence

                    /** @var BatchPacket $requestChunkRadius */
                    $requestChunkRadius = $this->session->getConnectedClient()->cachedPackets[ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET];
                    $this->session->sendEncapsulatedBuffer($requestChunkRadius->getBuffer());

                    $tickSync = TickSyncPacket::request(time());
                    $this->session->sendDataPacket($tickSync);

                    $movePlayer = new MovePlayerPacket();
                    $movePlayer->entityRuntimeId = $this->startGamePacket->entityRuntimeId;
                    $movePlayer->position = $this->startGamePacket->playerPosition;
                    $movePlayer->pitch = 0;
                    $movePlayer->yaw = 0;
                    $movePlayer->headYaw = 0;
                    $this->session->sendDataPacket($movePlayer);
                    break;
                case ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET:
                    $radiusUpdated = new ChunkRadiusUpdatedPacket($buffer);
                    $radiusUpdated->decode();
                    // can hook :P
                    $this->session->getProxy()->getLogger()->info("Chunk radius is $radiusUpdated->radius");

                    $initPlayer = new SetLocalPlayerAsInitializedPacket();
                    $initPlayer->entityRuntimeId = $this->startGamePacket->entityRuntimeId;
                    $this->session->sendDataPacket($initPlayer);
                    break;
                case ProtocolInfo::NETWORK_CHUNK_PUBLISHER_UPDATE_PACKET:
                    $chunkPublisher = new NetworkChunkPublisherUpdatePacket($buffer);
                    $chunkPublisher->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($chunkPublisher);
                    break;
                case ProtocolInfo::LEVEL_CHUNK_PACKET:
                    $levelChunk = new LevelChunkPacket($buffer);
                    $levelChunk->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($levelChunk);
                    break;
                case ProtocolInfo::DISCONNECT_PACKET:
                    $this->session->getConnectedClient()->cancelConnection();
                    break;
                case ProtocolInfo::AVAILABLE_COMMANDS_PACKET:
                    $availableCommands = new AvailableCommandsPacket($buffer);
                    $availableCommands->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($availableCommands);
                    break;
                case ProtocolInfo::UPDATE_ATTRIBUTES_PACKET:
                    $updateAttributes = new UpdateAttributesPacket($buffer);
                    $updateAttributes->decode();
                    $updateAttributes->entityRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($updateAttributes);
                    break;
                case ProtocolInfo::ADD_PLAYER_PACKET:
                    $addPlayer = new AddPlayerPacket($buffer);
                    $addPlayer->decode();

                    // Let's go!
                    $this->nearbyPlayers[$addPlayer->username] = $addPlayer->entityRuntimeId;

                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($addPlayer);
                    break;
                case ProtocolInfo::SET_TIME_PACKET:
                    $time = new SetTimePacket($buffer);
                    $time->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($time);
                    break;
                case ProtocolInfo::ADVENTURE_SETTINGS_PACKET:
                    $adventureSettings = new AdventureSettingsPacket($buffer);
                    $adventureSettings->entityUniqueId = $this->proxyEntityID;
                    $adventureSettings->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($adventureSettings);
                    break;
                case ProtocolInfo::SET_ACTOR_DATA_PACKET:
                    $setActorData = new SetActorDataPacket($buffer);
                    $setActorData->decode();
                    $setActorData->entityRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($setActorData);
                    break;
                case ProtocolInfo::TEXT_PACKET:
                    $textPacket = new TextPacket($buffer);
                    $textPacket->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($textPacket);
                    break;
                case ProtocolInfo::MOB_EFFECT_PACKET:
                    $mobEffect = new MobEffectPacket($buffer);
                    $mobEffect->decode();
                    // can hook :P
                    $mobEffect->entityRuntimeId = $this->proxyEntityID;
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($mobEffect);
                    break;
                case ProtocolInfo::MOVE_PLAYER_PACKET:
                    // lol we can actually modify other player positions to client
                    break;
                case ProtocolInfo::INVENTORY_CONTENT_PACKET:
                    $invContent = new InventoryContentPacket($buffer);
                    $invContent->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($invContent);
                    break;
                case ProtocolInfo::INVENTORY_SLOT_PACKET:
                    $invSlot = new InventorySlotPacket($buffer);
                    $invSlot->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($invSlot);
                    break;
                case ProtocolInfo::CREATIVE_CONTENT_PACKET:
                    $creativeContent = new CreativeContentPacket($buffer);
                    $creativeContent->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($creativeContent);
                    break;
                case ProtocolInfo::CRAFTING_DATA_PACKET:
                    $crafting = new CraftingDataPacket($buffer);
                    $crafting->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($crafting);
                    break;
                case ProtocolInfo::SET_DISPLAY_OBJECTIVE_PACKET:
                    $display = new SetDisplayObjectivePacket($buffer);
                    $display->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($display);
                    break;
                case ProtocolInfo::REMOVE_OBJECTIVE_PACKET:
                    $removeObj = new RemoveObjectivePacket($buffer);
                    $removeObj->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($removeObj);
                    break;
                case ProtocolInfo::SET_SCORE_PACKET:
                    $setScore = new SetScorePacket($buffer);
                    $setScore->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($setScore);
                    break;
                case ProtocolInfo::LEVEL_EVENT_PACKET:
                    $levelEvent = new LevelEventPacket($buffer);
                    $levelEvent->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($levelEvent);
                    break;
                case ProtocolInfo::LEVEL_SOUND_EVENT_PACKET:
                    $sound = new LevelSoundEventPacket($buffer);
                    $sound->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($sound);
                    break;
                case ProtocolInfo::MOB_EQUIPMENT_PACKET:
                    $mobEquip = new MobEquipmentPacket($buffer);
                    $mobEquip->decode();
                    $mobEquip->entityRuntimeId = $this->proxyEntityID;
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($mobEquip);
                    break;
                case ProtocolInfo::UPDATE_BLOCK_PACKET:
                    $updateBlock = new UpdateBlockPacket($buffer);
                    $updateBlock->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($updateBlock);
                    break;
                case ProtocolInfo::SET_TITLE_PACKET:
                    $setTitle = new SetTitlePacket($buffer);
                    $setTitle->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($setTitle);
                    break;
                case ProtocolInfo::PLAYER_LIST_PACKET:
                    $playerList = new PlayerListPacket($buffer);
                    $playerList->decode();
                    // can hook :P
                    // TODO: cache player IDS and remove when switching server
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($playerList);
                    break;
                case ProtocolInfo::MODAL_FORM_REQUEST_PACKET:
                    $modalReq = new ModalFormRequestPacket($buffer);
                    $modalReq->decode();
                    // can hook :P
                    $this->session->getConnectedClient()->getClientSession()->sendDataPacket($modalReq);
                    break;
                    // TODO: transfer
                default:
                    $packet = PacketPool::getPacket($buffer);
                    $this->session->getProxy()->getLogger()->info("Not implemented S->P {$packet->getName()}");
                    return;
            }
        }
    }
}