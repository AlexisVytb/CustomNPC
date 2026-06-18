<?php

namespace CustomNPC\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\player\Player;
use pocketmine\entity\Living;
use pocketmine\entity\Human;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\location;
use pocketmine\world\World;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\item\VanillaItems;
use pocketmine\scheduler\Task;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use CustomNPC\utils\ItemParser;
use CustomNPC\gui\MainGUI;
use CustomNPC\task\RespawnTask;
use CustomNPC\Main;

class NPCEventListener implements Listener {

    private NPCManager $npcManager;
    private bool $isSendingCustomPackets = false;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onChunkLoad(ChunkLoadEvent $event): void {
        $world = $event->getWorld();
        $chunkX = $event->getChunkX();
        $chunkZ = $event->getChunkZ();
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if($data["position"]["world"] !== $world->getFolderName()) continue;
            
            $npcX = (int)($data["position"]["x"] ?? 0) >> 4;
            $npcZ = (int)($data["position"]["z"] ?? 0) >> 4;
            
            if($npcX === $chunkX && $npcZ === $chunkZ) {
                Main::getInstance()->getScheduler()->scheduleDelayedTask(new class($this->npcManager, $world, $uuid) extends Task {
                    private $manager;
                    private $world;
                    private $uuid;

                    public function __construct($manager, $world, $uuid) {
                        $this->manager = $manager;
                        $this->world = $world;
                        $this->uuid = $uuid;
                    }

                    public function onRun(): void {
                        $this->manager->spawnNPC($this->world, $this->uuid);
                    }
                }, 1);
            }
        }
    }

    public function onChunkUnload(ChunkUnloadEvent $event): void {
        $world = $event->getWorld();
        $chunkX = $event->getChunkX();
        $chunkZ = $event->getChunkZ();
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
             if($data["position"]["world"] !== $world->getFolderName()) continue;
             
             $npcX = (int)($data["position"]["x"] ?? 0) >> 4;
             $npcZ = (int)($data["position"]["z"] ?? 0) >> 4;
             
             if($npcX === $chunkX && $npcZ === $chunkZ) {
             }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        $block = $event->getBlock();

        if($item->getCustomName() === Constants::NPC_WAND_NAME) {
            $event->cancel();
            
            if(!$player->hasPermission("customnpc.admin") && !$this->npcManager->isAdmin($player->getName())) {
                return;
            }

            if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK) {
                $this->handleWandEditClick($player);
            } elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
                $this->handleWandCreateClick($player);
            }
            return;
        }

        if(strpos($item->getCustomName(), Constants::NPC_ITEM_PREFIX) === 0) {
            $this->handleNPCItemPlace($player, $item, $block);
            $event->cancel();
        }
    }

    private function handleWandEditClick(Player $player): void {
        $playerPos = $player->getPosition();
        $world = $player->getWorld();
        
        $closestNpc = null;
        $closestDistance = Constants::NPC_DETECTION_RADIUS;
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $entityId = $data["runtimeId"] ?? 0;
            $npcEntity = $world->getEntity($entityId);
            
            if($npcEntity !== null) {
                $distance = $npcEntity->getPosition()->distance($playerPos);
                if($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestNpc = $uuid;
                }
            }
        }
        
        if($closestNpc !== null) {
            (new MainGUI($this->npcManager))->open($player, $closestNpc);
            $player->sendMessage("§aNPC trouvé : " . ($this->npcManager->getNPCData($closestNpc)["title"] ?? "NPC"));
        } else {
            $player->sendMessage("§cAucun NPC trouvé à proximité pour édition.");
        }
    }

    private function handleWandCreateClick(Player $player): void {
        $pos = $player->getPosition();
        $location = $player->getLocation();
        
        $data = $this->npcManager->getDefaultNPCDataWithRotation(
            $pos->getX(),
            $pos->getY(),
            $pos->getZ(),
            $player->getWorld()->getFolderName(),
            $location->getYaw(),
            $location->getPitch()
        );
        $data["creator"] = $player->getName();
        
        $uuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        
        $player->sendMessage("§aNPC créé avec ton orientation ! UUID: §e$uuid");
    }

    private function handleNPCItemPlace(Player $player, $item, $block): void {
        $nbt = $item->getNamedTag();
        if($nbt->getTag("npc_uuid") !== null) {
            $uuid = $nbt->getString("npc_uuid");
            $data = $this->npcManager->getNPCData($uuid);
            
            if($data !== null) {
                $this->npcManager->updateNPCData($uuid, [
                    "position" => [
                        "x" => $block->getPosition()->getX() + 0.5,
                        "y" => $block->getPosition()->getY() + 1,
                        "z" => $block->getPosition()->getZ() + 0.5,
                        "world" => $player->getWorld()->getFolderName()
                    ]
                ]);
                
                $this->npcManager->spawnNPC($player->getWorld(), $uuid);
                $this->npcManager->saveNPC($uuid);
                
                $item->pop();
                $player->getInventory()->setItemInHand($item);
                
                $player->sendMessage("§aNPC placé !");
            }
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        $entityId = $entity->getId();

        if($entity instanceof Player && $event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            
            if($damager instanceof Projectile) {
                $owner = $damager->getOwningEntity();
                if($owner instanceof Human) {
                    $attackerUuid = $this->npcManager->findNPCByEntityId($owner->getId());
                    if($attackerUuid !== null) {
                        $attackerData = $this->npcManager->getNPCData($attackerUuid);
                        if($attackerData !== null) {
                            $effectId = $attackerData["effectOnHit"] ?? "";
                            if($effectId !== "") {
                                $this->applyEffect($entity, $effectId);
                            }
                        }
                    }
                }
            }
            elseif($damager instanceof Human) {
                $attackerUuid = $this->npcManager->findNPCByEntityId($damager->getId());
                if($attackerUuid !== null) {
                    $attackerData = $this->npcManager->getNPCData($attackerUuid);
                    if($attackerData !== null) {
                        $effectId = $attackerData["effectOnHit"] ?? "";
                        if($effectId !== "") {
                            $this->applyEffect($entity, $effectId);
                        }
                    }
                }
            }
        }

        $npcUuid = $this->npcManager->findNPCByEntityId($entityId);

        if($npcUuid === null && $entity instanceof \pocketmine\entity\Human) {
            $pos = $entity->getPosition();
            foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
                $npcPos = $data["position"];
                if($npcPos["world"] === $pos->getWorld()->getFolderName()) {
                    $distance = sqrt(
                        pow($pos->x - $npcPos["x"], 2) +
                        pow($pos->y - $npcPos["y"], 2) +
                        pow($pos->z - $npcPos["z"], 2)
                    );
                    if($distance < 0.5) {
                        $npcUuid = $uuid;
                        break;
                    }
                }
            }
        }
        
        if($npcUuid === null) return;
        
        $npcData = $this->npcManager->getNPCData($npcUuid);
        if($npcData === null) return;
        
        Main::getInstance()->debugLog("NPC '{$npcData['title']}' ({$npcUuid}) received EntityDamageEvent (cause: " . $event->getCause() . ", raw damage: " . $event->getBaseDamage() . ", final damage: " . $event->getFinalDamage() . ")");
        
        $canBeHit = $npcData["canBeHit"] ?? true;
        
        if(!$canBeHit){
    $event->cancel();

    if($event instanceof EntityDamageByEntityEvent){
        $damager = $event->getDamager();

        if($damager instanceof Player){
            $item = $damager->getInventory()->getItemInHand();

            if($item->getCustomName() === Constants::NPC_WAND_NAME){
                (new MainGUI($this->npcManager))->open($damager, $npcUuid);
                return;
            }

            if(($npcData["commandEnabled"] ?? false) === true){
                $this->executeCommands($npcData["commands"] ?? [], $damager);
            }
        }
    }

    return;
}

        if($event instanceof EntityDamageByEntityEvent) {
            $this->handleEntityDamageByEntity($event, $npcUuid, $npcData, $entity);
        }

        if($entity instanceof Living) {
            $newHealth = $entity->getHealth() - $event->getFinalDamage();
            if($newHealth > 0) {
                $this->npcManager->updateNPCData($npcUuid, ["health" => $newHealth]);
            }
        }

        if($entity instanceof Living && $entity->getHealth() - $event->getFinalDamage() <= 0) {
            if($npcData["autoRespawn"] ?? false) {
                Main::getInstance()->getScheduler()->scheduleDelayedTask(
                    new RespawnTask($this->npcManager, $npcUuid), 
                    100
                );
            }
        }

        if($entity instanceof Living && ($npcData["aggressive"] ?? false)) {
            Main::getInstance()->getScheduler()->scheduleDelayedTask(new class($this->npcManager, $entity, $npcUuid) extends Task {
                private $manager;
                private $entity;
                private $uuid;

                public function __construct($manager, $entity, $uuid) {
                    $this->manager = $manager;
                    $this->entity = $entity;
                    $this->uuid = $uuid;
                }

                public function onRun(): void {
                    if(!$this->entity->isClosed()) {
                        $this->manager->updateNameTag($this->entity, $this->uuid);
                    }
                }
            }, 1);
        }
    }

    private function handleEntityDamageByEntity(EntityDamageByEntityEvent $event, string $npcUuid, array $npcData, $entity): void {
        $damager = $event->getDamager();

        if($damager instanceof Player) {
            $item = $damager->getInventory()->getItemInHand();
            
            if($item->getCustomName() === Constants::NPC_WAND_NAME) {
                (new MainGUI($this->npcManager))->open($damager, $npcUuid);
                $event->cancel();
                return;
            }
            if($this->npcManager->isWaitingForUuid($damager->getName())) {
                $damager->sendMessage("§eUUID du NPC: §a" . $npcUuid);
                $this->npcManager->setWaitingForUuid($damager->getName(), false);
                $event->cancel();
                return;
            }

            $this->executeCommands($npcData["commands"] ?? [], $damager);

            if($npcData["aggressive"] ?? false) {
                $this->npcManager->setTarget($npcUuid, $damager->getName());
            }

            if($entity instanceof Living && ($npcData["aggressive"] ?? false)) {
                $this->npcManager->updateNameTag($entity, $npcUuid);
            }
        }

        $victim = $event->getEntity();

        if($damager instanceof Projectile) {
            $owner = $damager->getOwningEntity();
            if($owner instanceof Human) {
                $ownerUuid = $this->npcManager->findNPCByEntityId($owner->getId());
                if($ownerUuid !== null) {
                    $ownerData = $this->npcManager->getNPCData($ownerUuid);
                    if($ownerData !== null && isset($ownerData["effectOnHit"])) {
                         $effectOnHit = $ownerData["effectOnHit"];
                         if($victim instanceof Player && $effectOnHit !== "") {
                             $this->applyEffect($victim, $effectOnHit);
                         }
                    }
                }
            }
        }

        if($victim instanceof Player && !($damager instanceof Player)){
            if(isset($npcData["attackDamage"])){
                $event->setBaseDamage((float) $npcData["attackDamage"]);
            }

            $realDamager = $damager;
            if($damager instanceof Projectile) {
                $realDamager = $damager->getOwningEntity();
            }

            if($realDamager instanceof Human) {
                 $attackerUuid = $this->npcManager->findNPCByEntityId($realDamager->getId());
                 if($attackerUuid !== null) {
                      $attackerData = $this->npcManager->getNPCData($attackerUuid);
                      if($attackerData !== null) {
                           $effectId = $attackerData["effectOnHit"] ?? "";
                           if($effectId !== "" && $victim instanceof Player){
                               $this->applyEffect($victim, $effectId);
                           }
                      }
                 }
            }
        }
    }

    private function executeCommands(array $commands, Player $player): void {
        if(empty($commands)) return;
        
        $commandStrings = array_map(function($cmd) {
            return is_array($cmd) ? json_encode($cmd) : (string)$cmd;
        }, $commands);
        Main::getInstance()->debugLog("NPC command execution triggered for player {$player->getName()}: " . implode(", ", $commandStrings));
        foreach($commands as $cmd) {
            if(is_string($cmd) && !empty(trim($cmd))) {
                $cmd = str_replace("{player}", $player->getName(), $cmd);
                try {
                    Main::getInstance()->getServer()->dispatchCommand(
                        Main::getInstance()->getServer()->getConsoleSender(), 
                        $cmd
                    );
                } catch(\Exception $e) {
                }
            }
        }
    }

    private function applyEffect(Player $player, string $effectId): void {
        $parts = explode(":", $effectId);
        $effectName = $parts[0];
        $durationSeconds = isset($parts[1]) ? (int)$parts[1] : 5; // Default 5 seconds
        $amplifier = isset($parts[2]) ? (int)$parts[2] : 0;
        
        $durationTicks = $durationSeconds * 20; // Convert seconds to ticks

        $effect = StringToEffectParser::getInstance()->parse($effectName);
        if($effect !== null) {
            $player->getEffects()->add(new EffectInstance($effect, $durationTicks, $amplifier));
        }
    }

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        $npcUuid = $this->npcManager->findNPCByEntityId($entity->getId());
        
        if($npcUuid === null) return;

        $npcData = $this->npcManager->getNPCData($npcUuid);
        if($npcData === null) return;

        $event->setDrops([]);
        
        if(!empty($npcData["drops"]) && is_array($npcData["drops"])) {
            $customDrops = [];
            foreach($npcData["drops"] as $dropString) {
                if(is_string($dropString) && !empty(trim($dropString))) {
                    $item = ItemParser::parse($dropString);
                    if($item !== null) {
                        $customDrops[] = $item;
                    }
                }
            }
            $event->setDrops($customDrops);
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        if($item->getCustomName() === Constants::NPC_WAND_NAME) {
            $event->cancel();
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $this->npcManager->refreshNPCsForPlayer($event->getPlayer());
    }

    public function onEntityTeleport(EntityTeleportEvent $event): void {
        $entity = $event->getEntity();
        if($entity instanceof Player) {
            Main::getInstance()->getScheduler()->scheduleDelayedTask(new class($this->npcManager, $entity) extends Task {
                private NPCManager $manager;
                private Player $player;
                public function __construct(NPCManager $manager, Player $player) {
                    $this->manager = $manager;
                    $this->player = $player;
                }
                public function onRun(): void {
                    if(!$this->player->isClosed()) {
                        $this->manager->refreshNPCsForPlayer($this->player);
                    }
                }
            }, 10);
        }
    }

    public function onPacketSend(DataPacketSendEvent $event): void {
        if($this->isSendingCustomPackets) return;

        $packets = $event->getPackets();
        $targets = $event->getTargets();
        
        $hasNpcPacket = false;
        foreach($packets as $pk) {
            if($pk instanceof AddPlayerPacket || $pk instanceof SetActorDataPacket) {
                $hasNpcPacket = true;
                break;
            }
        }
        
        if(!$hasNpcPacket) return;
        
        $admins = [];
        $nonAdmins = [];
        foreach($targets as $target) {
            $player = $target->getPlayer();
            if($player !== null && $this->npcManager->isAdmin($player->getName())) {
                $admins[] = $target;
            } else {
                $nonAdmins[] = $target;
            }
        }
        
        if(empty($admins)) return;
        
        if(empty($nonAdmins)) {
            $newPackets = [];
            foreach($packets as $pk) {
                $newPackets[] = $this->modifyPacketForAdmins($pk);
            }
            $event->setPackets($newPackets);
            return;
        }
        
        $event->cancel();
        
        $this->isSendingCustomPackets = true;
        
        foreach($nonAdmins as $session) {
            foreach($packets as $pk) {
                $session->sendDataPacket($pk);
            }
        }
        
        foreach($admins as $session) {
            foreach($packets as $pk) {
                $session->sendDataPacket($this->modifyPacketForAdmins(clone $pk));
            }
        }
        
        $this->isSendingCustomPackets = false;
    }

    private function modifyPacketForAdmins(\pocketmine\network\mcpe\protocol\ClientboundPacket $packet): \pocketmine\network\mcpe\protocol\ClientboundPacket {
        if($packet instanceof AddPlayerPacket) {
            $entityId = $packet->actorRuntimeId;
            $uuid = $this->npcManager->findNPCByEntityId($entityId);
            if($uuid !== null) {
                $npcData = $this->npcManager->getNPCData($uuid);
                $creator = $npcData["creator"] ?? "";
                if($creator !== "") {
                    $metadata = $packet->metadata;
                    $title = $npcData["title"] ?? "NPC";
                    $subtitle = $npcData["subtitle"] ?? "";
                    $nameTag = $title;
                    if($subtitle !== "") {
                        $nameTag .= "\n" . $subtitle;
                    }
                    if($npcData["aggressive"] ?? false) {
                        $nameTag .= "\n§c" . (int)($npcData["health"] ?? 100) . " §r/ §c" . (int)($npcData["maxHealth"] ?? 100);
                    }
                    $adminTag = $nameTag . "\n§l§cPlacer : §f" . $creator;
                    
                    $metadata[EntityMetadataProperties::NAMETAG] = new StringMetadataProperty($adminTag);
                    $packet->metadata = $metadata;
                }
            }
        } elseif($packet instanceof SetActorDataPacket) {
            $entityId = $packet->actorRuntimeId;
            $uuid = $this->npcManager->findNPCByEntityId($entityId);
            if($uuid !== null) {
                $npcData = $this->npcManager->getNPCData($uuid);
                $creator = $npcData["creator"] ?? "";
                if($creator !== "") {
                    $metadata = $packet->metadata;
                    $originalTag = "";
                    if(isset($metadata[EntityMetadataProperties::NAMETAG])) {
                        $originalTag = $metadata[EntityMetadataProperties::NAMETAG]->getValue();
                    } else {
                        $title = $npcData["title"] ?? "NPC";
                        $subtitle = $npcData["subtitle"] ?? "";
                        $originalTag = $title;
                        if($subtitle !== "") {
                            $originalTag .= "\n" . $subtitle;
                        }
                    }
                    
                    if(strpos($originalTag, "§l§cPlacer :") === false) {
                        $adminTag = $originalTag . "\n§l§cPlacer : §f" . $creator;
                        $metadata[EntityMetadataProperties::NAMETAG] = new StringMetadataProperty($adminTag);
                        $packet->metadata = $metadata;
                    }
                }
            }
        }
        return $packet;
    }
}