<?php

namespace CustomNPC\manager;

use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\scheduler\Task;
use CustomNPC\Main;
use CustomNPC\utils\ItemParser;

class NPCManager {

    private Main $plugin;
    private DatabaseManager $database;
    private SkinManager $skinManager;
    
    private array $npcData = [];
    private array $npcUuidByEntityId = [];
    private array $npcTargets = [];
    private array $npcLastAttack = [];

    public function __construct(Main $plugin, DatabaseManager $database) {
        $this->plugin = $plugin;
        $this->database = $database;
        $this->skinManager = new SkinManager($plugin);
    }

    /**
     * Retourne l'instance du plugin
     * ✅ AJOUT PM5 : Nécessaire pour OtherInfoGUI
     */
    public function getPlugin(): Main {
        return $this->plugin;
    }

    public function getSkinManager(): SkinManager {
        return $this->skinManager;
    }

    public function loadFromDatabase(): void {
        $this->npcData = $this->database->loadAllNPCs();
        
        foreach($this->npcData as $uuid => $data) {
            $this->npcData[$uuid]["runtimeId"] = 0;
        }
        
        $this->npcUuidByEntityId = [];
        $this->plugin->getLogger()->info("§aChargé " . count($this->npcData) . " NPCs depuis la base de données");
    }

    public function saveAll(): void {
        foreach($this->npcData as $uuid => $data) {
            $this->saveNPC($uuid);
        }
    }

    public function saveNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        $pos = $data["position"];
        
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($pos["world"]);
        if($world !== null) {
            $entity = $world->getEntity($data["runtimeId"] ?? 0);
            if($entity instanceof Living) {
                $data["health"] = $entity->getHealth();
                $this->npcData[$uuid]["health"] = $data["health"];
            }
        }
        
        $this->database->saveNPC($uuid, $data);
    }

    public function deleteNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
        
        if($world !== null) {
            $entity = $world->getEntity($data["runtimeId"] ?? 0);
            if($entity !== null) {
                $entity->flagForDespawn();
            }
        }

        unset($this->npcData[$uuid]);
        unset($this->npcTargets[$uuid]);
        unset($this->npcLastAttack[$uuid]);
        unset($this->npcUuidByEntityId[$data["runtimeId"] ?? 0]);
        
        $this->database->deleteNPC($uuid);
    }

    public function createNPC(array $data): string {
        $uuid = uniqid("npc_");
        $this->npcData[$uuid] = $data;
        $this->saveNPC($uuid);
        return $uuid;
    }

    public function spawnNPC(World $world, string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        
        // Despawn l'ancien NPC s'il existe
        $oldEntityId = $data["runtimeId"] ?? 0;
        if($oldEntityId > 0) {
            $oldEntity = $world->getEntity($oldEntityId);
            if($oldEntity !== null && !$oldEntity->isClosed()) {
                $oldEntity->flagForDespawn();
            }
            unset($this->npcUuidByEntityId[$oldEntityId]);
        }
        
        $pos = $data["position"];
        $yaw = $data["yaw"] ?? 0.0;
        $pitch = $data["pitch"] ?? 0.0;
        $location = new Location($pos["x"], $pos["y"], $pos["z"], $world, $yaw, $pitch);

        // Charger le skin
        $skinPath = $data["skin"] ?? "";
        
        $this->plugin->getLogger()->info("§eChargement du skin pour NPC {$uuid}: '{$skinPath}'");
        
        // Si on a des données de skin sauvegardées, les utiliser directement
        if(isset($data["savedSkin"]) && is_array($data["savedSkin"]) && !empty($data["savedSkin"]["skinData"])) {
            $this->plugin->getLogger()->info("§aUtilisation du skin sauvegardé");
            $skin = $this->loadSavedSkin($data["savedSkin"]);
        } else {
            $this->plugin->getLogger()->info("§eChargement du skin depuis: {$skinPath}");
            $skin = $this->skinManager->loadSkin($skinPath, null);
            
            // Si c'est un skin de joueur et qu'il a été chargé avec succès, le sauvegarder
            if(strpos($skinPath, "player:") === 0 && $skin->getSkinId() !== "Standard_Steve") {
                $this->plugin->getLogger()->info("§aSauvegarde du skin de joueur...");
                $this->saveSkinData($uuid, $skin);
            }
        }
        
        $nbt = CompoundTag::create();
        
        if($data["immobile"] ?? false) {
            $nbt->setByte("Immobile", 1);
        }
        
        // Créer l'entité Human avec le skin
        $entity = new Human($location, $skin, $nbt);

        $maxHealth = (float)($data["maxHealth"] ?? 100.0);
        $currentHealth = (float)($data["health"] ?? $maxHealth);
        
        $entity->setMaxHealth($maxHealth);
        $entity->setHealth($currentHealth);
        $entity->setScale($data["size"] ?? 1.0);
        $entity->setNameTag($data["title"] ?? "NPC");
        $entity->setNameTagVisible(true);
        $entity->setNameTagAlwaysVisible(true);
        
        if($data["immobile"] ?? false) {
            $entity->setHasGravity(false);
        }
        
        $this->equipArmor($entity, $data["armor"] ?? []);
        
        // IMPORTANT : Spawn l'entité d'abord
        $entity->spawnToAll();
        
        // Forcer le rafraîchissement du skin après le spawn
        $this->refreshSkin($entity, $skin);

        $entityId = $entity->getId();
        $this->npcData[$uuid]["runtimeId"] = $entityId;
        $this->npcUuidByEntityId[$entityId] = $uuid;
        
        $this->updateNameTag($entity, $uuid);
        $this->scheduleRefresh($entity, $uuid, $data, $maxHealth, $currentHealth);
    }

    /**
     * Force le rafraîchissement du skin pour tous les joueurs
     */
    private function refreshSkin(Human $entity, \pocketmine\entity\Skin $skin): void {
        // TEMPORAIREMENT DÉSACTIVÉ pour debug
        // Le despawn/respawn peut causer des crashes
        
        // Simple envoi du skin sans despawn
        $this->plugin->getScheduler()->scheduleDelayedTask(new class($entity, $skin) extends Task {
            private $entity;
            private $skin;

            public function __construct($entity, $skin) {
                $this->entity = $entity;
                $this->skin = $skin;
            }

            public function onRun(): void {
                if(!$this->entity->isClosed()) {
                    $this->entity->setSkin($this->skin);
                    $this->entity->sendSkin();
                }
            }
        }, 5);
    }

    /**
     * Sauvegarde les données du skin dans la config du NPC
     */
    private function saveSkinData(string $uuid, \pocketmine\entity\Skin $skin): void {
        $this->npcData[$uuid]["savedSkin"] = [
            "skinId" => $skin->getSkinId(),
            "skinData" => base64_encode($skin->getSkinData()),
            "capeData" => base64_encode($skin->getCapeData()),
            "geometryName" => $skin->getGeometryName(),
            "geometryData" => base64_encode($skin->getGeometryData())
        ];
        $this->saveNPC($uuid);
    }
    
    /**
     * Charge un skin depuis les données sauvegardées
     */
    private function loadSavedSkin(array $savedSkin): \pocketmine\entity\Skin {
        $skinId = $savedSkin["skinId"] ?? "CustomNPC";
        $skinData = base64_decode($savedSkin["skinData"] ?? "");
        $capeData = base64_decode($savedSkin["capeData"] ?? "");
        $geometryName = $savedSkin["geometryName"] ?? "";
        $geometryData = base64_decode($savedSkin["geometryData"] ?? "");
        
        $this->plugin->getLogger()->info("§eChargement skin sauvegardé:");
        $this->plugin->getLogger()->info("  - ID: {$skinId}");
        $this->plugin->getLogger()->info("  - Data length: " . strlen($skinData));
        $this->plugin->getLogger()->info("  - Geometry: {$geometryName}");
        
        // Vérifier que les données ne sont pas vides
        if(strlen($skinData) === 0) {
            $this->plugin->getLogger()->error("§cSkin data vide ! Utilisation du skin par défaut");
            return $this->skinManager->loadSkin("", null);
        }
        
        return new \pocketmine\entity\Skin(
            $skinId,
            $skinData,
            $capeData,
            $geometryName,
            $geometryData
        );
    }

    private function scheduleRefresh(Human $entity, string $uuid, array $data, float $maxHealth, float $currentHealth): void {
        for($i = 1; $i <= 5; $i++) {
            $this->plugin->getScheduler()->scheduleDelayedTask(new class($this, $entity, $uuid, $maxHealth, $currentHealth, $data) extends Task {
                private $manager;
                private $entity;
                private $uuid;
                private $maxHealth;
                private $currentHealth;
                private $data;

                public function __construct($manager, $entity, $uuid, $maxHealth, $currentHealth, $data) {
                    $this->manager = $manager;
                    $this->entity = $entity;
                    $this->uuid = $uuid;
                    $this->maxHealth = $maxHealth;
                    $this->currentHealth = $currentHealth;
                    $this->data = $data;
                }

                public function onRun(): void {
                    if(!$this->entity->isClosed()) {
                        $this->entity->setMaxHealth($this->maxHealth);
                        $this->entity->setHealth($this->currentHealth);
                        $this->entity->setScale($this->data["size"] ?? 1.0);
                        $this->entity->setNameTagVisible(true);
                        $this->entity->setNameTagAlwaysVisible(true);
                        
                        if($this->data["immobile"] ?? false) {
                            $this->entity->setHasGravity(false);
                        }
                        
                        $armorInv = $this->entity->getArmorInventory();
                        $armor = $this->data["armor"] ?? [];
                        
                        if(!empty($armor["helmet"])){
                            $item = ItemParser::parse($armor["helmet"]);
                            if($item !== null) $armorInv->setHelmet($item);
                        }
                        if(!empty($armor["chestplate"])){
                            $item = ItemParser::parse($armor["chestplate"]);
                            if($item !== null) $armorInv->setChestplate($item);
                        }
                        if(!empty($armor["leggings"])){
                            $item = ItemParser::parse($armor["leggings"]);
                            if($item !== null) $armorInv->setLeggings($item);
                        }
                        if(!empty($armor["boots"])){
                            $item = ItemParser::parse($armor["boots"]);
                            if($item !== null) $armorInv->setBoots($item);
                        }
                        if(!empty($armor["hand"])){
                            $item = ItemParser::parse($armor["hand"]);
                            if($item !== null) $this->entity->getInventory()->setItemInHand($item);
                        }
                        
                        // Re-envoyer le skin pour être sûr
                        $this->entity->sendSkin();
                        
                        $this->manager->updateNameTag($this->entity, $this->uuid);
                    }
                }
            }, 5 * $i);
        }
    }

    private function equipArmor(Human $entity, array $armorData): void {
        $armorInv = $entity->getArmorInventory();
        
        if(!empty($armorData["helmet"])) {
            $item = ItemParser::parse($armorData["helmet"]);
            if($item !== null) $armorInv->setHelmet($item);
        }
        
        if(!empty($armorData["chestplate"])) {
            $item = ItemParser::parse($armorData["chestplate"]);
            if($item !== null) $armorInv->setChestplate($item);
        }
        
        if(!empty($armorData["leggings"])) {
            $item = ItemParser::parse($armorData["leggings"]);
            if($item !== null) $armorInv->setLeggings($item);
        }
        
        if(!empty($armorData["boots"])) {
            $item = ItemParser::parse($armorData["boots"]);
            if($item !== null) $armorInv->setBoots($item);
        }
        
        if(!empty($armorData["hand"])) {
            $item = ItemParser::parse($armorData["hand"]);
            if($item !== null) $entity->getInventory()->setItemInHand($item);
        }
    }

    public function updateNameTag(Living $entity, string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        $title = $data["title"] ?? "NPC";
        $subtitle = $data["subtitle"] ?? "";
        
        $nameTag = $title;
        if($subtitle !== "") {
            $nameTag .= "\n" . $subtitle;
        }
        
        if($data["aggressive"] ?? false) {
            $health = (int)$entity->getHealth();
            $maxHealth = (int)($data["maxHealth"] ?? 100);
            $nameTag .= "\n§c" . $health . " §r/ §c" . $maxHealth;
        }
        
        $entity->setNameTag($nameTag);
    }

    public function updateNPC(World $world, string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        $oldEntityId = $data["runtimeId"] ?? 0;
        $entity = $world->getEntity($oldEntityId);

        if($entity !== null) {
            $entity->flagForDespawn();
            unset($this->npcUuidByEntityId[$oldEntityId]);
        }

        $this->spawnNPC($world, $uuid);
    }

    public function respawnNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;
        
        $data = $this->npcData[$uuid];
        $this->npcData[$uuid]["health"] = (float)($data["maxHealth"] ?? 100.0);
        
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
        
        if($world !== null) {
            $oldEntityId = $data["runtimeId"] ?? 0;
            if($oldEntityId > 0) {
                unset($this->npcUuidByEntityId[$oldEntityId]);
            }
            
            $this->spawnNPC($world, $uuid);
            $this->saveNPC($uuid);
        }
    }

    public function despawnAll(): void {
        foreach($this->npcData as $uuid => $data) {
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            if($world !== null) {
                $entity = $world->getEntity($data["runtimeId"] ?? 0);
                if($entity !== null && !$entity->isClosed()) {
                    $entity->flagForDespawn();
                }
            }
        }
        $this->npcUuidByEntityId = [];
    }

    public function findNPCByEntityId(int $entityId): ?string {
        return $this->npcUuidByEntityId[$entityId] ?? null;
    }

    public function repairMapping(int $entityId, string $uuid): void {
        $this->npcUuidByEntityId[$entityId] = $uuid;
        if(isset($this->npcData[$uuid])) {
            $this->npcData[$uuid]["runtimeId"] = $entityId;
        }
    }

    public function getNPCData(string $uuid): ?array {
        return $this->npcData[$uuid] ?? null;
    }

    public function getAllNPCData(): array {
        return $this->npcData;
    }

    public function updateNPCData(string $uuid, array $data): void {
        if(!isset($this->npcData[$uuid])) return;
        $this->npcData[$uuid] = array_merge($this->npcData[$uuid], $data);
    }

    public function getTarget(string $uuid): ?string {
        return $this->npcTargets[$uuid] ?? null;
    }

    public function setTarget(string $uuid, ?string $playerName): void {
        if($playerName === null) {
            unset($this->npcTargets[$uuid]);
        } else {
            $this->npcTargets[$uuid] = $playerName;
        }
    }

    public function canAttack(string $uuid): bool {
        if(!isset($this->npcData[$uuid])) return false;
        
        $now = microtime(true);
        $lastAttack = $this->npcLastAttack[$uuid] ?? 0;
        $attackSpeed = $this->npcData[$uuid]["attackSpeed"] ?? 1;
        $cooldown = 1.0 / $attackSpeed;

        if($now - $lastAttack >= $cooldown) {
            $this->npcLastAttack[$uuid] = $now;
            return true;
        }
        return false;
    }

    public function getDefaultNPCData(float $x, float $y, float $z, string $worldName): array {
        return [
            "title" => "NPC",
            "subtitle" => "",
            "position" => ["x" => $x, "y" => $y, "z" => $z, "world" => $worldName],
            "yaw" => 0.0,
            "pitch" => 0.0,
            "runtimeId" => 0,
            "health" => 100.0,
            "maxHealth" => 100.0,
            "speed" => 1,
            "aggressive" => false,
            "attackSpeed" => 1,
            "attackDamage" => 1,
            "arrowAttack" => false,
            "arrowSpeed" => 1,
            "effectOnHit" => "",
            "canRegen" => false,
            "regenAmount" => 1,
            "size" => 1.0,
            "entityType" => "human",
            "skin" => "",
            "immobile" => false,
            "autoRespawn" => false,
            "canBeHit" => true,
            "commands" => [],
            "drops" => [],
            "armor" => [
                "helmet" => "",
                "chestplate" => "",
                "leggings" => "",
                "boots" => "",
                "hand" => ""
            ]
        ];
    }

    public function getDefaultNPCDataWithRotation(float $x, float $y, float $z, string $worldName, float $yaw, float $pitch): array {
        $data = $this->getDefaultNPCData($x, $y, $z, $worldName);
        $data["yaw"] = $yaw;
        $data["pitch"] = $pitch;
        return $data;
    }
    
    /**
     * Méthode pour changer le skin d'un NPC existant
     */
    public function changeSkin(string $uuid, string $skinPath): bool {
        if(!isset($this->npcData[$uuid])) return false;
        
        try {
            // Charger le nouveau skin
            $skin = $this->skinManager->loadSkin($skinPath, null);
            
            // Mettre à jour les données
            $this->npcData[$uuid]["skin"] = $skinPath;
            
            // Sauvegarder les données du skin si c'est un skin de joueur
            if(strpos($skinPath, "player:") === 0) {
                $this->saveSkinData($uuid, $skin);
            }
            
            // Obtenir l'entité
            $data = $this->npcData[$uuid];
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            
            if($world !== null) {
                $entity = $world->getEntity($data["runtimeId"] ?? 0);
                
                if($entity instanceof Human && !$entity->isClosed()) {
                    // Appliquer le nouveau skin
                    $entity->setSkin($skin);
                    $entity->sendSkin();
                    
                    $this->saveNPC($uuid);
                    return true;
                }
            }
            
            return false;
        } catch(\Exception $e) {
            $this->plugin->getLogger()->error("Erreur changement de skin: " . $e->getMessage());
            return false;
        }
    }
}
