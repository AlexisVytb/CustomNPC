<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use jojoe77777\FormAPI\SimpleForm;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class MainGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid = null): void {
        $form = new SimpleForm(function(Player $player, $data) use ($uuid) {
            if($data === null) return;

            switch($data) {
                case 0:
                    (new GeneralInfoGUI($this->npcManager))->open($player, $uuid);
                    break;
                case 1:
                    (new CombatInfoGUI($this->npcManager))->open($player, $uuid);
                    break;
                case 2:
                    (new OtherInfoGUI($this->npcManager))->open($player, $uuid);
                    break;
                case 3:
                    if($uuid !== null) {
                        $this->giveNPCItem($player, $uuid);
                    }
                    break;
                case 4:
                    if($uuid !== null) {
                        $this->duplicateNPC($player, $uuid);
                    }
                    break;
                case 5:
                    if($uuid !== null) {
                        $this->deleteNPCConfirm($player, $uuid);
                    }
                    break;
            }
        });

        $form->setTitle("§6Menu NPC");
        
        if($uuid !== null) {
            $data = $this->npcManager->getNPCData($uuid);
            $form->setContent("§7UUID: §e" . $uuid . "\n§7Titre: §f" . ($data["title"] ?? "NPC") . "\n§7Vie: §c" . (int)($data["health"] ?? 100) . "§7/§c" . (int)($data["maxHealth"] ?? 100));
        } else {
            $form->setContent("§7Clique sur un NPC avec la wand\n§7ou crée-en un nouveau");
        }
        
        $form->addButton("§aInfo Général\n§7Vie, vitesse, nom...");
        $form->addButton("§cInfo Combat\n§7Attaques, armure...");
        $form->addButton("§bInfo Autres\n§7Taille, skin...");
        
        if($uuid !== null) {
            $form->addButton("§ePrendre l'item\n§7Récupérer le NPC");
            $form->addButton("§dDupliquer\n§7Copier ce NPC");
            $form->addButton("§4Supprimer\n§7Effacer ce NPC");
        }

        $player->sendForm($form);
    }

    private function giveNPCItem(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        // Despawn le NPC
        $world = $player->getWorld();
        $entity = $world->getEntity($data["runtimeId"] ?? 0);
        if($entity !== null) {
            $entity->flagForDespawn();
        }

        // Créer l'item
        $item = VanillaItems::EMERALD()->setCustomName(Constants::NPC_ITEM_PREFIX . ($data["title"] ?? "NPC"));
        $lore = [
            "§7UUID: §e" . $uuid,
            "§7Clic droit pour placer",
            "§7",
            "§eNPC: §f" . ($data["title"] ?? "NPC"),
            "§eVie: §c" . (int)($data["maxHealth"] ?? 100),
            "§eAgressif: " . (($data["aggressive"] ?? false) ? "§aOui" : "§cNon")
        ];
        $item->setLore($lore);

        $nbt = $item->getNamedTag();
        $nbt->setString("npc_uuid", $uuid);
        $item->setNamedTag($nbt);

        $player->getInventory()->addItem($item);
        $player->sendMessage("§aTu as récupéré le NPC en item !");
    }

    private function duplicateNPC(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        $pos = $player->getPosition();
        $data["position"] = [
            "x" => $pos->getX(),
            "y" => $pos->getY(),
            "z" => $pos->getZ(),
            "world" => $player->getWorld()->getFolderName()
        ];
        $data["runtimeId"] = 0;
        
        $newUuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $newUuid);
        
        $player->sendMessage("§aNPC dupliqué ! Nouveau UUID: §e" . $newUuid);
    }

    private function deleteNPCConfirm(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable !");
            return;
        }

        $form = new SimpleForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) return;

            if($formData === 0) {
                $this->npcManager->deleteNPC($uuid);
                $player->sendMessage("§aNPC supprimé avec succès !");
            } else {
                $player->sendMessage("§eSuppression annulée.");
                $this->open($player, $uuid);
            }
        });

        $npcTitle = $data["title"] ?? "NPC";
        $form->setTitle("§cConfirmer la suppression");
        $form->setContent("§7Êtes-vous sûr de vouloir supprimer le NPC:\n§e" . $npcTitle . "\n§7UUID: §e" . $uuid . "\n\n§cCette action est irréversible !");
        $form->addButton("§aOui, supprimer");
        $form->addButton("§cNon, annuler");

        $player->sendForm($form);
    }
}