<?php

namespace CustomNPC\manager;

use pocketmine\entity\Location;
use pocketmine\entity\Living;
use pocketmine\entity\Skin;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\scheduler\ClosureTask;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\World;
use CustomNPC\Main;
use CustomNPC\entity\NPCEntity;
use CustomNPC\utils\Constants;
use CustomNPC\utils\ItemParser;

class NPCManager {

    private Main $plugin;
    private DatabaseManager $database;
    private SkinManager $skinManager;
    private RaceManager $raceManager;
    private ModelManager $modelManager;

    private array $npcData = [];
    private array $npcUuidByEntityId = [];
    private array $npcTargets = [];
    private array $npcLastAttack = [];
    private array $dirty = [];
    private array $waitingForUuid = [];
    private array $adminPlayers = [];
    private array $selection = [];
    private array $clipboard = [];

    public function __construct(Main $plugin, DatabaseManager $database) {
        $this->plugin = $plugin;
        $this->database = $database;
        $this->skinManager = new SkinManager($plugin);
        $this->raceManager = new RaceManager($plugin);
        $this->modelManager = new ModelManager();
    }

    public function getPlugin(): Main {
        return $this->plugin;
    }

    public function getSkinManager(): SkinManager {
        return $this->skinManager;
    }

    public function getRaceManager(): RaceManager {
        return $this->raceManager;
    }

    public function getModelManager(): ModelManager {
        return $this->modelManager;
    }

    public function loadFromDatabase(): void {
        $this->npcData = $this->database->loadAllNPCs();

        foreach($this->npcData as $uuid => $data) {
            $this->npcData[$uuid] = array_merge($this->getDefaultNPCData(0, 0, 0, ""), $data);
            $this->npcData[$uuid]["runtimeId"] = 0;
            $this->npcData[$uuid]["pose"] = $this->modelManager->normalizePose((string)($this->npcData[$uuid]["pose"] ?? Constants::DEFAULT_POSE));
            $this->npcData[$uuid]["skinModel"] = $this->modelManager->normalizeModel((string)($this->npcData[$uuid]["skinModel"] ?? Constants::MODEL_STEVE));
        }

        $this->npcUuidByEntityId = [];
        $this->plugin->getLogger()->info(count($this->npcData) . " NPCs charges depuis la base");

        if((bool)$this->plugin->getConfig()->getNested("settings.admin-mode-persistent", false)) {
            $this->loadAdmins();
        }
    }

    private function loadAdmins(): void {
        $file = $this->plugin->getDataFolder() . "admins.json";
        if(file_exists($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            $this->adminPlayers = is_array($decoded) ? $decoded : [];
        }
    }

    private function saveAdmins(): void {
        if(!(bool)$this->plugin->getConfig()->getNested("settings.admin-mode-persistent", false)) return;
        file_put_contents($this->plugin->getDataFolder() . "admins.json", json_encode(array_values($this->adminPlayers)));
    }

    public function isAdmin(string $playerName): bool {
        return in_array(strtolower($playerName), $this->adminPlayers, true);
    }

    public function setAdmin(string $playerName, bool $value): void {
        $key = strtolower($playerName);

        if($value) {
            if(!in_array($key, $this->adminPlayers, true)) {
                $this->adminPlayers[] = $key;
            }
        } else {
            $this->adminPlayers = array_values(array_filter($this->adminPlayers, fn($name) => $name !== $key));
        }

        $this->saveAdmins();
    }

    public function select(Player $player, ?string $uuid): void {
        if($uuid === null) {
            unset($this->selection[$player->getName()]);
        } else {
            $this->selection[$player->getName()] = $uuid;
        }
    }

    public function getSelection(Player $player): ?string {
        $uuid = $this->selection[$player->getName()] ?? null;
        return ($uuid !== null && isset($this->npcData[$uuid])) ? $uuid : null;
    }

    public function setClipboard(string $playerName, array $data): void {
        $this->clipboard[$playerName] = $data;
    }

    public function getClipboard(string $playerName): ?array {
        return $this->clipboard[$playerName] ?? null;
    }

    public function resolve(string $identifier): ?string {
        if(isset($this->npcData[$identifier])) return $identifier;

        $needle = strtolower($identifier);
        foreach($this->npcData as $uuid => $data) {
            if(strtolower((string)($data["customId"] ?? "")) === $needle && $needle !== "") {
                return $uuid;
            }
        }

        return null;
    }

    public function customIdExists(string $customId, ?string $ignoreUuid = null): bool {
        $needle = strtolower($customId);
        foreach($this->npcData as $uuid => $data) {
            if($uuid === $ignoreUuid) continue;
            if(strtolower((string)($data["customId"] ?? "")) === $needle && $needle !== "") {
                return true;
            }
        }
        return false;
    }

    public function findNearest(Player $player, float $radius): ?string {
        $best = null;
        $bestDistance = $radius;
        $world = $player->getWorld();

        foreach($this->npcData as $uuid => $data) {
            if(($data["position"]["world"] ?? "") !== $world->getFolderName()) continue;

            $entity = $this->getEntity($uuid);
            if($entity === null) continue;

            $distance = $entity->getPosition()->distance($player->getPosition());
            if($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $uuid;
            }
        }

        return $best;
    }

    public function findLookedAt(Player $player, float $radius): ?string {
        $eye = $player->getEyePos();
        $direction = $player->getDirectionVector();

        $best = null;
        $bestScore = 0.94;

        foreach($this->npcData as $uuid => $data) {
            if(($data["position"]["world"] ?? "") !== $player->getWorld()->getFolderName()) continue;

            $entity = $this->getEntity($uuid);
            if($entity === null) continue;

            $to = $entity->getPosition()->add(0, $entity->getEyeHeight(), 0)->subtractVector($eye);
            $distance = $to->length();
            if($distance > $radius || $distance < 0.0001) continue;

            $dot = $to->normalize()->dot($direction);
            if($dot > $bestScore) {
                $bestScore = $dot;
                $best = $uuid;
            }
        }

        return $best ?? $this->findNearest($player, $radius);
    }

    public function markDirty(string $uuid): void {
        $this->dirty[$uuid] = true;
    }

    public function saveAll(bool $force = false): void {
        $batch = [];

        foreach($this->npcData as $uuid => $data) {
            if(!$force && !isset($this->dirty[$uuid])) continue;

            $entity = $this->getEntity($uuid);
            if($entity instanceof Living && !$entity->isClosed()) {
                $this->npcData[$uuid]["health"] = $entity->getHealth();
            }

            $batch[$uuid] = $this->npcData[$uuid];
        }

        if(empty($batch)) return;

        $this->database->saveBatch($batch);
        $this->dirty = [];
    }

    public function saveNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;

        $entity = $this->getEntity($uuid);
        if($entity instanceof Living && !$entity->isClosed()) {
            $this->npcData[$uuid]["health"] = $entity->getHealth();
        }

        $this->database->saveNPC($uuid, $this->npcData[$uuid]);
        unset($this->dirty[$uuid]);
    }

    public function deleteNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;

        $entity = $this->getEntity($uuid);
        if($entity !== null && !$entity->isClosed()) {
            $entity->flagForDespawn();
        }

        $entityId = $this->npcData[$uuid]["runtimeId"] ?? 0;

        unset($this->npcData[$uuid], $this->npcTargets[$uuid], $this->npcLastAttack[$uuid], $this->dirty[$uuid], $this->npcUuidByEntityId[$entityId]);

        foreach($this->selection as $player => $selected) {
            if($selected === $uuid) unset($this->selection[$player]);
        }

        $this->database->deleteNPC($uuid);
    }

    public function createNPC(array $data): string {
        $uuid = uniqid("npc_");
        $this->npcData[$uuid] = array_merge($this->getDefaultNPCData(0, 0, 0, ""), $data);
        $this->saveNPC($uuid);
        return $uuid;
    }

    public function getEntity(string $uuid): ?NPCEntity {
        $data = $this->npcData[$uuid] ?? null;
        if($data === null) return null;

        $entityId = $data["runtimeId"] ?? 0;
        if($entityId === 0) return null;

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName((string)($data["position"]["world"] ?? ""));
        if($world === null || !$world->isLoaded()) return null;

        $entity = $world->getEntity($entityId);
        return ($entity instanceof NPCEntity && !$entity->isClosed()) ? $entity : null;
    }

    public function spawnNPC(World $world, string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;

        $data = $this->npcData[$uuid];
        if($data["stored"] ?? false) return;

        $existing = $this->getEntity($uuid);
        if($existing !== null) {
            unset($this->npcUuidByEntityId[$existing->getId()]);
            $existing->flagForDespawn();
        }

        $position = $data["position"];
        $yaw = (float)($data["yaw"] ?? 0.0);
        $pitch = max(-90.0, min(90.0, (float)($data["pitch"] ?? 0.0)));
        $headYaw = (float)($data["headYaw"] ?? $yaw);

        $race = (string)($data["race"] ?? Constants::DEFAULT_RACE);
        $pose = $this->modelManager->normalizePose((string)($data["pose"] ?? Constants::DEFAULT_POSE));
        $hitbox = $this->modelManager->getHitbox($race, $pose);

        $location = new Location((float)$position["x"], (float)$position["y"], (float)$position["z"], $world, $yaw, $pitch);

        $nbt = CompoundTag::create();
        if($data["immobile"] ?? false) {
            $nbt->setByte("Immobile", 1);
        }

        $entity = new NPCEntity($location, $this->buildSkin($data), $nbt, $hitbox["height"], $hitbox["eyeHeight"], $hitbox["width"]);
        $entity->setNpcUuid($uuid);
        $entity->setHeadYaw($headYaw);

        $maxHealth = max(Constants::MIN_HEALTH, (float)($data["maxHealth"] ?? 100.0));
        $health = (float)($data["health"] ?? $maxHealth);
        $health = max(Constants::MIN_HEALTH, min($maxHealth, $health));

        $entity->setMaxHealth($maxHealth);
        $entity->setHealth($health);
        $entity->setScale(max(Constants::MIN_SIZE, min(Constants::MAX_SIZE, (float)($data["size"] ?? 1.0))));
        $entity->setNpcImmobile((bool)($data["immobile"] ?? false));
        $entity->applyNametagMode((string)($data["nametagMode"] ?? Constants::NAMETAG_ALWAYS));

        $this->equip($entity, $data["armor"] ?? []);

        if(VisibilityManager::isRestricted($data)) {
            foreach($world->getPlayers() as $viewer) {
                if(VisibilityManager::canSee($viewer, $data)) {
                    $entity->spawnTo($viewer);
                }
            }
        } else {
            $entity->spawnToAll();
        }

        $this->npcData[$uuid]["runtimeId"] = $entity->getId();
        $this->npcData[$uuid]["health"] = $health;
        $this->npcUuidByEntityId[$entity->getId()] = $uuid;

        $this->updateNameTag($uuid);
    }

    public function buildSkin(array $data): Skin {
        $race = (string)($data["race"] ?? Constants::DEFAULT_RACE);
        $pose = $this->modelManager->normalizePose((string)($data["pose"] ?? Constants::DEFAULT_POSE));
        $slim = $this->modelManager->isSlim($data);

        $texture = null;
        $skinPath = trim((string)($data["skin"] ?? ""));

        if($skinPath !== "" && !str_starts_with($skinPath, "player:")) {
            $texture = $this->skinManager->loadTexture($skinPath);
        }

        if($texture === null) {
            $texture = $this->skinManager->decodeTexture($data["savedSkin"] ?? null);
        }

        if($texture === null && $skinPath !== "") {
            $texture = $this->skinManager->loadTexture($skinPath);
        }

        if($texture === null && $race !== Constants::DEFAULT_RACE && $this->raceManager->raceExists($race)) {
            $path = $this->raceManager->getTexturePath($race);
            if($path !== null) {
                $texture = $this->skinManager->readTexture($path);
            }
        }

        if($texture === null) {
            $texture = $this->skinManager->getDefaultTexture();
        }

        $geometryName = $this->modelManager->getIdentifier($race, $pose, $slim);
        $geometryData = $this->modelManager->buildGeometry($race, $pose, $slim);

        return $this->skinManager->buildSkin($texture, $geometryName, $geometryData);
    }

    public function refreshSkin(string $uuid): void {
        $entity = $this->getEntity($uuid);
        if($entity === null) return;

        $entity->setSkin($this->buildSkin($this->npcData[$uuid]));
        $entity->sendSkin();
    }

    private function equip(NPCEntity $entity, array $armor): void {
        $inventory = $entity->getArmorInventory();

        $helmet = ItemParser::deserialize((string)($armor["helmet"] ?? ""));
        $chestplate = ItemParser::deserialize((string)($armor["chestplate"] ?? ""));
        $leggings = ItemParser::deserialize((string)($armor["leggings"] ?? ""));
        $boots = ItemParser::deserialize((string)($armor["boots"] ?? ""));
        $hand = ItemParser::deserialize((string)($armor["hand"] ?? ""));
        $offhand = ItemParser::deserialize((string)($armor["offhand"] ?? ""));

        $inventory->setHelmet($helmet ?? ItemParser::air());
        $inventory->setChestplate($chestplate ?? ItemParser::air());
        $inventory->setLeggings($leggings ?? ItemParser::air());
        $inventory->setBoots($boots ?? ItemParser::air());

        $handItem = $hand ?? ItemParser::air();
        $offhandItem = $offhand ?? ItemParser::air();

        $entity->getInventory()->setItemInHand($handItem);
        $entity->getOffHandInventory()->setItem(0, $offhandItem);

        $entityId = $entity->getId();
        $world = $entity->getWorld();

        foreach([3, 10] as $delay) {
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($entityId, $world, $handItem, $offhandItem): void {
                if(!$world->isLoaded()) return;

                $entity = $world->getEntity($entityId);
                if(!($entity instanceof NPCEntity) || $entity->isClosed() || empty($entity->getViewers())) return;

                $entity->getInventory()->setItemInHand(clone $handItem);
                $entity->getOffHandInventory()->setItem(0, clone $offhandItem);
            }), $delay);
        }
    }

    public function buildNameTag(string $uuid, bool $adminView = false): string {
        $data = $this->npcData[$uuid] ?? null;
        if($data === null) return "";

        $nameTag = $this->applyPlaceholders((string)($data["title"] ?? "NPC"), $uuid);

        $subtitle = (string)($data["subtitle"] ?? "");
        if($subtitle !== "") {
            $nameTag .= "\n" . $this->applyPlaceholders($subtitle, $uuid);
        }

        if($data["aggressive"] ?? false) {
            $entity = $this->getEntity($uuid);
            $health = (int)($entity !== null ? $entity->getHealth() : ($data["health"] ?? 0));
            $nameTag .= "\n§c" . $health . " §r/ §c" . (int)($data["maxHealth"] ?? 100);
        }

        if($adminView) {
            $creator = (string)($data["creator"] ?? "");
            $customId = (string)($data["customId"] ?? "");
            $nameTag .= "\n§l§cID: §r§f" . ($customId !== "" ? $customId : $uuid);
            if($creator !== "") {
                $nameTag .= "\n§l§cPlace par: §r§f" . $creator;
            }
        }

        return $nameTag;
    }

    public function applyPlaceholders(string $text, string $uuid): string {
        if(!str_contains($text, "{")) return $text;

        $server = $this->plugin->getServer();
        $data = $this->npcData[$uuid] ?? [];
        $worldName = (string)($data["position"]["world"] ?? "");
        $world = $server->getWorldManager()->getWorldByName($worldName);

        return str_replace(
            ["{online}", "{max}", "{world}", "{world_players}", "{tps}"],
            [
                (string)count($server->getOnlinePlayers()),
                (string)$server->getMaxPlayers(),
                $worldName,
                (string)($world !== null ? count($world->getPlayers()) : 0),
                (string)round($server->getTicksPerSecond(), 1)
            ],
            $text
        );
    }

    public function updateNameTag(string $uuid): void {
        $entity = $this->getEntity($uuid);
        if($entity === null) return;

        $entity->setNameTag($this->buildNameTag($uuid, false));
        $entity->applyNametagMode((string)($this->npcData[$uuid]["nametagMode"] ?? Constants::NAMETAG_ALWAYS));

        foreach($entity->getViewers() as $viewer) {
            if($this->isAdmin($viewer->getName())) {
                $this->sendAdminNameTag($viewer, $uuid);
            }
        }
    }

    public function sendAdminNameTag(Player $player, string $uuid): void {
        $entity = $this->getEntity($uuid);
        if($entity === null) return;

        $packet = SetActorDataPacket::create(
            $entity->getId(),
            [EntityMetadataProperties::NAMETAG => new StringMetadataProperty($this->buildNameTag($uuid, true))],
            new PropertySyncData([], []),
            0
        );

        $player->getNetworkSession()->sendDataPacket($packet);
    }

    public function refreshNPCsForPlayer(Player $player): void {
        $isAdmin = $this->isAdmin($player->getName());
        $worldName = $player->getWorld()->getFolderName();

        foreach($this->npcData as $uuid => $data) {
            if(($data["position"]["world"] ?? "") !== $worldName) continue;

            $entity = $this->getEntity($uuid);
            if($entity === null) continue;

            if($isAdmin) {
                $this->sendAdminNameTag($player, $uuid);
            } else {
                $packet = SetActorDataPacket::create(
                    $entity->getId(),
                    [EntityMetadataProperties::NAMETAG => new StringMetadataProperty($this->buildNameTag($uuid, false))],
                    new PropertySyncData([], []),
                    0
                );
                $player->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    public function updateNPC(string $uuid): void {
        $data = $this->npcData[$uuid] ?? null;
        if($data === null) return;

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName((string)($data["position"]["world"] ?? ""));
        if($world === null) return;

        $this->spawnNPC($world, $uuid);
    }

    public function respawnNPC(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;

        $this->npcData[$uuid]["health"] = (float)($this->npcData[$uuid]["maxHealth"] ?? 100.0);
        $this->npcData[$uuid]["stored"] = false;
        $this->updateNPC($uuid);
        $this->markDirty($uuid);
    }

    public function despawnAll(): void {
        foreach(array_keys($this->npcData) as $uuid) {
            $entity = $this->getEntity($uuid);
            if($entity !== null) {
                $entity->flagForDespawn();
            }
        }
        $this->npcUuidByEntityId = [];
    }

    public function spawnWorld(World $world): int {
        $count = 0;
        foreach($this->npcData as $uuid => $data) {
            if(($data["position"]["world"] ?? "") !== $world->getFolderName()) continue;
            if($data["stored"] ?? false) continue;
            if($this->getEntity($uuid) !== null) continue;

            $this->spawnNPC($world, $uuid);
            $count++;
        }
        return $count;
    }

    public function findNPCByEntityId(int $entityId): ?string {
        return $this->npcUuidByEntityId[$entityId] ?? null;
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
        $this->markDirty($uuid);
    }

    public function getWaypoints(string $uuid): array {
        $data = $this->npcData[$uuid] ?? null;
        if($data === null) return [];

        $waypoints = $data["waypoints"] ?? [];
        if(!is_array($waypoints)) return [];

        $clean = [];
        foreach($waypoints as $point) {
            if(!is_array($point) || !isset($point["x"], $point["y"], $point["z"])) continue;

            $clean[] = [
                "x" => (float)$point["x"],
                "y" => (float)$point["y"],
                "z" => (float)$point["z"],
                "wait" => max(0, (int)($point["wait"] ?? 0))
            ];
        }

        return $clean;
    }

    public function setWaypoints(string $uuid, array $waypoints): void {
        if(!isset($this->npcData[$uuid])) return;

        $this->npcData[$uuid]["waypoints"] = array_values($waypoints);
        $this->markDirty($uuid);
        $this->saveNPC($uuid);
    }

    public function refreshNameTagFor(Player $player, string $uuid): void {
        if($this->isAdmin($player->getName())) {
            $this->sendAdminNameTag($player, $uuid);
        }
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
        $last = $this->npcLastAttack[$uuid] ?? 0.0;
        $cooldown = 1.0 / max(1, (int)($this->npcData[$uuid]["attackSpeed"] ?? 1));

        if($now - $last >= $cooldown) {
            $this->npcLastAttack[$uuid] = $now;
            return true;
        }

        return false;
    }

    public function setWaitingForUuid(string $playerName, bool $waiting): void {
        if($waiting) {
            $this->waitingForUuid[$playerName] = true;
        } else {
            unset($this->waitingForUuid[$playerName]);
        }
    }

    public function isWaitingForUuid(string $playerName): bool {
        return isset($this->waitingForUuid[$playerName]);
    }

    public function handleQuit(Player $player): void {
        $name = $player->getName();
        unset($this->selection[$name], $this->waitingForUuid[$name], $this->clipboard[$name]);

        if(!(bool)$this->plugin->getConfig()->getNested("settings.admin-mode-persistent", false)) {
            $this->setAdmin($name, false);
        }
    }

    public function countInWorld(string $worldName): int {
        $count = 0;
        foreach($this->npcData as $data) {
            if(($data["position"]["world"] ?? "") === $worldName) $count++;
        }
        return $count;
    }

    public function getDefaultNPCData(float $x, float $y, float $z, string $worldName): array {
        return [
            "customId" => "",
            "title" => "NPC",
            "subtitle" => "",
            "position" => ["x" => $x, "y" => $y, "z" => $z, "world" => $worldName],
            "yaw" => 0.0,
            "pitch" => 0.0,
            "headYaw" => 0.0,
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
            "skin" => "",
            "savedSkin" => null,
            "skinModel" => Constants::MODEL_STEVE,
            "race" => Constants::DEFAULT_RACE,
            "pose" => Constants::DEFAULT_POSE,
            "commandEnabled" => false,
            "immobile" => false,
            "autoRespawn" => false,
            "canBeHit" => true,
            "stored" => false,
            "commands" => [],
            "drops" => [],
            "creator" => "",
            "nametagMode" => Constants::NAMETAG_ALWAYS,
            "lookAtPlayers" => false,
            "animation" => Constants::ANIM_NONE,
            "animationHeight" => 10.0,
            "animationSpeed" => 0.15,
            "animationPause" => 40,
            "dialogueEnabled" => false,
            "dialogue" => [],
            "dialogueDelay" => 20,
            "interactSound" => "",
            "usedOnce" => [],
            "waypoints" => [],
            "visibility" => [
                "mode" => "all",
                "permission" => ""
            ],
            "shop" => [
                "enabled" => false,
                "title" => "Boutique",
                "trades" => []
            ],
            "dialogueTree" => [
                "enabled" => false,
                "start" => "start",
                "nodes" => [
                    "start" => [
                        "lines" => [],
                        "choices" => []
                    ]
                ]
            ],
            "armor" => [
                "helmet" => "",
                "chestplate" => "",
                "leggings" => "",
                "boots" => "",
                "hand" => "",
                "offhand" => ""
            ]
        ];
    }

    public function getDefaultNPCDataWithRotation(float $x, float $y, float $z, string $worldName, float $yaw, float $pitch): array {
        $data = $this->getDefaultNPCData($x, $y, $z, $worldName);
        $data["yaw"] = $yaw;
        $data["pitch"] = $pitch;
        $data["headYaw"] = $yaw;
        return $data;
    }

    public function changeSkinFromPlayer(string $uuid, Player $source): bool {
        if(!isset($this->npcData[$uuid])) return false;

        $skin = $source->getSkin();

        $this->npcData[$uuid]["savedSkin"] = $this->skinManager->encodeSkin($skin);
        $this->npcData[$uuid]["skin"] = "player:" . $source->getName();

        if(str_contains(strtolower($skin->getGeometryName()), "slim")) {
            $this->npcData[$uuid]["skinModel"] = Constants::MODEL_ALEX;
        }

        $this->markDirty($uuid);

        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
        return true;
    }

    public function changeSkin(string $uuid, string $skinPath): string {
        if(!isset($this->npcData[$uuid])) return SkinManager::RESULT_NOT_FOUND;

        $skinPath = trim($skinPath);

        if(str_starts_with($skinPath, "player:")) {
            $target = $this->plugin->getServer()->getPlayerByPrefix(substr($skinPath, 7));
            if($target === null) return SkinManager::RESULT_NOT_FOUND;

            $this->npcData[$uuid]["skin"] = $skinPath;
            $this->npcData[$uuid]["savedSkin"] = $this->skinManager->encodeSkin($target->getSkin());

            $this->updateNPC($uuid);
            $this->saveNPC($uuid);
            return SkinManager::RESULT_OK;
        }

        $file = $this->skinManager->resolveFile($skinPath);
        if($file === null) return SkinManager::RESULT_NOT_FOUND;

        $texture = $this->skinManager->readTexture($file);
        if($texture === null) return SkinManager::RESULT_INVALID;

        $this->npcData[$uuid]["skin"] = basename($file);
        $this->npcData[$uuid]["savedSkin"] = null;

        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
        return SkinManager::RESULT_OK;
    }

    public function changeSkinModel(string $uuid, string $model): bool {
        if(!isset($this->npcData[$uuid])) return false;

        $this->npcData[$uuid]["skinModel"] = $this->modelManager->normalizeModel($model);
        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
        return true;
    }

    public function resetSkin(string $uuid): void {
        if(!isset($this->npcData[$uuid])) return;

        $this->npcData[$uuid]["skin"] = "";
        $this->npcData[$uuid]["savedSkin"] = null;
        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
    }

    public function changeRace(string $uuid, string $raceId): bool {
        if(!isset($this->npcData[$uuid])) return false;
        if($raceId !== Constants::DEFAULT_RACE && !$this->raceManager->raceExists($raceId)) return false;

        $this->npcData[$uuid]["race"] = $raceId;
        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
        return true;
    }

    public function changePose(string $uuid, string $poseId): bool {
        if(!isset($this->npcData[$uuid])) return false;
        if(!$this->modelManager->poseExists($poseId)) return false;

        $this->npcData[$uuid]["pose"] = $this->modelManager->normalizePose($poseId);
        $this->updateNPC($uuid);
        $this->saveNPC($uuid);
        return true;
    }
}
