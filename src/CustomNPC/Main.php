<?php

namespace CustomNPC;

use CustomNPC\libs\muqsit\invmenu\InvMenuHandler;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use CustomNPC\command\NPCCommandHandler;
use CustomNPC\command\NPCFlagCommand;
use CustomNPC\entity\NPCEntity;
use CustomNPC\listener\NPCListener;
use CustomNPC\manager\ConditionManager;
use CustomNPC\manager\DatabaseManager;
use CustomNPC\manager\DialogueRunner;
use CustomNPC\manager\LogManager;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\PlayerDataManager;
use CustomNPC\manager\ShopManager;
use CustomNPC\task\AnimationTask;
use CustomNPC\task\AutoSaveTask;
use CustomNPC\task\LoadNPCsTask;
use CustomNPC\task\NameTagRefreshTask;
use CustomNPC\task\NPCBehaviorTask;
use CustomNPC\task\NPCRegenTask;
use CustomNPC\task\VisibilityTask;
use CustomNPC\utils\Messages;

class Main extends PluginBase {

    private static self $instance;

    private NPCManager $npcManager;
    private DatabaseManager $databaseManager;
    private NPCCommandHandler $commandHandler;
    private ShopManager $shopManager;
    private DialogueRunner $dialogueRunner;
    private LogManager $logManager;
    private PlayerDataManager $playerDataManager;
    private ConditionManager $conditionManager;

    public function onEnable(): void {
        self::$instance = $this;

        @mkdir($this->getDataFolder() . "skins/", 0777, true);
        @mkdir($this->getDataFolder() . "races/", 0777, true);

        $this->saveDefaultConfig();
        Messages::init($this);
        $this->registerEntity();

        if(class_exists(InvMenuHandler::class)) {
            if(!InvMenuHandler::isRegistered()) {
                InvMenuHandler::register($this);
            }
        } else {
            $this->getLogger()->warning("InvMenu absent : les coffres d'equipement et de drops utiliseront des formulaires texte.");
        }

        $this->databaseManager = new DatabaseManager($this);
        $this->npcManager = new NPCManager($this, $this->databaseManager);
        $this->shopManager = new ShopManager($this->npcManager);
        $this->playerDataManager = new PlayerDataManager($this, $this->databaseManager);
        $this->conditionManager = new ConditionManager($this->playerDataManager);
        $this->dialogueRunner = new DialogueRunner($this->npcManager, $this->shopManager, $this->conditionManager, $this->playerDataManager);
        $this->logManager = new LogManager($this, $this->databaseManager);
        $this->commandHandler = new NPCCommandHandler($this->npcManager);

        $this->npcManager->loadFromDatabase();

        $this->getServer()->getPluginManager()->registerEvents(new NPCListener($this->npcManager), $this);

        $this->getScheduler()->scheduleDelayedTask(new LoadNPCsTask($this, $this->npcManager), 40);
        $this->getScheduler()->scheduleRepeatingTask(new NPCBehaviorTask($this->npcManager), 5);
        $this->getScheduler()->scheduleRepeatingTask(new AnimationTask($this->npcManager), 2);
        $this->getScheduler()->scheduleRepeatingTask(new NPCRegenTask($this->npcManager), 20);
        $this->getScheduler()->scheduleRepeatingTask(new NameTagRefreshTask($this->npcManager), 100);
        $this->getScheduler()->scheduleRepeatingTask(new VisibilityTask($this->npcManager), 40);

        $interval = max(10, (int)$this->getConfig()->getNested("settings.auto-save-interval", 60));
        $this->getScheduler()->scheduleRepeatingTask(new AutoSaveTask($this->npcManager), $interval * 20);

        $this->getServer()->getCommandMap()->register("customnpc", new \CustomNPC\command\SudoCommand());
        $this->getServer()->getCommandMap()->register("customnpc", new NPCFlagCommand($this->playerDataManager));

        $this->getLogger()->info("CustomNPC actif (" . strtoupper($this->databaseManager->getDatabaseType()) . ")");
    }

    private function registerEntity(): void {
        if(EntityFactory::getInstance()->isRegistered(NPCEntity::class)) return;

        EntityFactory::getInstance()->register(
            NPCEntity::class,
            function(World $world, CompoundTag $nbt): NPCEntity {
                return new NPCEntity(
                    EntityDataHelper::parseLocation($nbt, $world),
                    Human::parseSkinNBT($nbt),
                    $nbt
                );
            },
            ['CustomNPCEntity', 'customnpc:npc']
        );
    }

    public function onDisable(): void {
        if(isset($this->npcManager)) {
            $this->npcManager->saveAll(true);
            $this->npcManager->despawnAll();
        }

        if(isset($this->databaseManager)) {
            $this->databaseManager->close();
        }

        $this->getLogger()->info("CustomNPC desactive");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage("Cette commande doit etre executee en jeu.");
            return true;
        }

        return $this->commandHandler->handleCommand($sender, $command->getName(), $args);
    }

    public static function getInstance(): self {
        return self::$instance;
    }

    public function debugLog(string $message): void {
        if((bool)$this->getConfig()->getNested("debug.enabled", false)) {
            $this->getLogger()->info("[DEBUG] " . $message);
        }
    }

    public function debugLogAll(string $message): void {
        if((bool)$this->getConfig()->getNested("debug.log_all_messages", false)) {
            $this->getLogger()->info("[DEBUG-ALL] " . $message);
        }
    }

    public function getShopManager(): ShopManager {
        return $this->shopManager;
    }

    public function getDialogueRunner(): DialogueRunner {
        return $this->dialogueRunner;
    }

    public function getLogManager(): LogManager {
        return $this->logManager;
    }

    public function getNPCManager(): NPCManager {
        return $this->npcManager;
    }

    public function getDatabaseManager(): DatabaseManager {
        return $this->databaseManager;
    }

    public function getPlayerDataManager(): PlayerDataManager {
        return $this->playerDataManager;
    }

    public function getConditionManager(): ConditionManager {
        return $this->conditionManager;
    }
}
