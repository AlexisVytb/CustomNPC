<?php

namespace CustomNPC\command;

use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use CustomNPC\gui\MainGUI;
use CustomNPC\gui\ArmorGUI;

class NPCCommandHandler {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function handleCommand(Player $player, string $commandName, array $args): bool {
        switch($commandName) {
            case "npcwand":
                return $this->handleWandCommand($player);
            
            case "npcspawn":
                return $this->handleSpawnCommand($player);
            
            case "npcdelete":
                return $this->handleDeleteCommand($player, $args);
            
            case "npcarmor":
                return $this->handleArmorCommand($player, $args);
            
            case "npclist":
                return $this->handleListCommand($player);
            
            case "npcskin":
                return $this->handleSkinCommand($player, $args);
            
            case "npclistskins":
                return $this->handleListSkinsCommand($player);
            
            case "npcdebug":
                return $this->handleDebugCommand($player);
            
            case "npcrefresh":
                return $this->handleRefreshCommand($player);
            
            case "npcrotate":
                return $this->handleRotateCommand($player, $args);
            
            default:
                return false;
        }
    }

    private function handleWandCommand(Player $player): bool {
        $wand = VanillaItems::WOODEN_HOE()->setCustomName(Constants::NPC_WAND_NAME);
        $player->getInventory()->addItem($wand);
        $player->sendMessage("§aTu as reçu la NPC Wand !");
        return true;
    }

    private function handleSpawnCommand(Player $player): bool {
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
        
        $uuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        
        $player->sendMessage("§aNPC créé avec ton orientation ! UUID: §e$uuid");
        return true;
    }

    private function handleDeleteCommand(Player $player, array $args): bool {
        $uuid = $args[0] ?? "";
        
        if($uuid === "" || $this->npcManager->getNPCData($uuid) === null) {
            $player->sendMessage("§cUUID invalide !");
            return true;
        }

        $this->npcManager->deleteNPC($uuid);
        $player->sendMessage("§aNPC supprimé !");
        return true;
    }

    private function handleArmorCommand(Player $player, array $args): bool {
        $uuid = $args[0] ?? "";
        
        if($uuid === "" || $this->npcManager->getNPCData($uuid) === null) {
            $player->sendMessage("§cUUID invalide !");
            return true;
        }
        
        (new ArmorGUI($this->npcManager))->open($player, $uuid);
        return true;
    }

    private function handleListCommand(Player $player): bool {
        $player->sendMessage("§e=== Liste des NPCs ===");
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $player->sendMessage("§7- §a" . $data["title"] . " §7(§e" . $uuid . "§7)");
        }
        
        $count = count($this->npcManager->getAllNPCData());
        $player->sendMessage("§eTotal: §a" . $count . " NPCs");
        return true;
    }

    private function handleSkinCommand(Player $player, array $args): bool {
        $player->sendMessage("§c⚠ Les skins custom ne fonctionnent pas sur Bedrock Edition !");
        $player->sendMessage("§7Cette commande a été désactivée.");
        $player->sendMessage("§7Utilise l'armure pour personnaliser l'apparence des NPCs.");
        return true;
    }

    private function handleListSkinsCommand(Player $player): bool {
        $player->sendMessage("§c⚠ Les skins custom ne fonctionnent pas sur Bedrock Edition !");
        $player->sendMessage("§7Cette commande a été désactivée.");
        return true;
    }

    private function handleDebugCommand(Player $player): bool {
        $logger = \CustomNPC\Main::getInstance()->getLogger();
        $logger->info("=== DEBUG NPCs ===");
        
        $debug = $this->npcManager->debugMappings();
        
        $logger->info("=== MAPPINGS (entityId → uuid) ===");
        foreach($debug["mappings"] as $entityId => $uuid) {
            $logger->info("  $entityId → $uuid");
        }
        
        $logger->info("=== NPCs DATA ===");
        foreach($debug["npcs"] as $uuid => $npcData) {
            $logger->info("  $uuid: runtimeId={$npcData['runtimeId']}, canBeHit=" . ($npcData['canBeHit'] ? 'TRUE' : 'FALSE') . ", title={$npcData['title']}");
        }
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $entityId = $data["runtimeId"] ?? 0;
            $world = \CustomNPC\Main::getInstance()->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            $exists = false;
            $actualHealth = "N/A";
            
            if($world !== null) {
                $entity = $world->getEntity($entityId);
                $exists = $entity !== null && !$entity->isClosed();
                if($exists && $entity instanceof \pocketmine\entity\Living) {
                    $actualHealth = (int)$entity->getHealth() . "/" . (int)$entity->getMaxHealth();
                }
            }
            
            $logger->info(
                "NPC '$uuid': " .
                "EntityID=$entityId, " .
                "Exists=" . ($exists ? "OUI" : "NON") . ", " .
                "Health={$data['health']}/{$data['maxHealth']}, " .
                "ActualHealth=$actualHealth"
            );
        }
        
        $player->sendMessage("§aVoir la console pour les infos de debug");
        return true;
    }

    private function handleRefreshCommand(Player $player): bool {
        $world = $player->getWorld();
        $count = 0;
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if($data["position"]["world"] === $world->getFolderName()) {
                $this->npcManager->updateNPC($world, $uuid);
                $count++;
            }
        }
        
        $player->sendMessage("§a$count NPCs rafraîchis dans ce monde !");
        return true;
    }
}