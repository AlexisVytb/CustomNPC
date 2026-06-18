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
        if($commandName === "npcadmin") {
            return $this->handleNpcAdminCommand($player, ["admin"]);
        }

        if($commandName !== "npc") {
            return false;
        }

        if(empty($args)) {
            $this->sendHelpMessage($player);
            return true;
        }

        $subCommand = strtolower($args[0]);
        $subArgs = array_slice($args, 1);

        switch($subCommand) {
            case "admin":
                return $this->handleNpcAdminCommand($player, ["admin"]);
            case "wand":
                return $this->handleWandCommand($player);
            case "delete":
                return $this->handleDeleteCommand($player, $subArgs);
            case "rotate":
                return $this->handleRotateCommand($player, $subArgs);
            case "armor":
                return $this->handleArmorCommand($player, $subArgs);
            case "list":
                return $this->handleListCommand($player);
            case "skin":
                return $this->handleSkinCommand($player, $subArgs);
            case "listskins":
                return $this->handleListSkinsCommand($player);
            case "debug":
                return $this->handleDebugCommand($player);
            case "refresh":
                return $this->handleRefreshCommand($player);
            case "uuid":
                return $this->handleUuidCommand($player);
            case "fakeplayer":
                return $this->handleFakePlayerCommand($player, $subArgs);
            default:
                $this->sendHelpMessage($player);
                return true;
        }
    }

    private function sendHelpMessage(Player $player): void {
        $player->sendMessage("§e=== Commandes CustomNPC ===");
        $player->sendMessage("§b/npc admin §7- Activer/désactiver le mode admin (affiche les tags de créateurs)");
        $player->sendMessage("§b/npc wand §7- Obtenir la baguette NPC");
        $player->sendMessage("§b/npc delete <uuid> §7- Supprimer un NPC");
        $player->sendMessage("§b/npc rotate <uuid> §7- Tourner un NPC vers toi");
        $player->sendMessage("§b/npc armor <uuid> §7- Configurer l'armure d'un NPC");
        $player->sendMessage("§b/npc list §7- Lister tous les NPCs");
        $player->sendMessage("§b/npc skin <uuid> <skin> §7- Changer le skin d'un NPC");
        $player->sendMessage("§b/npc listskins §7- Lister les skins disponibles");
        $player->sendMessage("§b/npc debug §7- Voir les informations de debug dans la console");
        $player->sendMessage("§b/npc refresh §7- Rafraîchir les NPCs de ton monde");
        $player->sendMessage("§b/npc uuid §7- Activer le clic pour voir l'UUID d'un NPC");
        $player->sendMessage("§b/npc fakeplayer <uuid> §7- Configurer un NPC en Fake Player");
    }

    private function handleNpcAdminCommand(Player $player, array $args): bool {
        if(!isset($args[0]) || strtolower($args[0]) !== "admin") {
            $player->sendMessage("§cUsage: /npc admin");
            return true;
        }

        $isAdmin = $this->npcManager->isAdmin($player->getName());
        $this->npcManager->setAdmin($player->getName(), !$isAdmin);

        if(!$isAdmin) {
            // On active le mode admin
            $wand = \pocketmine\item\VanillaItems::WOODEN_HOE()->setCustomName(Constants::NPC_WAND_NAME);
            $player->getInventory()->addItem($wand);
            $player->sendMessage("§a§lMode NPC Admin activé !");
            $player->sendMessage("§eTu as reçu la NPC Wand.");
            $player->sendMessage("§7Clic gauche = Éditer | Clic droit = Créer un NPC");
        } else {
            // On désactive le mode admin
            foreach($player->getInventory()->getContents() as $index => $item) {
                if($item->getCustomName() === Constants::NPC_WAND_NAME) {
                    $player->getInventory()->setItem($index, \pocketmine\item\VanillaItems::AIR());
                }
            }
            $player->sendMessage("§c§lMode NPC Admin désactivé.");
            $player->sendMessage("§eLa NPC Wand a été retirée de ton inventaire.");
        }

        // Rafraîchir les nametags pour ce joueur
        $this->npcManager->refreshNPCsForPlayer($player);
        return true;
    }

    private function handleWandCommand(Player $player): bool {
        $wand = VanillaItems::WOODEN_HOE()->setCustomName(Constants::NPC_WAND_NAME);
        $player->getInventory()->addItem($wand);
        $player->sendMessage("§aTu as reçu la NPC Wand !");
        return true;
    }

    private function handleSpawnCommand(Player $player, ?array $args = null): bool {
        if(isset($args[0]) && $args[0] !== "") {
             $uuid = $args[0];
             $data = $this->npcManager->getNPCData($uuid);
             
             if($data === null) {
                 $player->sendMessage("§cNPC introuvable avec cet UUID !");
                 return true;
             }
             
             $pos = $player->getPosition();
             $location = $player->getLocation();
             
             $this->npcManager->updateNPCData($uuid, [
                "position" => [
                    "x" => $pos->getX(),
                    "y" => $pos->getY(),
                    "z" => $pos->getZ(),
                    "world" => $player->getWorld()->getFolderName()
                ],
                "yaw" => $location->getYaw(),
                "pitch" => $location->getPitch()
             ]);
             
             $this->npcManager->saveNPC($uuid);
             $this->npcManager->respawnNPC($uuid);
             
             $player->sendMessage("§aNPC {$uuid} respawn (déplacé sur toi) !");
              return true;
        }

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
        return true;
    }

    private function handleUuidCommand(Player $player): bool {
        if($this->npcManager->isWaitingForUuid($player->getName())) {
            $this->npcManager->setWaitingForUuid($player->getName(), false);
            $player->sendMessage("§cMode détection d'UUID désactivé.");
        } else {
            $this->npcManager->setWaitingForUuid($player->getName(), true);
            $player->sendMessage("§aMode détection activé ! Tape un NPC pour voir son UUID.");
        }
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
        if(count($args) < 2) {
            $player->sendMessage("§cUsage: /npcskin <uuid> <skin>");
            $player->sendMessage("§7Exemples:");
            $player->sendMessage("§7- /npcskin npc_123 player:Notch");
            $player->sendMessage("§7- /npcskin npc_123 steve");
            return true;
        }
        
        $uuid = $args[0];
        $skinPath = $args[1];
        
        if($this->npcManager->getNPCData($uuid) === null) {
            $player->sendMessage("§cNPC introuvable !");
            return true;
        }
        
        $success = $this->npcManager->changeSkin($uuid, $skinPath);
        
        if($success) {
            $player->sendMessage("§aSkin changé avec succès !");
        } else {
            $player->sendMessage("§cErreur lors du changement de skin !");
        }
        
        return true;
    }

    private function handleListSkinsCommand(Player $player): bool {
        $skinManager = $this->npcManager->getSkinManager();
        $skinsFolder = \CustomNPC\Main::getInstance()->getDataFolder() . "skins/";
        
        $player->sendMessage("§e=== Skins disponibles ===");
        $player->sendMessage("§7Format: §b/npcskin <uuid> <skin>");
        $player->sendMessage("");
        $player->sendMessage("§aTypes de skins:");
        $player->sendMessage("§7- §eplayer:<pseudo> §7(skin d'un joueur)");
        $player->sendMessage("§7- §esteve §7(skin par défaut)");
        $player->sendMessage("§7- §ealex §7(skin Alex)");
        
        if(is_dir($skinsFolder)) {
            $skins = array_diff(scandir($skinsFolder), ['.', '..']);
            $pngSkins = array_filter($skins, fn($file) => str_ends_with($file, '.png'));
            
            if(!empty($pngSkins)) {
                $player->sendMessage("");
                $player->sendMessage("§eSkins personnalisés:");
                foreach($pngSkins as $skin) {
                    $skinName = str_replace('.png', '', $skin);
                    $player->sendMessage("§7- §b$skinName");
                }
            }
        }
        
        return true;
    }

    private function handleDebugCommand(Player $player): bool {
        $logger = \CustomNPC\Main::getInstance()->getLogger();
        $logger->info("=== DEBUG NPCs ===");
        
        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $entityId = $data["runtimeId"] ?? 0;
            $world = \CustomNPC\Main::getInstance()->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            $exists = false;
            $actualHealth = "N/A";
            $canBeHit = $data["canBeHit"] ?? true;
            $commandEnabled = $data["commandEnabled"] ?? false;
            $commandCount = count($data["commands"] ?? []);
            
            if($world !== null) {
                $entity = $world->getEntity($entityId);
                $exists = $entity !== null && !$entity->isClosed();
                if($exists && $entity instanceof \pocketmine\entity\Living) {
                    $actualHealth = (int)$entity->getHealth() . "/" . (int)$entity->getMaxHealth();
                }
            }
            
            $logger->info(
                "NPC '$uuid' ({$data['title']}): " .
                "EntityID=$entityId, " .
                "Exists=" . ($exists ? "OUI" : "NON") . ", " .
                "Health={$data['health']}/{$data['maxHealth']}, " .
                "ActualHealth=$actualHealth, " .
                "CanBeHit=" . ($canBeHit ? "OUI" : "NON") . ", " .
                "CommandEnabled=" . ($commandEnabled ? "OUI" : "NON") . ", " .
                "Commands=$commandCount"
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

    private function handleRotateCommand(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npcrotate <uuid>");
            return true;
        }
        
        $uuid = $args[0];
        $npcData = $this->npcManager->getNPCData($uuid);
        
        if($npcData === null) {
            $player->sendMessage("§cNPC introuvable !");
            return true;
        }
        
        $location = $player->getLocation();
        
        $this->npcManager->updateNPCData($uuid, [
            "yaw" => $location->getYaw(),
            "pitch" => $location->getPitch()
        ]);
        
        $this->npcManager->saveNPC($uuid);
        
        $world = $player->getWorld();
        $this->npcManager->updateNPC($world, $uuid);
        
        $player->sendMessage("§aNPC tourné dans ta direction !");
        return true;
    }
    private function handleFakePlayerCommand(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npcfakeplayer <uuid>");
            return true;
        }

        $uuid = $args[0];
        if($this->npcManager->getNPCData($uuid) === null) {
            $player->sendMessage("§cNPC introuvable !");
            return true;
        }

        (new \CustomNPC\gui\FakePlayerGUI($this->npcManager))->open($player, $uuid);
        return true;
    }
}