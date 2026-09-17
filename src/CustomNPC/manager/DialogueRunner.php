<?php

namespace CustomNPC\manager;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\Position;
use CustomNPC\gui\ShopGUI;
use CustomNPC\Main;
use CustomNPC\utils\ItemParser;
use CustomNPC\utils\Messages;

class DialogueRunner {

    public const ACTION_NODE = "node";
    public const ACTION_COMMAND = "command";
    public const ACTION_CONSOLE = "console";
    public const ACTION_SHOP = "shop";
    public const ACTION_TELEPORT = "teleport";
    public const ACTION_GIVE = "give";
    public const ACTION_MESSAGE = "message";
    public const ACTION_CLOSE = "close";

    public const ACTIONS = [
        self::ACTION_NODE => "Aller a un autre noeud",
        self::ACTION_COMMAND => "Commande (joueur)",
        self::ACTION_CONSOLE => "Commande (console)",
        self::ACTION_SHOP => "Ouvrir la boutique",
        self::ACTION_TELEPORT => "Teleporter",
        self::ACTION_GIVE => "Donner des items",
        self::ACTION_MESSAGE => "Message",
        self::ACTION_CLOSE => "Fermer"
    ];

    private NPCManager $npcManager;
    private ShopManager $shopManager;

    public function __construct(NPCManager $npcManager, ShopManager $shopManager) {
        $this->npcManager = $npcManager;
        $this->shopManager = $shopManager;
    }

    public static function getDefaultTree(): array {
        return [
            "enabled" => false,
            "start" => "start",
            "nodes" => [
                "start" => [
                    "lines" => [],
                    "choices" => []
                ]
            ]
        ];
    }

    public static function getDefaultChoice(): array {
        return [
            "text" => "Continuer",
            "action" => self::ACTION_CLOSE,
            "value" => "",
            "permission" => ""
        ];
    }

    public function getTree(string $uuid): array {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return self::getDefaultTree();

        $tree = $data["dialogueTree"] ?? [];
        $tree = array_merge(self::getDefaultTree(), is_array($tree) ? $tree : []);

        if(!is_array($tree["nodes"]) || empty($tree["nodes"])) {
            $tree["nodes"] = self::getDefaultTree()["nodes"];
        }

        return $tree;
    }

    public function saveTree(string $uuid, array $tree): void {
        $this->npcManager->updateNPCData($uuid, ["dialogueTree" => $tree]);
        $this->npcManager->saveNPC($uuid);
    }

    public function hasTree(string $uuid): bool {
        $tree = $this->getTree($uuid);
        if(!($tree["enabled"] ?? false)) return false;

        foreach($tree["nodes"] as $node) {
            if(!empty($node["lines"]) || !empty($node["choices"])) return true;
        }

        return false;
    }

    public function start(Player $player, string $uuid): void {
        $tree = $this->getTree($uuid);
        $this->openNode($player, $uuid, (string)($tree["start"] ?? "start"));
    }

    public function openNode(Player $player, string $uuid, string $nodeId): void {
        $data = $this->npcManager->getNPCData($uuid);
        if($data === null) return;

        $tree = $this->getTree($uuid);
        $node = $tree["nodes"][$nodeId] ?? null;

        if(!is_array($node)) {
            $player->sendMessage(Messages::get("dialogue.node-missing"));
            return;
        }

        $npcName = $this->npcManager->applyPlaceholders((string)($data["title"] ?? "NPC"), $uuid);
        $delay = max(1, (int)($data["dialogueDelay"] ?? 20));
        $scheduler = Main::getInstance()->getScheduler();

        $lines = array_values(array_filter($node["lines"] ?? [], 'is_string'));
        $index = 0;

        foreach($lines as $line) {
            $text = $this->format($line, $player, $uuid);

            $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player, $npcName, $text): void {
                if($player->isConnected()) {
                    $player->sendMessage(Messages::get("dialogue.format", ["npc" => $npcName, "line" => $text]));
                }
            }), $index * $delay);

            $index++;
        }

        $choices = [];
        foreach($node["choices"] ?? [] as $choice) {
            if(!is_array($choice)) continue;

            $choice = array_merge(self::getDefaultChoice(), $choice);
            $permission = trim((string)$choice["permission"]);

            if($permission !== "" && !$player->hasPermission($permission)) continue;

            $choices[] = $choice;
        }

        if(empty($choices)) return;

        $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player, $uuid, $npcName, $choices, $lines): void {
            if(!$player->isConnected()) return;

            $form = new SimpleForm(function(Player $player, $index) use ($uuid, $choices) {
                if($index === null || !isset($choices[$index])) return;

                $this->execute($player, $uuid, $choices[$index]);
            });

            $form->setTitle("§6" . $npcName);
            $form->setContent(empty($lines) ? "§7..." : "§f" . $this->stripColorNoise(end($lines)));

            foreach($choices as $choice) {
                $form->addButton("§f" . $choice["text"]);
            }

            $player->sendForm($form);
        }), max(1, count($lines)) * $delay);
    }

    private function stripColorNoise(string $line): string {
        return $line;
    }

    private function format(string $line, Player $player, string $uuid): string {
        $line = str_replace("{player}", $player->getName(), $line);
        return $this->npcManager->applyPlaceholders($line, $uuid);
    }

    public function execute(Player $player, string $uuid, array $choice): void {
        $action = (string)($choice["action"] ?? self::ACTION_CLOSE);
        $value = $this->format((string)($choice["value"] ?? ""), $player, $uuid);

        switch($action) {
            case self::ACTION_NODE:
                $this->openNode($player, $uuid, $value);
                break;

            case self::ACTION_COMMAND:
                Server::getInstance()->dispatchCommand($player, ltrim($value, "/"));
                break;

            case self::ACTION_CONSOLE:
                Server::getInstance()->dispatchCommand(Server::getInstance()->getConsoleSender(), ltrim($value, "/"));
                break;

            case self::ACTION_SHOP:
                (new ShopGUI($this->npcManager, $this->shopManager))->open($player, $uuid);
                break;

            case self::ACTION_TELEPORT:
                $this->teleport($player, $value);
                break;

            case self::ACTION_GIVE:
                $this->give($player, $value);
                break;

            case self::ACTION_MESSAGE:
                $player->sendMessage($value);
                break;
        }
    }

    private function teleport(Player $player, string $value): void {
        $parts = preg_split('/\s+/', trim($value));

        if(count($parts) < 3) {
            $player->sendMessage("§cDestination invalide.");
            return;
        }

        $world = $player->getWorld();

        if(isset($parts[3])) {
            $target = Server::getInstance()->getWorldManager()->getWorldByName($parts[3]);

            if($target === null) {
                $player->sendMessage(Messages::get("general.world-not-loaded"));
                return;
            }

            $world = $target;
        }

        $player->teleport(new Position((float)$parts[0], (float)$parts[1], (float)$parts[2], $world));
    }

    private function give(Player $player, string $value): void {
        foreach(explode(";", $value) as $entry) {
            $entry = trim($entry);
            if($entry === "") continue;

            $item = ItemParser::parse($entry);
            if($item === null) continue;

            if($player->getInventory()->canAddItem($item)) {
                $player->getInventory()->addItem($item);
            } else {
                $player->getWorld()->dropItem($player->getPosition(), $item);
            }
        }
    }
}
