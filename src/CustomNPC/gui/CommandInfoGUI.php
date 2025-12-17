<?php

namespace CustomNPC\gui;

use pocketmine\player\Player;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use CustomNPC\manager\NPCManager;

class CommandInfoGUI {
    
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
        
        if(!($data["commandEnabled"] ?? false)) {
            $player->sendMessage("§cLe NPC doit avoir les commandes activées !");
            $player->sendMessage("§eActive-les dans le menu Info du NPC");
            (new MainGUI($this->npcManager))->open($player, $uuid);
            return;
        }
        
        $form = new SimpleForm(function(Player $player, $buttonIndex) use ($uuid) {
            if($buttonIndex === null) {
                (new MainGUI($this->npcManager))->open($player, $uuid);
                return;
            }
            
            switch($buttonIndex) {
                case 0:
                    $this->openAddCommand($player, $uuid);
                    break;
                case 1:
                    $this->openCommandList($player, $uuid);
                    break;
                case 2:
                    (new MainGUI($this->npcManager))->open($player, $uuid);
                    break;
            }
        });
        
        $form->setTitle("§6Gestion des Commandes");
        
        $data = $this->npcManager->getNPCData($uuid);
        $commands = $data["commands"] ?? [];
        $commandCount = count($commands);
        
        $content = "§7NPC: §e" . ($data["name"] ?? "Sans nom") . "\n";
        $content .= "§7Commandes configurées: §b{$commandCount}\n";
        $content .= "§8━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $content .= "§7Clique sur le NPC pour exécuter les commandes";
        
        $form->setContent($content);
        $form->addButton("§a+ Ajouter une commande\n§8Créer une nouvelle commande", 0, "textures/ui/color_plus");
        $form->addButton("§eVoir les commandes ({$commandCount})\n§8Gérer les commandes existantes", 0, "textures/ui/book");
        $form->addButton("§c« Retour\n§8Menu principal", 0, "textures/ui/cancel");
        
        $player->sendForm($form);
    }
    private function openAddCommand(Player $player, string $uuid): void {
        $form = new CustomForm(function(Player $player, $formData) use ($uuid) {
            if($formData === null) {
                $this->open($player, $uuid);
                return;
            }
            
            $command = trim($formData[1]);
            $executor = $formData[2];
            $cooldown = max(0, (int)$formData[3]);
            $permission = trim($formData[4]);
            $oneTime = $formData[5];
            
            if(empty($command)) {
                $player->sendMessage("§cLa commande ne peut pas être vide !");
                $this->openAddCommand($player, $uuid);
                return;
            }
            
            if(str_starts_with($command, "/")) {
                $command = substr($command, 1);
            }
            
            $data = $this->npcManager->getNPCData($uuid);
            $commands = $data["commands"] ?? [];
            
            $commandData = [
                "command" => $command,
                "executor" => $executor === 0 ? "player" : "console",
                "cooldown" => $cooldown,
                "permission" => empty($permission) ? null : $permission,
                "oneTime" => $oneTime
            ];
            
            $commands[] = $commandData;
            
            $this->npcManager->updateNPCData($uuid, ["commands" => $commands]);
            $this->npcManager->saveNPC($uuid);
            
            $executorText = $executor === 0 ? "§eJoueur" : "§cConsole";
            $player->sendMessage("§aCommande ajoutée !");
            $player->sendMessage("§7§eCommande: §b/{$command}");
            $player->sendMessage("§7§eExécuteur: {$executorText}");
            if($cooldown > 0) {
                $player->sendMessage("§7§eCooldown: §b{$cooldown}s");
            }
            if(!empty($permission)) {
                $player->sendMessage("§7§ePermission: §b{$permission}");
            }
            if($oneTime) {
                $player->sendMessage("§7§eUsage: §6Une seule fois");
            }
            $this->open($player, $uuid);
        });
        
        $form->setTitle("§aAjouter une Commande");
        
        $form->addLabel("§7Ajoute une commande qui sera exécutée\n§7quand un joueur clique sur le NPC\n§8━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        $form->addInput(
            "§eCommande §7(sans le /)\n§8Placeholders: §7{player}, {x}, {y}, {z}",
            "gamemode creative {player}",
            ""
        );
        
        $form->addDropdown(
            "§eExécutée par",
            ["§eJoueur §7(celui qui clique)", "§cConsole §7(le serveur)"],
            0
        );
        
        $form->addInput(
            "§eCooldown §7(secondes)\n§80 = pas de cooldown",
            "0",
            "0"
        );
        
        $form->addInput(
            "§ePermission requise §7(optionnel)\n§8Laisse vide si aucune",
            "customnpc.use",
            ""
        );
        
        $form->addToggle(
            "§eUsage unique §7(une seule fois par joueur)",
            false
        );
        
        $player->sendForm($form);
    }
    
    private function openCommandList(Player $player, string $uuid): void {
        $data = $this->npcManager->getNPCData($uuid);
        $commands = $data["commands"] ?? [];
        
        if(empty($commands)) {
            $player->sendMessage("§cAucune commande configurée pour ce NPC !");
            $this->open($player, $uuid);
            return;
        }
        
        $form = new SimpleForm(function(Player $player, $buttonIndex) use ($uuid, $commands) {
            if($buttonIndex === null) {
                $this->open($player, $uuid);
                return;
            }
            
            if($buttonIndex === count($commands)) {
                $this->open($player, $uuid);
                return;
            }
            
            $this->openCommandEdit($player, $uuid, $buttonIndex);
        });
        
        $form->setTitle("§eListe des Commandes");
        
        $content = "§7Clique sur une commande pour la modifier\n";
        $content .= "§8━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $content .= "§7Total: §b" . count($commands) . " commande(s)";
        
        $form->setContent($content);
        
        foreach($commands as $index => $cmd) {
            $executor = $cmd["executor"] === "player" ? "§eJoueur" : "§c=Console";
            $cooldown = $cmd["cooldown"] > 0 ? " §8| §7CD: {$cmd["cooldown"]}s" : "";
            $oneTime = $cmd["oneTime"] ? " §8| §6Une fois" : "";
            
            $form->addButton(
                "§b/{$cmd["command"]}\n{$executor}{$cooldown}{$oneTime}",
                0,
                "textures/ui/book"
            );
        }
        
        $form->addButton("§c« Retour", 0, "textures/ui/cancel");
        
        $player->sendForm($form);
    }
    
    private function openCommandEdit(Player $player, string $uuid, int $commandIndex): void {
        $data = $this->npcManager->getNPCData($uuid);
        $commands = $data["commands"] ?? [];
        
        if(!isset($commands[$commandIndex])) {
            $player->sendMessage("§cCommande introuvable !");
            $this->openCommandList($player, $uuid);
            return;
        }
        
        $cmd = $commands[$commandIndex];
        
        $form = new SimpleForm(function(Player $player, $buttonIndex) use ($uuid, $commandIndex) {
            if($buttonIndex === null) {
                $this->openCommandList($player, $uuid);
                return;
            }
            
            switch($buttonIndex) {
                case 0:
                    $this->deleteCommand($player, $uuid, $commandIndex);
                    break;
                case 1:
                    $this->openCommandList($player, $uuid);
                    break;
            }
        });
        
        $form->setTitle("§eÉditer la Commande");
        
        $executor = $cmd["executor"] === "player" ? "§eJoueur" : "§cConsole";
        
        $content = "§b/{$cmd["command"]}\n";
        $content .= "§8━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $content .= "§7Exécuteur: {$executor}\n";
        
        if($cmd["cooldown"] > 0) {
            $content .= "§7Cooldown: §b{$cmd["cooldown"]}s\n";
        }
        
        if(!empty($cmd["permission"])) {
            $content .= "§7Permission: §b{$cmd["permission"]}\n";
        }
        
        if($cmd["oneTime"]) {
            $content .= "§7Usage: §6Une seule fois par joueur\n";
        }
        
        $form->setContent($content);
        $form->addButton("§cSupprimer cette commande", 0, "textures/ui/trash_default");
        $form->addButton("§eRetour à la liste", 0, "textures/ui/cancel");
        
        $player->sendForm($form);
    }
    
    private function deleteCommand(Player $player, string $uuid, int $commandIndex): void {
        $data = $this->npcManager->getNPCData($uuid);
        $commands = $data["commands"] ?? [];
        
        if(!isset($commands[$commandIndex])) {
            $player->sendMessage("§cCommande introuvable !");
            $this->openCommandList($player, $uuid);
            return;
        }
        
        $deletedCommand = $commands[$commandIndex]["command"];
        unset($commands[$commandIndex]);
        $commands = array_values($commands);
        
        $this->npcManager->updateNPCData($uuid, ["commands" => $commands]);
        $this->npcManager->saveNPC($uuid);
        
        $player->sendMessage("§aCommande supprimée !");
        $player->sendMessage("§7/{$deletedCommand}");
        
        $this->openCommandList($player, $uuid);
    }
}