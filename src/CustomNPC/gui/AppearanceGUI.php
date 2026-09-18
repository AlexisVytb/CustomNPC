<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use CustomNPC\form\CustomForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\SkinManager;
use CustomNPC\utils\Constants;
use CustomNPC\utils\Messages;

class AppearanceGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid): void {
        if($uuid === null) return;

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage(Messages::get("general.npc-not-found"));
            return;
        }

        $model = $this->npcManager->getModelManager();

        $races = $this->npcManager->getRaceManager()->listRaces();
        $raceIds = array_keys($races);
        $raceIndex = array_search((string)($data["race"] ?? Constants::DEFAULT_RACE), $raceIds, true);
        if($raceIndex === false) $raceIndex = 0;

        $poses = $model->listPoses();
        $poseIds = array_keys($poses);
        $poseIndex = array_search($model->normalizePose((string)($data["pose"] ?? Constants::DEFAULT_POSE)), $poseIds, true);
        if($poseIndex === false) $poseIndex = 0;

        $models = $model->listModels();
        $modelIds = array_keys($models);
        $modelIndex = array_search($model->normalizeModel((string)($data["skinModel"] ?? Constants::MODEL_STEVE)), $modelIds, true);
        if($modelIndex === false) $modelIndex = 0;

        $files = $this->npcManager->getSkinManager()->listAvailableSkins();
        $fileOptions = array_merge(["§7Aucun fichier"], $files);

        $currentSkin = (string)($data["skin"] ?? "");
        $fileIndex = array_search($currentSkin, $files, true);
        $fileIndex = $fileIndex === false ? 0 : $fileIndex + 1;

        $skinOptions = [
            "Ne pas changer",
            "Copier mon skin",
            "Copier le skin d'un joueur",
            "Fichier PNG (liste ci-dessous)",
            "Fichier PNG (nom saisi)",
            "Skin par defaut"
        ];

        $skinLabel = "§eSkin actuel: §7" . ($currentSkin === "" ? "par defaut" : $currentSkin);

        $form = new CustomForm(function(Player $player, $result) use ($uuid, $raceIds, $poseIds, $modelIds, $files) {
            if($result === null) return;

            $data = $this->npcManager->getNPCData($uuid);
            if($data === null) return;

            $yaw = fmod((float)$result[5], 360.0);
            $pitch = max(-90.0, min(90.0, (float)$result[6]));

            $this->npcManager->updateNPCData($uuid, [
                "size" => max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)$result[1])),
                "race" => $raceIds[(int)$result[2]] ?? Constants::DEFAULT_RACE,
                "pose" => $poseIds[(int)$result[3]] ?? Constants::DEFAULT_POSE,
                "yaw" => $yaw,
                "pitch" => $pitch,
                "headYaw" => $yaw,
                "skinModel" => $modelIds[(int)$result[7]] ?? Constants::MODEL_STEVE
            ]);

            $skinChoice = (int)$result[8];
            $fileChoice = (int)$result[9];
            $skinValue = trim((string)$result[10]);

            switch($skinChoice) {
                case 1:
                    $this->npcManager->changeSkinFromPlayer($uuid, $player);
                    $player->sendMessage(Messages::get("skin.applied", ["skin" => $player->getName()]));
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
                    $player->sendMessage(Messages::get("skin.applied", ["skin" => $target->getName()]));
                    break;

                case 3:
                    if($fileChoice <= 0 || !isset($files[$fileChoice - 1])) {
                        $player->sendMessage("§cAucun fichier selectionne dans la liste.");
                        break;
                    }

                    $this->applyFile($player, $uuid, $files[$fileChoice - 1]);
                    break;

                case 4:
                    if($skinValue === "") {
                        $player->sendMessage("§cAucun fichier renseigne, skin inchange.");
                        break;
                    }

                    $this->applyFile($player, $uuid, $skinValue);
                    break;

                case 5:
                    $this->npcManager->resetSkin($uuid);
                    $player->sendMessage(Messages::get("skin.reset"));
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
        $form->addDropdown("§6Modele de bras", array_values($models), (int)$modelIndex);
        $form->addDropdown($skinLabel, $skinOptions, 0);
        $form->addDropdown("§bFichier du dossier skins", $fileOptions, (int)$fileIndex);
        $form->addInput("§7Nom du joueur ou fichier PNG", "Alexis262010 ou garde.png", "");

        $player->sendForm($form);
    }

    private function applyFile(Player $player, string $uuid, string $file): void {
        $result = $this->npcManager->changeSkin($uuid, $file);

        if($result === SkinManager::RESULT_OK) {
            $player->sendMessage(Messages::get("skin.applied", ["skin" => $file]));
            return;
        }

        if($result === SkinManager::RESULT_INVALID) {
            $player->sendMessage(Messages::get("skin.invalid", ["skin" => $file]));
            return;
        }

        $player->sendMessage(Messages::get("skin.not-found", ["skin" => $file]));
    }
}
