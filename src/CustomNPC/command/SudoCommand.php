<?php

namespace CustomNPC\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use CustomNPC\Main;

class SudoCommand extends Command {

    public function __construct() {
        parent::__construct("sudo", "Exécuter une action à la place d'un joueur", "/sudo <joueur> <action>");
        $this->setPermission("customnpc.sudo");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) {
            return false;
        }

        if(count($args) < 2) {
            $sender->sendMessage("§cUsage: /sudo <joueur> <action>");
            return false;
        }

        $playerName = array_shift($args);
        $target = Main::getInstance()->getServer()->getPlayerExact($playerName);

        if($target === null) {
            $sender->sendMessage("§cJoueur introuvable !");
            return false;
        }

        $action = implode(" ", $args);

        if(str_starts_with($action, "*")) {
            // Chat message
            $message = substr($action, 1);
            $target->chat($message);
            $sender->sendMessage("§a[Sudo] §e{$target->getName()} §7a dit: §f{$message}");
        } else {
            // Command execution
            // Strip leading slash if present, as dispatchCommand handles it or expects it depending on context
            // But Server::dispatchCommand usually needs no slash for console, but for player context it simulates player input.
            // Actually Server::dispatchCommand($player, $cmdLine) expects the command string without slash usually?
            // Let's check PMMP behavior.
            // If we use $target->chat("/cmd"), it works as a command.
            // but dispatchCommand is safer if we want to bypass chat event filtering perhaps?
            // The user said "si y'a rien c une command ducoup say car on ignore le /".
            // Let's remove the slash if present.
            
            if(str_starts_with($action, "/")) {
                $action = substr($action, 1);
            }
            
            Main::getInstance()->getServer()->dispatchCommand($target, $action);
            $sender->sendMessage("§a[Sudo] §e{$target->getName()} §7a exécuté: §f/{$action}");
        }

        return true;
    }
}
