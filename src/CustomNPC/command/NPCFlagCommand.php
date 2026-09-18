<?php

namespace CustomNPC\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use CustomNPC\manager\PlayerDataManager;

class NPCFlagCommand extends Command {

    private PlayerDataManager $playerData;

    public function __construct(PlayerDataManager $playerData) {
        parent::__construct("npcflag", "Lire ou modifier une variable de quete d'un joueur", "/npcflag <joueur> <get|set|add|clear|list> [cle] [valeur]");
        $this->setPermission("customnpc.flag");
        $this->playerData = $playerData;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if(!$this->testPermission($sender)) {
            return false;
        }

        if(count($args) < 2) {
            $sender->sendMessage("§cUsage: /npcflag <joueur> <get|set|add|clear|list> [cle] [valeur]");
            return false;
        }

        $player = array_shift($args);
        $action = strtolower(array_shift($args));

        switch($action) {
            case "get":
                if(empty($args)) {
                    $sender->sendMessage("§cUsage: /npcflag <joueur> get <cle>");
                    return false;
                }

                $value = $this->playerData->getFlag($player, $args[0]);
                $sender->sendMessage("§7" . $player . "." . $args[0] . " = §e" . ($value ?? "§8(non definie)"));
                return true;

            case "set":
                if(count($args) < 2) {
                    $sender->sendMessage("§cUsage: /npcflag <joueur> set <cle> <valeur>");
                    return false;
                }

                $key = array_shift($args);
                $value = implode(" ", $args);
                $this->playerData->setFlag($player, $key, $value);
                $sender->sendMessage("§a" . $player . "." . $key . " = §e" . $value);
                return true;

            case "add":
                if(empty($args)) {
                    $sender->sendMessage("§cUsage: /npcflag <joueur> add <cle> [nombre]");
                    return false;
                }

                $key = array_shift($args);
                $amount = empty($args) ? 1.0 : (float)$args[0];
                $result = $this->playerData->increment($player, $key, $amount);
                $sender->sendMessage("§a" . $player . "." . $key . " = §e" . $result);
                return true;

            case "clear":
                if(empty($args)) {
                    $this->playerData->clearAll($player);
                    $sender->sendMessage("§aToutes les variables de " . $player . " ont ete effacees.");
                    return true;
                }

                $this->playerData->deleteFlag($player, $args[0]);
                $sender->sendMessage("§aVariable §e" . $args[0] . " §asupprimee pour " . $player . ".");
                return true;

            case "list":
                $flags = $this->playerData->getAllFlags($player);

                if(empty($flags)) {
                    $sender->sendMessage("§7Aucune variable pour " . $player . ".");
                    return true;
                }

                $sender->sendMessage("§e===== Variables de " . $player . " =====");
                foreach($flags as $key => $value) {
                    $sender->sendMessage("§7- §f" . $key . " §7= §e" . $value);
                }
                return true;

            default:
                $sender->sendMessage("§cAction inconnue : " . $action);
                return false;
        }
    }
}
