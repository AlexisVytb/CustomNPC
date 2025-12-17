<?php

namespace CustomNPC\listener;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\Server;
use CustomNPC\manager\NPCManager;
use CustomNPC\gui\MainGUI;
use CustomNPC\utils\Constants;
use CustomNPC\Main;

class NPCTapListener implements Listener {
    
    private NPCManager $npcManager;
    private array $commandCooldowns = [];
    
    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }
    public function onNPCTap(EntityDamageByEntityEvent $event): void {
        $entity = $event->getEntity();
        $damager = $event->getDamager();
        
        if(!($damager instanceof Player)) {
            return;
        }
        
        if(!($entity instanceof \pocketmine\entity\Human)) {
            return;
        }
        
        $player = $damager;
        $entityId = $entity->getId();
        
        $npcUuid = $this->npcManager->findNPCByEntityId($entityId);
        
        if($npcUuid === null) {
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
                        $this->npcManager->repairMapping($entityId, $uuid);
                        break;
                    }
                }
            }
        }
        
        if($npcUuid === null) {
            return;
        }
        
        $npcData = $this->npcManager->getNPCData($npcUuid);
        if($npcData === null) {
            return;
        }
        
        $item = $player->getInventory()->getItemInHand();
        
        if($item->getCustomName() === Constants::NPC_WAND_NAME && 
           $player->hasPermission("customnpc.wand")) {
            (new MainGUI($this->npcManager))->open($player, $npcUuid);
            $event->cancel();
            return;
        }
    
        if($npcData["commandEnabled"] ?? false) {
            $this->executeCommandsAdvanced($npcData, $player, $npcUuid);
            if(!($npcData["canBeHit"] ?? true) || !($npcData["aggressive"] ?? false)) {
                $event->cancel();
            }
        }
    }
    private function executeCommandsAdvanced(array $npcData, Player $player, string $npcUuid): void {
        $commands = $npcData["commands"] ?? [];
        if(empty($commands)) {
            return;
        }
        
        $playerName = $player->getName();
        $executedCount = 0;
        
        foreach($commands as $index => $commandData) {
            if(is_string($commandData)) {
                $commandData = [
                    "command" => $commandData,
                    "executor" => "console",
                    "cooldown" => 0,
                    "permission" => null,
                    "oneTime" => false
                ];
            }
            if(!empty($commandData["permission"]) && !$player->hasPermission($commandData["permission"])) {
                $player->sendMessage("§cTu n'as pas la permission : §e{$commandData["permission"]}");
                continue;
            }
            
            $cooldownKey = $playerName . "_" . $npcUuid . "_" . $index;
            if(($commandData["cooldown"] ?? 0) > 0) {
                $now = time();
                $lastUse = $this->commandCooldowns[$cooldownKey] ?? 0;
                $remaining = ($lastUse + $commandData["cooldown"]) - $now;
                
                if($remaining > 0) {
                    $player->sendMessage("§cEn Cooldown => §e{$remaining}s");
                    continue;
                }
                
                $this->commandCooldowns[$cooldownKey] = $now;
            }

            if($commandData["oneTime"] ?? false) {
                $oneTimeKey = $cooldownKey . "_onetime";
                if(isset($this->commandCooldowns[$oneTimeKey])) {
                    $player->sendMessage("§cCommande déjà utilisée");
                    continue;
                }
                $this->commandCooldowns[$oneTimeKey] = true;
            }
            $command = $this->replacePlaceholders($commandData["command"], $player);
            
            $success = false;
            if(($commandData["executor"] ?? "console") === "console") {
                $success = $this->executeAsConsole($command);
            } else {
                $success = $this->executeAsPlayer($player, $command);
            }
            
            if($success) {
                $executedCount++;
            }
        }
    }
    
    private function replacePlaceholders(string $command, Player $player): string {
        $location = $player->getLocation();
        
        $placeholders = [
            "{player}" => $player->getName(),
            "{x}" => (string)((int)$location->x),
            "{y}" => (string)((int)$location->y),
            "{z}" => (string)((int)$location->z),
            "{world}" => $player->getWorld()->getFolderName(),
            "{xuid}" => $player->getXuid(),
            "{uuid}" => $player->getUniqueId()->toString()
        ];
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $command);
    }
    
    private function executeAsConsole(string $command): bool {
        try {
            $server = Server::getInstance();
            return $server->dispatchCommand(
                new \pocketmine\console\ConsoleCommandSender($server, $server->getLanguage()),
                $command
            );
        } catch(\Exception $e) {
            Main::getInstance()->getLogger()->error("Erreur commande console: " . $e->getMessage());
            return false;
        }
    }
    
    private function executeAsPlayer(Player $player, string $command): bool {
        try {
            return Server::getInstance()->dispatchCommand($player, $command);
        } catch(\Exception $e) {
            Main::getInstance()->getLogger()->error("Erreur commande joueur: " . $e->getMessage());
            $player->sendMessage("§cErreur lors de l'exécution");
            return false;
        }
    }
}