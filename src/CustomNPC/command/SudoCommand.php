<?php

namespace CustomNPC\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Server;

class SudoCommand extends Command {

    public function __construct() {
        parent::__construct("sudo", "Executer une action a la place d'un joueur", "/sudo <joueur> <action>");
        $this->setPermission("customnpc.sudo");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) {
            return false;
        }

        if(count($args) < 2) {
            $sender->sendMessage("§cUsage: /sudo <joueur> <action>");
            $sender->sendMessage("§7Prefixe l'action par §e*§7 pour parler dans le chat.");
            return false;
        }

        $playerName = array_shift($args);
        $target = Server::getInstance()->getPlayerExact($playerName);

        if($target === null) {
            $sender->sendMessage("§cJoueur introuvable.");
            return false;
        }

        if($target->hasPermission("customnpc.sudo.exempt") && $target !== $sender) {
            $sender->sendMessage("§cCe joueur ne peut pas etre cible par /sudo.");
            return false;
        }

        $action = implode(" ", $args);

        if(str_starts_with($action, "*")) {
            $message = substr($action, 1);
            $target->chat($message);
            $sender->sendMessage("§a[Sudo] §e" . $target->getName() . " §7a dit: §f" . $message);
            return true;
        }

        $command = ltrim($action, "/");
        Server::getInstance()->dispatchCommand($target, $command);
        $sender->sendMessage("§a[Sudo] §e" . $target->getName() . " §7a execute: §f/" . $command);

        return true;
    }
}
