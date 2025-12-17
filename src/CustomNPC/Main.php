<?php

namespace CustomNPC;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\DatabaseManager;
use CustomNPC\listener\NPCEventListener;
use CustomNPC\listener\NPCTapListener;
use CustomNPC\task\NPCBehaviorTask;
use CustomNPC\task\NPCRegenTask;
use CustomNPC\task\AutoSaveTask;

class Main extends PluginBase {

    private static self $instance;
    private NPCManager $npcManager;
    private DatabaseManager $databaseManager;

    public function onEnable(): void {
        self::$instance = $this;
        
        @mkdir($this->getDataFolder() . "skins/", 0777, true);

        $this->databaseManager = new DatabaseManager($this);
        $this->npcManager = new NPCManager($this, $this->databaseManager);

        $this->npcManager->loadFromDatabase();

        $this->getServer()->getPluginManager()->registerEvents(
            new NPCEventListener($this->npcManager), 
            $this
        );
        $this->getServer()->getPluginManager()->registerEvents(
            new NPCTapListener($this->npcManager),
            $this
        );

        $this->getScheduler()->scheduleDelayedTask(new class($this, $this->npcManager) extends \pocketmine\scheduler\Task {
            private $plugin;
            private $npcManager;
            
            public function __construct($plugin, $npcManager) {
                $this->plugin = $plugin;
                $this->npcManager = $npcManager;
            }
            
            public function onRun(): void {
                if(count($this->plugin->getServer()->getOnlinePlayers()) > 0) {
                    $count = 0;
                    foreach($this->npcManager->getAllNPCData() as $uuid => $npcInfo) {
                        $pos = $npcInfo["position"];
                        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($pos["world"]);
                        
                        if($world === null) continue;

                        $this->npcManager->spawnNPC($world, $uuid);
                        $count++;
                    }
                    
                    if($count > 0) {
                        $this->plugin->getLogger()->info("§a" . $count . " NPCs chargés !");
                    }
                }
            }
        }, 40);

        $this->getScheduler()->scheduleRepeatingTask(new NPCBehaviorTask($this->npcManager), 5);
        $this->getScheduler()->scheduleRepeatingTask(new NPCRegenTask($this->npcManager), 20);
        $this->getScheduler()->scheduleRepeatingTask(new AutoSaveTask($this->npcManager), 1200);

        $this->getLogger()->info("§aCustomNPC activé avec système de commandes !");
    }

    public function onDisable(): void {
        $this->npcManager->despawnAll();
        $this->npcManager->saveAll();
        $this->databaseManager->close();
        $this->getLogger()->info("§cCustomNPC désactivé !");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage("§cCette commande doit être exécutée en jeu !");
            return false;
        }

        $commandHandler = new \CustomNPC\command\NPCCommandHandler($this->npcManager);
        return $commandHandler->handleCommand($sender, $command->getName(), $args);
    }

    public static function getInstance(): self {
        return self::$instance;
    }

    public function getNPCManager(): NPCManager {
        return $this->npcManager;
    }

    public function getDatabaseManager(): DatabaseManager {
        return $this->databaseManager;
    }
}