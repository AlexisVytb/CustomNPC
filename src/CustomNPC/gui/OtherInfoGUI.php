<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use pocketmine\scheduler\ClosureTask;

class OtherInfoGUI {
    
    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) {
            $player->sendMessage("§cAucun NPC sélectionné !");
            return;
        }

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            $data = $this->npcManager->getNPCData($uuid);
            if($data === null) {
                $player->sendMessage("§cNPC introuvable !");
                return;
            }

            $updates = [
                "size" => max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)$formData[0]))
            ];

            $skinChoice = (int)$formData[1];
            
            if($skinChoice === 0) {
                $this->copySkinToNPC($player, $uuid, $player);
            } elseif($skinChoice === 1) {
                $updates["skin"] = "";
                unset($data["savedSkin"]);
                $this->npcManager->updateNPCData($uuid, $updates);
                $this->npcManager->updateNPC($player->getWorld(), $uuid);
                $this->npcManager->saveNPC($uuid);
                $player->sendMessage("§aSkin par défaut appliqué !");
            } elseif($skinChoice === 2) {
                $targetName = trim($formData[2] ?? "");
                if($targetName !== "") {
                    $targetPlayer = $player->getServer()->getPlayerByPrefix($targetName);
                    if($targetPlayer !== null) {
                        $this->copySkinToNPC($player, $uuid, $targetPlayer);
                    } else {
                        $player->sendMessage("§cJoueur '{$targetName}' introuvable ! Il doit être en ligne.");
                    }
                } else {
                    $player->sendMessage("§cVeuillez entrer un nom de joueur !");
                }
            } elseif($skinChoice === 3) {
                $skinFile = trim($formData[2] ?? "");
                if($skinFile !== "") {
                    $updates["skin"] = $skinFile;
                    unset($data["savedSkin"]);
                } else {
                    $updates["skin"] = "";
                }
            }
            $updates["immobile"] = (bool)$formData[3];

            $updates["canBeHit"] = (bool)$formData[4];
 
            $yaw = (float)($formData[5] ?? 0.0);
            $updates["yaw"] = max(0, min(360, $yaw));

            $pitch = (float)($formData[6] ?? 0.0);
            $updates["pitch"] = max(-90, min(90, $pitch));

            $dropsRaw = $formData[7] ?? "";
            if(!empty(trim($dropsRaw))) {
                $drops = array_filter(array_map('trim', explode(";", $dropsRaw)));
                $updates["drops"] = $drops;
            } else {
                $updates["drops"] = [];
            }

            if($skinChoice !== 0 && $skinChoice !== 2) {
                $this->npcManager->updateNPCData($uuid, $updates);
                $this->npcManager->updateNPC($player->getWorld(), $uuid);
                $this->npcManager->saveNPC($uuid);
                $player->sendMessage("§aAutres infos modifiées !");
            }

            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§bInfo Autres");

        $form->addInput("Taille du NPC", "0.1-10", (string)($data["size"] ?? 1.0));

        $currentSkin = $data["skin"] ?? "";
        $skinLabel = "§eSkin actuel: §7" . (empty($currentSkin) ? "Steve par défaut" : $currentSkin);
        
        $form->addDropdown($skinLabel, [
            "Copier mon skin",
            "Skin par défaut (Steve)",
            "Copier le skin d'un joueur",
            "Fichier PNG personnalisé"
        ], 1);

        $form->addInput("§7Nom du joueur", "Alexis262010", $data["skin"] ?? "");

        $form->addToggle("Immobile", $data["immobile"] ?? false);
        $form->addToggle("Peut être frappé", $data["canBeHit"] ?? true);

        $form->addInput("Rotation Yaw (0-360°)", "ex: 90", (string)($data["yaw"] ?? 0.0));
        $form->addInput("Rotation Pitch (-90 à 90°)", "ex: 0", (string)($data["pitch"] ?? 0.0));

        $form->addInput("Items à drop (séparés par ;)", "ex: diamond:0;iron_ingot", implode(";", $data["drops"] ?? []));

        $player->sendForm($form);
    }

    private function copySkinToNPC(Player $sender, string $npcUuid, Player $skinSource): void {
        $npcData = $this->npcManager->getNPCData($npcUuid);

        if($npcData === null) {
            $sender->sendMessage("§cNPC introuvable !");
            return;
        }

        $skin = $skinSource->getSkin();

        $updates = [
            "skin" => "player_" . $skinSource->getName(),
            "savedSkin" => [
                "skinId" => $skin->getSkinId(),
                "skinData" => base64_encode($skin->getSkinData()),
                "capeData" => base64_encode($skin->getCapeData()),
                "geometryName" => $skin->getGeometryName(),
                "geometryData" => base64_encode($skin->getGeometryData())
            ]
        ];

        $this->npcManager->updateNPCData($npcUuid, $updates);

        $world = $sender->getWorld();
        $entity = $world->getEntity($npcData["runtimeId"] ?? 0);

        if($entity instanceof \pocketmine\entity\Human && !$entity->isClosed()) {
            $entity->setSkin($skin);

            foreach($entity->getViewers() as $viewer) {
                $entity->despawnFrom($viewer);
            }

            $this->npcManager->getPlugin()->getScheduler()->scheduleDelayedTask(
                new ClosureTask(function() use ($entity, $world): void {
                    if(!$entity->isClosed()) {
                        foreach($world->getPlayers() as $p) {
                            $entity->spawnTo($p);
                        }
                    }
                }),
                2
            );

            if($skinSource === $sender) {
                $sender->sendMessage("§aVotre skin a été copié sur le NPC !");
            } else {
                $sender->sendMessage("§aLe skin de §e{$skinSource->getName()}§a a été copié sur le NPC !");
            }
        } else {
            $sender->sendMessage("§cErreur : NPC introuvable dans le monde !");
        }
        $this->npcManager->saveNPC($npcUuid);
    }
}
