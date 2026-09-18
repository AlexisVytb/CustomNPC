<?php

namespace CustomNPC\gui;

use CustomNPC\form\SimpleForm;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use CustomNPC\inventory\ChestEditor;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class MainGUI {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function open(Player $player, ?string $uuid = null): void {
        if($uuid === null) {
            $uuid = $this->npcManager->getSelection($player);
        }

        if($uuid === null) {
            $player->sendMessage("§cAucun NPC selectionne. Utilise §e/npc select§c.");
            return;
        }

        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) {
            $player->sendMessage("§cNPC introuvable.");
            return;
        }

        $form = new SimpleForm(function(Player $player, $index) use ($uuid) {
            if($index === null) return;

            switch($index) {
                case 0: (new GeneralInfoGUI($this->npcManager))->open($player, $uuid); break;
                case 1: (new CombatInfoGUI($this->npcManager))->open($player, $uuid); break;
                case 2: (new AppearanceGUI($this->npcManager))->open($player, $uuid); break;
                case 3: (new NametagGUI($this->npcManager))->open($player, $uuid); break;
                case 4: $this->openArmor($player, $uuid); break;
                case 5: $this->openDrops($player, $uuid); break;
                case 6: (new AnimationGUI($this->npcManager))->open($player, $uuid); break;
                case 7: (new WaypointGUI($this->npcManager))->open($player, $uuid); break;
                case 8: (new DialogueGUI($this->npcManager))->open($player, $uuid); break;
                case 9: $this->openDialogueTree($player, $uuid); break;
                case 10: $this->openShop($player, $uuid); break;
                case 11: (new CommandInfoGUI($this->npcManager))->open($player, $uuid); break;
                case 12: (new VisibilityGUI($this->npcManager))->open($player, $uuid); break;
                case 13: $this->giveNPCItem($player, $uuid); break;
                case 14: $this->duplicate($player, $uuid); break;
                case 15: $this->confirmDelete($player, $uuid); break;
            }
        });

        $identifier = (string)($data["customId"] ?? "");
        $race = $this->npcManager->getRaceManager()->getRaceLabel((string)($data["race"] ?? Constants::DEFAULT_RACE));
        $pose = $this->npcManager->getModelManager()->getPoseLabel((string)($data["pose"] ?? Constants::DEFAULT_POSE));

        $content = "§7ID: §e" . ($identifier !== "" ? $identifier : $uuid) . "\n";
        $content .= "§7Titre: §f" . ($data["title"] ?? "NPC") . "\n";
        $content .= "§7Vie: §c" . (int)($data["health"] ?? 0) . "§7/§c" . (int)($data["maxHealth"] ?? 100) . "\n";
        $content .= "§7Apparence: §f" . $race . " §7/ §f" . $pose . "\n";
        $content .= "§7Agressif: " . (($data["aggressive"] ?? false) ? "§aoui" : "§cnon") . " §8| §7Commandes: " . (($data["commandEnabled"] ?? false) ? "§aoui" : "§cnon") . "\n";
        $content .= "§7Boutique: " . (($data["shop"]["enabled"] ?? false) ? "§aouverte" : "§cfermee") . " §8| §7Dialogue: " . (($data["dialogueTree"]["enabled"] ?? false) ? "§aarbre" : (($data["dialogueEnabled"] ?? false) ? "§asimple" : "§cnon"));

        $form->setTitle("§6Menu NPC");
        $form->setContent($content);

        $form->addButton("§aInfos generales\n§8Vie, vitesse, nom");
        $form->addButton("§cCombat\n§8Degats, fleches, effets");
        $form->addButton("§bApparence\n§8Skin, race, pose, taille");
        $form->addButton("§eNametag\n§8Visibilite et affichage");
        $form->addButton("§6Equipement\n§8Coffre d'armure");
        $form->addButton("§6Drops\n§8Coffre de butin");
        $form->addButton("§dAnimations\n§8Mouvements automatiques");
        $form->addButton("§dPatrouille\n§8Points de passage");
        $form->addButton("§5Dialogues simples\n§8Lignes a l'interaction");
        $form->addButton("§5Arbre de dialogue\n§8Choix et actions");
        $form->addButton("§6Boutique\n§8Echanges et stock");
        $form->addButton("§3Commandes\n§8Actions a l'interaction");
        $form->addButton("§9Visibilite\n§8Qui voit ce NPC");
        $form->addButton("§ePrendre l'item\n§8Ranger le NPC");
        $form->addButton("§dDupliquer\n§8Copier sur ma position");
        $form->addButton("§4Supprimer\n§8Effacer definitivement");

        $player->sendForm($form);
    }

    private function openShop(Player $player, string $uuid): void {
        $plugin = \CustomNPC\Main::getInstance();
        (new ShopEditGUI($this->npcManager, $plugin->getShopManager()))->open($player, $uuid);
    }

    private function openDialogueTree(Player $player, string $uuid): void {
        $plugin = \CustomNPC\Main::getInstance();
        (new DialogueTreeGUI($this->npcManager, $plugin->getDialogueRunner(), $plugin->getConditionManager()))->open($player, $uuid);
    }

    private function openArmor(Player $player, string $uuid): void {
        $editor = new ChestEditor($this->npcManager);

        $opened = $editor->openArmor($player, $uuid, function(Player $player) use ($uuid): void {
            $this->open($player, $uuid);
        });

        if(!$opened) {
            (new ArmorGUI($this->npcManager))->open($player, $uuid);
        }
    }

    private function openDrops(Player $player, string $uuid): void {
        $editor = new ChestEditor($this->npcManager);

        $opened = $editor->openDrops($player, $uuid, function(Player $player) use ($uuid): void {
            $this->open($player, $uuid);
        });

        if(!$opened) {
            (new DropsGUI($this->npcManager))->open($player, $uuid);
        }
    }

    private function giveNPCItem(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $entity = $this->npcManager->getEntity($uuid);
        if($entity !== null) {
            $entity->flagForDespawn();
        }

        $this->npcManager->updateNPCData($uuid, ["stored" => true, "runtimeId" => 0]);
        $this->npcManager->saveNPC($uuid);

        $item = VanillaItems::EMERALD()->setCustomName(Constants::NPC_ITEM_PREFIX . ($data["title"] ?? "NPC"));
        $item->setLore([
            "§7UUID: §e" . $uuid,
            "§7Clic droit sur un bloc pour placer",
            "",
            "§eVie: §c" . (int)($data["maxHealth"] ?? 100),
            "§eAgressif: " . (($data["aggressive"] ?? false) ? "§aoui" : "§cnon")
        ]);

        $tag = $item->getNamedTag();
        $tag->setString(Constants::ITEM_TAG, $uuid);
        $item->setNamedTag($tag);

        if($player->getInventory()->canAddItem($item)) {
            $player->getInventory()->addItem($item);
        } else {
            $player->getWorld()->dropItem($player->getPosition(), $item);
        }

        $player->sendMessage("§aNPC range dans un item.");
    }

    private function duplicate(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $position = $player->getPosition();
        $location = $player->getLocation();

        $data["position"] = [
            "x" => $position->x,
            "y" => $position->y,
            "z" => $position->z,
            "world" => $player->getWorld()->getFolderName()
        ];
        $data["yaw"] = $location->yaw;
        $data["pitch"] = $location->pitch;
        $data["headYaw"] = $location->yaw;
        $data["runtimeId"] = 0;
        $data["customId"] = "";
        $data["stored"] = false;
        $data["usedOnce"] = [];
        $data["creator"] = $player->getName();

        $newUuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $newUuid);
        $this->npcManager->select($player, $newUuid);

        $player->sendMessage("§aNPC duplique et selectionne. §7UUID: §e" . $newUuid);
    }

    private function confirmDelete(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $form = new SimpleForm(function(Player $player, $index) use ($uuid) {
            if($index === null) return;

            if($index === 0) {
                $this->npcManager->deleteNPC($uuid);
                $player->sendMessage("§aNPC supprime.");
            } else {
                $this->open($player, $uuid);
            }
        });

        $form->setTitle("§cConfirmer la suppression");
        $form->setContent("§7Supprimer definitivement :\n§e" . ($data["title"] ?? "NPC") . "\n§7UUID: §e" . $uuid);
        $form->addButton("§aOui, supprimer");
        $form->addButton("§cNon, annuler");

        $player->sendForm($form);
    }
}
