<?php

namespace CustomNPC\gui;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class AppearanceGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable.");
            return;
        }

        $races = $this->npcManager->getRaceManager()->listRaces();
        $raceIds = array_keys($races);
        $raceIndex = array_search((string)($data["race"] ?? Constants::DEFAULT_RACE), $raceIds, true);
        if($raceIndex === false) $raceIndex = 0;

        $poses = $this->npcManager->getModelManager()->listPoses();
        $poseIds = array_keys($poses);
        $poseIndex = array_search((string)($data["pose"] ?? Constants::DEFAULT_POSE), $poseIds, true);
        if($poseIndex === false) $poseIndex = 0;

        $skinOptions = [
            "Ne pas changer",
            "Copier mon skin",
            "Copier le skin d'un joueur",
            "Fichier PNG du dossier skins",
            "Skin par defaut"
        ];

        $currentSkin = (string)($data["skin"] ?? "");
        $skinLabel = "§eSkin actuel: §7" . ($currentSkin === "" ? "par defaut" : $currentSkin);

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $raceIds, $poseIds) {
            if($result === null) return;

            $data = $this->npcManager->getNPCData($uuid);
            if($data === null) return;

            $yaw = fmod((float)$result[5], 360.0);
            $pitch = max(-90.0, min(90.0, (float)$result[6]));

            $updates = [
                "size" => max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)$result[1])),
                "race" => $raceIds[(int)$result[2]] ?? Constants::DEFAULT_RACE,
                "pose" => $poseIds[(int)$result[3]] ?? Constants::DEFAULT_POSE,
                "yaw" => $yaw,
                "pitch" => $pitch,
                "headYaw" => $yaw
            ];

            $this->npcManager->updateNPCData($uuid, $updates);

            $skinChoice = (int)$result[7];
            $skinValue = trim((string)$result[8]);

            switch($skinChoice) {
                case 1:
                    $this->npcManager->changeSkinFromPlayer($uuid, $player);
                    break;

                case 2:
                    if($skinValue === "") {
                        $player->sendMessage("§cAucun nom de joueur renseigne, skin inchange.");
                        break;
                    }

                    $target = $player->getServer()->getPlayerByPrefix($skinValue);
                    if($target === null) {
                        $player->sendMessage("§cJoueur '" . $skinValue . "' introuvable (il doit etre connecte).");
                        break;
                    }

                    $this->npcManager->changeSkinFromPlayer($uuid, $target);
                    break;

                case 3:
                    if($skinValue === "") {
                        $player->sendMessage("§cAucun fichier renseigne, skin inchange.");
                        break;
                    }

                    if(!str_ends_with(strtolower($skinValue), ".png")) {
                        $skinValue .= ".png";
                    }

                    $this->npcManager->changeSkin($uuid, $skinValue);
                    break;

                case 4:
                    $this->npcManager->resetSkin($uuid);
                    break;
            }

            $this->npcManager->updateNPC($uuid);
            $this->npcManager->saveNPC($uuid);

            $player->sendMessage("§aApparence enregistree.");
            (new MainGUI($this->npcManager))->open($player, $uuid);
        });

        $form->setTitle("§bApparence");
        $form->addLabel("§7La race definit la texture, la pose definit la posture.\n§7Les deux se combinent.");
        $form->addInput("Taille", "0.1-10", (string)($data["size"] ?? 1.0));
        $form->addDropdown("§dRace", array_values($races), (int)$raceIndex);
        $form->addDropdown("§aPose", array_values($poses), (int)$poseIndex);
        $form->addLabel("§7Rotation appliquee au corps et a la tete.");
        $form->addInput("Rotation Yaw", "-180 a 180", (string)round((float)($data["yaw"] ?? 0.0), 1));
        $form->addInput("Rotation Pitch", "-90 a 90", (string)round((float)($data["pitch"] ?? 0.0), 1));
        $form->addDropdown($skinLabel, $skinOptions, 0);
        $form->addInput("§7Nom du joueur ou fichier PNG", "Alexis262010 ou garde.png", "");

        $player->sendForm($form);
    }
}
