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

            // Index 0 : Taille
            $updates = [
                "size" => max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)$formData[0]))
            ];

            // Index 1 : Dropdown skin
            $skinChoice = (int)$formData[1];
            
            if($skinChoice === 0) {
                // Copier le skin du joueur actuel
                $this->copySkinToNPC($player, $uuid, $player);
            } elseif($skinChoice === 1) {
                // Skin par défaut (Steve)
                $updates["skin"] = "";
                unset($data["savedSkin"]);
                $this->npcManager->updateNPCData($uuid, $updates);
                $this->npcManager->updateNPC($player->getWorld(), $uuid);
                $this->npcManager->saveNPC($uuid);
                $player->sendMessage("§aSkin par défaut appliqué !");
            } elseif($skinChoice === 2) {
                // Copier d'un autre joueur
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
                // Fichier PNG personnalisé
                $skinFile = trim($formData[2] ?? "");
                if($skinFile !== "") {
                    $updates["skin"] = $skinFile;
                    unset($data["savedSkin"]);
                } else {
                    $updates["skin"] = "";
                }
            }

            // Index 2 : Input nom joueur / fichier skin (déjà géré)
            
            // Index 3 : Toggle immobile
            $updates["immobile"] = (bool)$formData[3];
            
            // Index 4 : Toggle peut être frappé
            $updates["canBeHit"] = (bool)$formData[4];
            
            // Index 5 : Yaw
            $yaw = (float)($formData[5] ?? 0.0);
            $updates["yaw"] = max(0, min(360, $yaw));
            
            // Index 6 : Pitch
            $pitch = (float)($formData[6] ?? 0.0);
            $updates["pitch"] = max(-90, min(90, $pitch));
            
            // Index 7 : Drops
            $dropsRaw = $formData[7] ?? "";
            if(!empty(trim($dropsRaw))) {
                $drops = array_filter(array_map('trim', explode(";", $dropsRaw)));
                $updates["drops"] = $drops;
            } else {
                $updates["drops"] = [];
            }

            // Appliquer les updates (sauf si on a déjà fait copySkinToNPC qui sauvegarde)
            if($skinChoice !== 0 && $skinChoice !== 2) {
                $this->npcManager->updateNPCData($uuid, $updates);
                $this->npcManager->updateNPC($player->getWorld(), $uuid);
                $this->npcManager->saveNPC($uuid);
                $player->sendMessage("§aAutres infos modifiées !");
            }

            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§bInfo Autres");
        
        // Champ taille
        $form->addInput("Taille du NPC", "0.1-10", (string)($data["size"] ?? 1.0));
        
        // Dropdown pour le choix du skin
        $currentSkin = $data["skin"] ?? "";
        $skinLabel = "§eSkin actuel: §7" . (empty($currentSkin) ? "Steve par défaut" : $currentSkin);
        
        $form->addDropdown($skinLabel, [
            "Copier mon skin",
            "Skin par défaut (Steve)",
            "Copier le skin d'un joueur",
            "Fichier PNG personnalisé"
        ], 1);
        
        // Input pour nom joueur OU fichier skin
        $form->addInput("§7Nom du joueur OU fichier skin.png", "Alexis262010 ou skin.png", $data["skin"] ?? "");
        
        // Toggles
        $form->addToggle("Immobile", $data["immobile"] ?? false);
        $form->addToggle("Peut être frappé", $data["canBeHit"] ?? true);
        
        // Rotation
        $form->addInput("Rotation Yaw (0-360°)", "ex: 90", (string)($data["yaw"] ?? 0.0));
        $form->addInput("Rotation Pitch (-90 à 90°)", "ex: 0", (string)($data["pitch"] ?? 0.0));
        
        // Drops
        $form->addInput("Items à drop (séparés par ;)", "ex: diamond:0;iron_ingot", implode(";", $data["drops"] ?? []));

        $player->sendForm($form);
    }

    /**
     * Copie le skin d'un joueur sur un NPC et applique instantanément
     */
    private function copySkinToNPC(Player $sender, string $npcUuid, Player $skinSource): void {
        $npcData = $this->npcManager->getNPCData($npcUuid);

        if($npcData === null) {
            $sender->sendMessage("§cNPC introuvable !");
            return;
        }

        // Obtenir le skin du joueur source
        $skin = $skinSource->getSkin();

        // Sauvegarder directement les données du skin
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

        // Mettre à jour les données
        $this->npcManager->updateNPCData($npcUuid, $updates);

        // Obtenir l'entité et appliquer le skin immédiatement
        $world = $sender->getWorld();
        $entity = $world->getEntity($npcData["runtimeId"] ?? 0);

        if($entity instanceof \pocketmine\entity\Human && !$entity->isClosed()) {
            // Appliquer le skin
            $entity->setSkin($skin);

            // Forcer le refresh visuel (despawn/respawn)
            foreach($entity->getViewers() as $viewer) {
                $entity->despawnFrom($viewer);
            }

            // ✅ CORRECTION PM5 : Utiliser le scheduler du plugin
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
                $sender->sendMessage("§a✔ Votre skin a été copié sur le NPC !");
            } else {
                $sender->sendMessage("§a✔ Le skin de §e{$skinSource->getName()}§a a été copié sur le NPC !");
            }
        } else {
            $sender->sendMessage("§c✘ Erreur : NPC introuvable dans le monde !");
        }

        // Sauvegarder en base de données
        $this->npcManager->saveNPC($npcUuid);
    }
}
