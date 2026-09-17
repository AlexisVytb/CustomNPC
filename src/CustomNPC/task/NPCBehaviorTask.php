<?php

namespace CustomNPC\task;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\world\sound\BowShootSound;
use CustomNPC\Main;
use CustomNPC\entity\NPCEntity;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class NPCBehaviorTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $plugin = Main::getInstance();
        $config = $plugin->getConfig();

        $aggroRadius = (float)$config->getNested("behavior.aggro-radius", 15.0);
        $deaggroDistance = (float)$config->getNested("behavior.deaggro-distance", 20.0);
        $attackRange = (float)$config->getNested("behavior.attack-range", 2.5);
        $followDistance = (float)$config->getNested("behavior.follow-distance", 1.5);
        $lookRadius = (float)$config->getNested("behavior.look-radius", 8.0);

        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if($data["stored"] ?? false) continue;

            $aggressive = (bool)($data["aggressive"] ?? false);
            $lookAt = (bool)($data["lookAtPlayers"] ?? false);
            if(!$aggressive && !$lookAt) continue;

            $entity = $this->npcManager->getEntity($uuid);
            if($entity === null || empty($entity->getViewers())) continue;

            if($aggressive && !($data["immobile"] ?? false)) {
                $target = $this->findTarget($entity, $uuid, $aggroRadius, $deaggroDistance);

                if($target !== null) {
                    $this->handleCombat($entity, $target, $uuid, $data, $attackRange, $followDistance, $aggroRadius);
                    continue;
                }
            }

            if($lookAt) {
                $nearest = $this->findNearestViewer($entity, $lookRadius);
                if($nearest !== null) {
                    $entity->lookAt($nearest->getEyePos());
                }
            }
        }
    }

    private function isValidTarget(Player $player): bool {
        $config = Main::getInstance()->getConfig();

        if(!$player->isAlive() || !$player->isConnected()) return false;
        if($player->hasPermission("customnpc.ignored")) return false;
        if((bool)$config->getNested("behavior.ignore-spectator", true) && $player->isSpectator()) return false;
        if((bool)$config->getNested("behavior.ignore-creative", true) && $player->isCreative()) return false;

        return true;
    }

    private function findNearestViewer(NPCEntity $entity, float $radius): ?Player {
        $best = null;
        $bestDistance = $radius;

        foreach($entity->getViewers() as $player) {
            if(!$this->isValidTarget($player)) continue;

            $distance = $entity->getPosition()->distance($player->getPosition());
            if($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $player;
            }
        }

        return $best;
    }

    private function findTarget(NPCEntity $entity, string $uuid, float $aggroRadius, float $deaggroDistance): ?Player {
        $targetName = $this->npcManager->getTarget($uuid);

        if($targetName !== null) {
            $target = Main::getInstance()->getServer()->getPlayerExact($targetName);

            if($target !== null
                && $this->isValidTarget($target)
                && $target->getWorld() === $entity->getWorld()
                && $entity->getPosition()->distance($target->getPosition()) <= $deaggroDistance) {
                return $target;
            }

            $this->npcManager->setTarget($uuid, null);
        }

        return $this->findNearestViewer($entity, $aggroRadius);
    }

    private function handleCombat(NPCEntity $entity, Player $target, string $uuid, array $data, float $attackRange, float $followDistance, float $aggroRadius): void {
        $distance = $entity->getPosition()->distance($target->getPosition());
        $entity->lookAt($target->getEyePos());

        $arrowAttack = (bool)($data["arrowAttack"] ?? false);

        if($arrowAttack) {
            if($distance <= $aggroRadius && $this->npcManager->canAttack($uuid)) {
                $this->shootArrow($entity, $target, $data);
            }
            if($distance > $aggroRadius * 0.6) {
                $this->moveTowards($entity, $target, $data);
            }
            return;
        }

        if($distance > $followDistance) {
            $this->moveTowards($entity, $target, $data);
        }

        if($distance <= $attackRange && $this->npcManager->canAttack($uuid)) {
            $damage = (float)($data["attackDamage"] ?? 1);
            $event = new EntityDamageByEntityEvent($entity, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
            $target->attack($event);
        }
    }

    private function shootArrow(NPCEntity $entity, Player $target, array $data): void {
        $source = $entity->getPosition()->add(0, $entity->getEyeHeight(), 0);
        $destination = $target->getPosition()->add(0, $target->getEyeHeight(), 0);

        $direction = $destination->subtractVector($source);
        if($direction->lengthSquared() < 0.0001) return;
        $direction = $direction->normalize();

        $speed = max(0.5, (float)($data["arrowSpeed"] ?? 1)) * 0.9;

        $location = Location::fromObject(
            $source,
            $entity->getWorld(),
            (atan2($direction->z, $direction->x) * 180 / M_PI) - 90,
            -atan2($direction->y, sqrt($direction->x ** 2 + $direction->z ** 2)) * 180 / M_PI
        );

        $arrow = new Arrow($location, $entity, false);
        $arrow->setMotion($direction->multiply($speed));
        $arrow->setBaseDamage((float)($data["attackDamage"] ?? 2));
        $arrow->spawnToAll();

        $entity->getWorld()->addSound($source, new BowShootSound());
    }

    private function moveTowards(NPCEntity $entity, Player $target, array $data): void {
        $world = $entity->getWorld();
        $position = $entity->getPosition();

        $dirX = $target->getPosition()->x - $position->x;
        $dirZ = $target->getPosition()->z - $position->z;
        $length = sqrt($dirX * $dirX + $dirZ * $dirZ);
        if($length < 0.0001) return;

        $dirX /= $length;
        $dirZ /= $length;

        $speed = max(1, (int)($data["speed"] ?? 1)) * 0.12;

        $newX = $position->x + $dirX * $speed;
        $newZ = $position->z + $dirZ * $speed;
        $newY = $position->y;

        $front = $world->getBlockAt((int)floor($newX), (int)floor($newY), (int)floor($newZ));
        $above = $world->getBlockAt((int)floor($newX), (int)floor($newY) + 1, (int)floor($newZ));
        $below = $world->getBlockAt((int)floor($newX), (int)floor($newY) - 1, (int)floor($newZ));

        if($front->isSolid()) {
            if($above->isSolid()) {
                $entity->setMotion(new Vector3(0, 0, 0));
                return;
            }
            $newY += 1.0;
        } elseif(!$below->isSolid()) {
            $newY -= 0.35;
        }

        $yaw = atan2($dirZ, $dirX) * 180 / M_PI - 90;
        $entity->moveTo(new Vector3($newX, $newY, $newZ), $yaw);
    }
}
