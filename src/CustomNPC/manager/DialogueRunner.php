<?php

namespace CustomNPC\manager;

use CustomNPC\form\SimpleForm;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\Position;
use CustomNPC\gui\ShopGUI;
use CustomNPC\Main;
use CustomNPC\utils\Compat;
use CustomNPC\utils\ItemParser;
use CustomNPC\utils\Messages;

class DialogueRunner {

    public const ACTION_NODE = "node";
    public const ACTION_COMMAND = "command";
    public const ACTION_CONSOLE = "console";
    public const ACTION_SHOP = "shop";
    public const ACTION_TELEPORT = "teleport";
    public const ACTION_GIVE = "give";
    public const ACTION_TAKE = "take";
    public const ACTION_FLAG_SET = "flag_set";
    public const ACTION_FLAG_ADD = "flag_add";
    public const ACTION_FLAG_CLEAR = "flag_clear";
    public const ACTION_MESSAGE = "message";
    public const ACTION_CLOSE = "close";

    public const ACTIONS = [
        self::ACTION_NODE => "Aller a un autre noeud",
        self::ACTION_COMMAND => "Commande (joueur)",
        self::ACTION_CONSOLE => "Commande (console)",
        self::ACTION_SHOP => "Ouvrir la boutique",
        self::ACTION_TELEPORT => "Teleporter",
        self::ACTION_GIVE => "Donner des items",
        self::ACTION_TAKE => "Retirer des items",
        self::ACTION_FLAG_SET => "Definir une variable",
        self::ACTION_FLAG_ADD => "Ajouter a une variable",
        self::ACTION_FLAG_CLEAR => "Supprimer une variable",
        self::ACTION_MESSAGE => "Message",
        self::ACTION_CLOSE => "Fermer"
    ];

    private NPCManager $npcManager;
    private ShopManager $shopManager;
    private ConditionManager $conditionManager;
    private PlayerDataManager $playerData;

    public function __construct(NPCManager $npcManager, ShopManager $shopManager, ConditionManager $conditionManager, PlayerDataManager $playerData) {
        $this->npcManager = $npcManager;
        $this->shopManager = $shopManager;
        $this->conditionManager = $conditionManager;
        $this->playerData = $playerData;
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
            "id" => "",
            "text" => "Continuer",
            "action" => self::ACTION_CLOSE,
            "value" => "",
            "permission" => "",
            "conditions" => [],
            "showWhenLocked" => false,
            "lockedMessage" => ""
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
        foreach($tree["nodes"] as &$node) {
            if(!is_array($node) || !isset($node["choices"]) || !is_array($node["choices"])) continue;

            foreach($node["choices"] as &$choice) {
                if(!is_array($choice)) continue;
                if(trim((string)($choice["id"] ?? "")) === "") {
                    $choice["id"] = uniqid("choice_", true);
                }
            }
            unset($choice);
        }
        unset($node);

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

        $lines = [];
        foreach($node["lines"] ?? [] as $line) {
            if(!is_string($line) || trim($line) === "") continue;
            $lines[] = $line;
        }

        $index = 0;

        foreach($lines as $line) {
            $text = $this->format($line, $player, $uuid);
            $message = Messages::get("dialogue.format", ["npc" => $npcName, "line" => $text]);

            $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player, $message): void {
                if($player->isConnected()) {
                    $player->sendMessage($message);
                }
            }), $index * $delay);

            $index++;
        }

        $visible = [];
        $locked = [];

        foreach($node["choices"] ?? [] as $choice) {
            if(!is_array($choice)) continue;

            $choice = array_merge(self::getDefaultChoice(), $choice);
            $trackingId = $this->trackingId($uuid, $nodeId, $choice);

            $permission = trim((string)$choice["permission"]);
            $permissionOk = $permission === "" || $player->hasPermission($permission);
            $conditionsOk = $permissionOk && $this->conditionManager->evaluateAll($player, is_array($choice["conditions"]) ? $choice["conditions"] : [], $trackingId);

            if($conditionsOk) {
                $visible[] = $choice;
            } elseif((bool)($choice["showWhenLocked"] ?? false)) {
                $locked[] = $choice;
            }
        }

        if(empty($visible) && empty($locked)) return;

        $formDelay = count($lines) > 0 ? ((count($lines) - 1) * $delay) + $delay : 5;
        $formDelay = max(5, $formDelay);

        $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($player, $uuid, $nodeId, $npcName, $visible, $locked, $lines): void {
            if(!$player->isConnected()) return;

            $entries = [];
            foreach($visible as $choice) $entries[] = ["choice" => $choice, "locked" => false];
            foreach($locked as $choice) $entries[] = ["choice" => $choice, "locked" => true];

            $form = new SimpleForm(function(Player $player, $index) use ($uuid, $nodeId, $entries) {
                if($index === null || !isset($entries[$index])) return;

                $entry = $entries[$index];

                if($entry["locked"]) {
                    $this->sendLockedMessage($player, $uuid, $nodeId, $entry["choice"]);
                    return;
                }

                $this->execute($player, $uuid, $entry["choice"], $nodeId);
            });

            $form->setTitle("§6" . $npcName);

            $last = empty($lines) ? "" : (string)end($lines);
            $form->setContent($last === "" ? "§7..." : "§f" . $this->format($last, $player, $uuid));

            foreach($entries as $entry) {
                $prefix = $entry["locked"] ? "§8🔒 §7" : "§f";
                $form->addButton($prefix . $entry["choice"]["text"]);
            }

            $player->sendForm($form);
        }), $formDelay);
    }

    private function trackingId(string $uuid, string $nodeId, array $choice): string {
        $id = trim((string)($choice["id"] ?? ""));
        if($id !== "") return $uuid . ":" . $id;

        return $uuid . ":" . $nodeId . ":" . md5((string)($choice["text"] ?? ""));
    }

    private function sendLockedMessage(Player $player, string $uuid, string $nodeId, array $choice): void {
        $custom = trim((string)($choice["lockedMessage"] ?? ""));

        if($custom !== "") {
            $player->sendMessage($this->format($custom, $player, $uuid));
            return;
        }

        $trackingId = $this->trackingId($uuid, $nodeId, $choice);
        $conditions = is_array($choice["conditions"]) ? $choice["conditions"] : [];
        $failure = $this->conditionManager->findFailure($player, $conditions, $trackingId);

        if($failure !== null && ($failure["type"] ?? "") === ConditionManager::TYPE_COOLDOWN) {
            $remaining = $this->conditionManager->cooldownRemaining($player, $failure, $trackingId);
            $player->sendMessage(Messages::get("quest.locked-cooldown", ["seconds" => (string)$remaining]));
            return;
        }

        $player->sendMessage(Messages::get("quest.locked"));
    }

    private function format(string $line, Player $player, string $uuid): string {
        $line = str_replace("{player}", $player->getName(), $line);
        return $this->npcManager->applyPlaceholders($line, $uuid);
    }

    public function execute(Player $player, string $uuid, array $choice, string $nodeId = ""): void {
        $choice = array_merge(self::getDefaultChoice(), $choice);
        $action = (string)($choice["action"] ?? self::ACTION_CLOSE);
        $value = $this->format((string)($choice["value"] ?? ""), $player, $uuid);

        $trackingId = $this->trackingId($uuid, $nodeId, $choice);
        $this->conditionManager->markUsed($player, is_array($choice["conditions"]) ? $choice["conditions"] : [], $trackingId);

        switch($action) {
            case self::ACTION_NODE:
                $this->openNode($player, $uuid, $value);
                break;

            case self::ACTION_COMMAND:
                Compat::dispatch($player, $value);
                break;

            case self::ACTION_CONSOLE:
                Compat::dispatchConsole($value);
                break;

            case self::ACTION_SHOP:
                Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $uuid): void {
                    if($player->isConnected()) {
                        (new ShopGUI($this->npcManager, $this->shopManager))->open($player, $uuid, true);
                    }
                }), 5);
                break;

            case self::ACTION_TELEPORT:
                $this->teleport($player, $value);
                break;

            case self::ACTION_GIVE:
                $this->give($player, $value);
                break;

            case self::ACTION_TAKE:
                $this->take($player, $value);
                break;

            case self::ACTION_FLAG_SET:
                $this->setFlag($player, $value);
                break;

            case self::ACTION_FLAG_ADD:
                $this->addFlag($player, $value);
                break;

            case self::ACTION_FLAG_CLEAR:
                if(trim($value) !== "") {
                    $this->playerData->deleteFlag($player->getName(), trim($value));
                }
                break;

            case self::ACTION_MESSAGE:
                $player->sendMessage($value);
                break;
        }
    }

    private function setFlag(Player $player, string $value): void {
        $pos = strpos($value, "=");
        if($pos === false) return;

        $key = trim(substr($value, 0, $pos));
        $flagValue = trim(substr($value, $pos + 1));
        if($key === "") return;

        $this->playerData->setFlag($player->getName(), $key, $flagValue);
    }

    private function addFlag(Player $player, string $value): void {
        $pos = strpos($value, "=");
        $key = $pos === false ? trim($value) : trim(substr($value, 0, $pos));
        $amount = $pos === false ? 1.0 : (float)trim(substr($value, $pos + 1));
        if($key === "") return;

        $this->playerData->increment($player->getName(), $key, $amount);
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

    private function take(Player $player, string $value): void {
        foreach(explode(";", $value) as $entry) {
            $entry = trim($entry);
            if($entry === "") continue;

            $item = ItemParser::parse($entry);
            if($item === null) continue;

            if($player->getInventory()->contains($item)) {
                $player->getInventory()->removeItem($item);
            }
        }
    }
}
