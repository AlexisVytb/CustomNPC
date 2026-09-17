<?php

namespace CustomNPC\listener;

use pocketmine\block\Block;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use CustomNPC\entity\NPCEntity;
use CustomNPC\gui\MainGUI;
use CustomNPC\gui\ShopGUI;
use CustomNPC\manager\VisibilityManager;
use CustomNPC\Main;
use CustomNPC\manager\NPCManager;
use CustomNPC\task\RespawnTask;
use CustomNPC\utils\Constants;
use CustomNPC\utils\ItemParser;

class NPCListener implements Listener {

    private NPCManager $npcManager;
    private array $cooldowns = [];
    private array $interactCooldowns = [];

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onWorldLoad(WorldLoadEvent $event): void {
        $world = $event->getWorld();

        Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world): void {
            if($world->isLoaded()) {
                $this->npcManager->spawnWorld($world);
            }
        }), 20);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();

        Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void {
            if(!$player->isConnected()) return;
            $this->npcManager->spawnWorld($player->getWorld());
            $this->npcManager->refreshNPCsForPlayer($player);
        }), 20);
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $name = $event->getPlayer()->getName();
        $this->npcManager->handleQuit($event->getPlayer());

        foreach(array_keys($this->cooldowns) as $key) {
            if(str_starts_with($key, $name . "|")) {
                unset($this->cooldowns[$key]);
            }
        }

        unset($this->interactCooldowns[$name]);
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        if(!($packet instanceof InventoryTransactionPacket)) return;

        $data = $packet->trData;
        if(!($data instanceof UseItemOnEntityTransactionData)) return;
        if($data->getActionType() !== UseItemOnEntityTransactionData::ACTION_INTERACT) return;

        $player = $event->getOrigin()->getPlayer();
        if($player === null) return;

        $entity = $player->getWorld()->getEntity($data->getActorRuntimeId());
        if(!($entity instanceof NPCEntity)) return;

        $uuid = $entity->getNpcUuid();
        $npcData = $this->npcManager->getNPCData($uuid);
        if($uuid === "" || $npcData === null) return;
        if(!VisibilityManager::canSee($player, $npcData)) return;

        $event->cancel();

        Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $uuid): void {
            if($player->isConnected()) {
                $this->handleInteraction($player, $uuid);
            }
        }), 1);
    }

    private function handleInteraction(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $item = $player->getInventory()->getItemInHand();

        if($this->isWand($item)) {
            if($player->hasPermission("customnpc.wand") || $this->npcManager->isAdmin($player->getName())) {
                $this->npcManager->select($player, $uuid);
                (new MainGUI($this->npcManager))->open($player, $uuid);
            }
            return;
        }

        if($this->npcManager->isWaitingForUuid($player->getName())) {
            $player->sendMessage("§eUUID: §a" . $uuid);
            $customId = (string)($data["customId"] ?? "");
            if($customId !== "") {
                $player->sendMessage("§eID: §a" . $customId);
            }
            $this->npcManager->setWaitingForUuid($player->getName(), false);
            return;
        }

        $cooldown = max(0, (int)Main::getInstance()->getConfig()->getNested("settings.interact-cooldown", 1));
        $now = time();
        $last = $this->interactCooldowns[$player->getName()] ?? 0;

        if($cooldown > 0 && $now - $last < $cooldown) return;
        $this->interactCooldowns[$player->getName()] = $now;

        $this->playInteractSound($player, $data);

        $plugin = Main::getInstance();
        $runner = $plugin->getDialogueRunner();
        $usedTree = false;

        if($runner->hasTree($uuid)) {
            $runner->start($player, $uuid);
            $usedTree = true;
        } elseif($data["dialogueEnabled"] ?? false) {
            $this->playDialogue($player, $uuid, $data);
        }

        if(!$usedTree && ($data["shop"]["enabled"] ?? false)) {
            (new ShopGUI($this->npcManager, $plugin->getShopManager()))->open($player, $uuid);
        }

        if($data["commandEnabled"] ?? false) {
            $this->executeCommands($player, $uuid, $data);
        }
    }

    private function playInteractSound(Player $player, array $data): void {
        $sound = (string)($data["interactSound"] ?? "");
        if($sound === "") return;

        $player->getNetworkSession()->sendDataPacket(
            \pocketmine\network\mcpe\protocol\PlaySoundPacket::create(
                $sound,
                $player->getPosition()->x,
                $player->getPosition()->y,
                $player->getPosition()->z,
                1.0,
                1.0
            )
        );
    }

    private function playDialogue(Player $player, string $uuid, array $data): void {
        $lines = $data["dialogue"] ?? [];
        if(empty($lines)) return;

        $delay = max(1, (int)($data["dialogueDelay"] ?? 20));
        $prefix = "§e" . $this->npcManager->applyPlaceholders((string)($data["title"] ?? "NPC"), $uuid) . "§r §7> §f";
        $scheduler = Main::getInstance()->getScheduler();

        $index = 0;
        foreach($lines as $line) {
            if(!is_string($line) || trim($line) === "") continue;

            $text = str_replace("{player}", $player->getName(), $line);
            $text = $this->npcManager->applyPlaceholders($text, $uuid);

            $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player, $prefix, $text): void {
                if($player->isConnected()) {
                    $player->sendMessage($prefix . $text);
                }
            }), $index * $delay);

            $index++;
        }
    }

    private function executeCommands(Player $player, string $uuid, array $data): void {
        $commands = $data["commands"] ?? [];
        if(empty($commands)) return;

        $playerName = $player->getName();
        $usedOnce = $data["usedOnce"] ?? [];
        $changed = false;

        foreach($commands as $entry) {
            if(is_string($entry)) {
                $entry = [
                    "id" => md5($entry),
                    "command" => $entry,
                    "executor" => "console",
                    "cooldown" => 0,
                    "permission" => null,
                    "oneTime" => false
                ];
            }

            if(!is_array($entry) || empty($entry["command"])) continue;

            $id = (string)($entry["id"] ?? md5((string)$entry["command"]));

            if(!empty($entry["permission"]) && !$player->hasPermission((string)$entry["permission"])) {
                $player->sendMessage("§cPermission requise : §e" . $entry["permission"]);
                continue;
            }

            if($entry["oneTime"] ?? false) {
                $key = strtolower($playerName) . "|" . $id;
                if(in_array($key, $usedOnce, true)) {
                    $player->sendMessage("§cCommande deja utilisee.");
                    continue;
                }
                $usedOnce[] = $key;
                $changed = true;
            }

            $cooldown = max(0, (int)($entry["cooldown"] ?? 0));
            if($cooldown > 0) {
                $key = $playerName . "|" . $uuid . "|" . $id;
                $now = time();
                $last = $this->cooldowns[$key] ?? 0;
                $remaining = ($last + $cooldown) - $now;

                if($remaining > 0) {
                    $player->sendMessage("§cEn cooldown : §e" . $remaining . "s");
                    continue;
                }

                $this->cooldowns[$key] = $now;
            }

            $command = $this->replacePlaceholders((string)$entry["command"], $player);
            $executor = (string)($entry["executor"] ?? "console");

            try {
                if($executor === "player") {
                    Server::getInstance()->dispatchCommand($player, $command);
                } else {
                    Server::getInstance()->dispatchCommand(Server::getInstance()->getConsoleSender(), $command);
                }
            } catch(\Throwable $e) {
                Main::getInstance()->getLogger()->error("Erreur commande NPC : " . $e->getMessage());
            }
        }

        if($changed) {
            $this->npcManager->updateNPCData($uuid, ["usedOnce" => $usedOnce]);
        }
    }

    private function replacePlaceholders(string $command, Player $player): string {
        $location = $player->getLocation();

        return str_replace(
            ["{player}", "{x}", "{y}", "{z}", "{world}", "{xuid}", "{uuid}"],
            [
                $player->getName(),
                (string)(int)$location->x,
                (string)(int)$location->y,
                (string)(int)$location->z,
                $player->getWorld()->getFolderName(),
                $player->getXuid(),
                $player->getUniqueId()->toString()
            ],
            $command
        );
    }

    public function isWand(Item $item): bool {
        return $item->getNamedTag()->getTag(Constants::WAND_TAG) !== null;
    }

    public function isNPCItem(Item $item): bool {
        return $item->getNamedTag()->getTag(Constants::ITEM_TAG) !== null;
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if($this->isWand($item)) {
            $event->cancel();

            if(!$player->hasPermission("customnpc.wand") && !$this->npcManager->isAdmin($player->getName())) {
                return;
            }

            if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK) {
                $this->wandEdit($player);
            } elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
                $this->wandCreate($player);
            }
            return;
        }

        if($this->isNPCItem($item)) {
            $event->cancel();

            if(!$player->hasPermission("customnpc.create") && !$this->npcManager->isAdmin($player->getName())) {
                return;
            }

            $this->placeNPCItem($player, $item, $event->getBlock());
        }
    }

    private function wandEdit(Player $player): void {
        $radius = (float)Main::getInstance()->getConfig()->getNested("settings.wand-detection-radius", 5.0);
        $uuid = $this->npcManager->findLookedAt($player, $radius);

        if($uuid === null) {
            $player->sendMessage("§cAucun NPC a proximite.");
            return;
        }

        $this->npcManager->select($player, $uuid);
        (new MainGUI($this->npcManager))->open($player, $uuid);
    }

    private function wandCreate(Player $player): void {
        $worldName = $player->getWorld()->getFolderName();
        $max = (int)Main::getInstance()->getConfig()->getNested("settings.max-npcs-per-world", 0);

        if($max > 0 && $this->npcManager->countInWorld($worldName) >= $max) {
            $player->sendMessage("§cLimite de NPCs atteinte dans ce monde (" . $max . ").");
            return;
        }

        $position = $player->getPosition();
        $location = $player->getLocation();

        $data = $this->npcManager->getDefaultNPCDataWithRotation(
            $position->x,
            $position->y,
            $position->z,
            $worldName,
            $location->yaw,
            $location->pitch
        );
        $data["creator"] = $player->getName();

        $uuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        $this->npcManager->select($player, $uuid);

        Main::getInstance()->getLogManager()->log($player->getName(), "create", $uuid, "baguette");
        $player->sendMessage("§aNPC cree et selectionne. §7UUID: §e" . $uuid);
    }

    private function placeNPCItem(Player $player, Item $item, Block $block): void {
        $tag = $item->getNamedTag()->getTag(Constants::ITEM_TAG);
        if($tag === null) return;

        $uuid = (string)$item->getNamedTag()->getString(Constants::ITEM_TAG, "");
        if($uuid === "" || $this->npcManager->getNPCData($uuid) === null) {
            $player->sendMessage("§cCe NPC n'existe plus.");
            return;
        }

        $position = $block->getPosition();

        $this->npcManager->updateNPCData($uuid, [
            "position" => [
                "x" => $position->x + 0.5,
                "y" => $position->y + 1,
                "z" => $position->z + 0.5,
                "world" => $player->getWorld()->getFolderName()
            ],
            "stored" => false
        ]);

        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        $this->npcManager->saveNPC($uuid);

        $item->pop();
        $player->getInventory()->setItemInHand($item);
        $player->sendMessage("§aNPC place.");
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        if($this->isWand($event->getItem()) || $this->isNPCItem($event->getItem())) {
            $event->cancel();
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();

        if($entity instanceof NPCEntity) {
            $this->handleNPCDamaged($event, $entity);
            return;
        }

        if($entity instanceof Player && $event instanceof EntityDamageByEntityEvent) {
            $this->handlePlayerDamaged($event, $entity);
        }
    }

    private function handleNPCDamaged(EntityDamageEvent $event, NPCEntity $entity): void {
        $uuid = $entity->getNpcUuid();
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $damager = $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : null;

        if($damager instanceof Player) {
            $item = $damager->getInventory()->getItemInHand();

            if($this->isWand($item)) {
                $event->cancel();

                if($damager->hasPermission("customnpc.wand") || $this->npcManager->isAdmin($damager->getName())) {
                    $this->npcManager->select($damager, $uuid);
                    (new MainGUI($this->npcManager))->open($damager, $uuid);
                }
                return;
            }

            if($this->npcManager->isWaitingForUuid($damager->getName())) {
                $event->cancel();
                $damager->sendMessage("§eUUID: §a" . $uuid);
                $this->npcManager->setWaitingForUuid($damager->getName(), false);
                return;
            }
        }

        if(!($data["canBeHit"] ?? true)) {
            $event->cancel();

            if($damager instanceof Player) {
                $this->handleInteraction($damager, $uuid);
            }
            return;
        }

        if($damager instanceof Player && ($data["aggressive"] ?? false)) {
            $this->npcManager->setTarget($uuid, $damager->getName());
        }

        if($damager instanceof Player) {
            $this->handleInteraction($damager, $uuid);
        }

        $remaining = $entity->getHealth() - $event->getFinalDamage();

        if($remaining > 0) {
            $this->npcManager->updateNPCData($uuid, ["health" => $remaining]);

            if($data["aggressive"] ?? false) {
                Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($uuid): void {
                    $this->npcManager->updateNameTag($uuid);
                }), 1);
            }
        }
    }

    private function handlePlayerDamaged(EntityDamageByEntityEvent $event, Player $victim): void {
        $damager = $event->getDamager();

        if($damager instanceof Projectile) {
            $damager = $damager->getOwningEntity();
        }

        if(!($damager instanceof NPCEntity)) return;

        $data = $this->npcManager->getNPCData($damager->getNpcUuid());
        if($data === null) return;

        $event->setBaseDamage((float)($data["attackDamage"] ?? 1));

        $effect = (string)($data["effectOnHit"] ?? "");
        if($effect !== "") {
            $this->applyEffect($victim, $effect);
        }
    }

    private function applyEffect(Player $player, string $effectId): void {
        $parts = explode(":", $effectId);
        $effect = StringToEffectParser::getInstance()->parse($parts[0]);
        if($effect === null) return;

        $duration = isset($parts[1]) ? max(1, (int)$parts[1]) : 5;
        $amplifier = isset($parts[2]) ? max(0, (int)$parts[2]) : 0;

        $player->getEffects()->add(new EffectInstance($effect, $duration * 20, $amplifier));
    }

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        if(!($entity instanceof NPCEntity)) return;

        $uuid = $entity->getNpcUuid();
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $event->setDrops(ItemParser::deserializeList($data["drops"] ?? []));
        $event->setXpDropAmount(0);

        $this->npcManager->updateNPCData($uuid, [
            "health" => (float)($data["maxHealth"] ?? 100.0),
            "runtimeId" => 0
        ]);
        $this->npcManager->setTarget($uuid, null);

        if($data["autoRespawn"] ?? false) {
            Main::getInstance()->getScheduler()->scheduleDelayedTask(new RespawnTask($this->npcManager, $uuid), 100);
        }

        $this->npcManager->saveNPC($uuid);
    }
}
