<?php

namespace CustomNPC\task;

use pocketmine\scheduler\Task;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Projectile;

class NPCBehaviorTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $npcData = $this->npcManager->getAllNPCData();

        foreach($npcData as $uuid => $data) {
            if($data["immobile"] ?? false) continue;
            
            if(!($data["aggressive"] ?? false)) continue;
            if($data["immobile"] ?? false) continue;

            $world = \CustomNPC\Main::getInstance()->getServer()->getWorldManager()->getWorldByName($data["position"]["world"]);
            if($world === null) continue;

            $npc = $world->getEntity($data["runtimeId"] ?? 0);
            if($npc === null || !($npc instanceof Living)) continue;

            $target = $this->findTarget($npc, $uuid, $data, $world);

            if($target !== null) {
                $this->handleMovementAndAttack($npc, $target, $uuid, $data, $world);
            }
        }
    }

    private function findTarget(Living $npc, string $uuid, array $data, $world): ?\pocketmine\player\Player {
        $target = null;
        $targetName = $this->npcManager->getTarget($uuid);
        if($targetName !== null) {
            $target = \CustomNPC\Main::getInstance()->getServer()->getPlayerExact($targetName);
            if($target === null || $target->getWorld() !== $world || $npc->getPosition()->distance($target->getPosition()) > Constants::NPC_DEAGGRO_DISTANCE) {
                $this->npcManager->setTarget($uuid, null);
                $target = null;
            }
        }

        if($target === null) {
            $minDistance = Constants::NPC_AGGRO_RADIUS;
            foreach($world->getPlayers() as $player) {
                $distance = $npc->getPosition()->distance($player->getPosition());
                if($distance < $minDistance) {
                    $minDistance = $distance;
                    $target = $player;
                }
            }
        }

        return $target;
    }

    private function handleMovementAndAttack(Living $npc, $target, string $uuid, array $data, $world): void {
        $distance = $npc->getPosition()->distance($target->getPosition());

        if($distance > Constants::NPC_FOLLOW_DISTANCE) {
            $this->moveTowardsTarget($npc, $target, $data, $world);
        }

        if($distance < Constants::NPC_ATTACK_RANGE && !($data["arrowAttack"] ?? false) && $this->npcManager->canAttack($uuid)) {
            $event = new EntityDamageByEntityEvent($npc, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $data["attackDamage"] ?? 1);
            $target->attack($event);

            if(!$event->isCancelled() && isset($data["effectOnHit"]) && $data["effectOnHit"] !== "") {
                $this->applyEffectToPlayer($target, $data["effectOnHit"]);
            }
        }

        if(($data["arrowAttack"] ?? false) && $this->npcManager->canAttack($uuid)) {
             if($distance <= Constants::NPC_AGGRO_RADIUS) {
                 $this->shootArrow($npc, $target, $data);
             }
        }
    }

    private function shootArrow(Living $npc, $target, array $data): void {
        $sourcePos = $npc->getPosition()->add(0, $npc->getEyeHeight(), 0);
        $targetPos = $target->getPosition()->add(0, $target->getEyeHeight(), 0);
        
        $direction = $targetPos->subtractVector($sourcePos)->normalize();
        $speed = ($data["arrowSpeed"] ?? 1.0) * 0.8;
        
        $location = Location::fromObject($sourcePos, $npc->getWorld(), 
            (atan2($direction->z, $direction->x) * 180 / M_PI) - 90,
            -atan2($direction->y, sqrt($direction->x ** 2 + $direction->z ** 2)) * 180 / M_PI
        );
        
        $arrow = new Arrow($location, $npc, ($data["critical"] ?? false));
        $arrow->setMotion($direction->multiply($speed));

        $arrow->setBaseDamage($data["attackDamage"] ?? 2.0);
        
        $arrow->spawnToAll();
        $npc->getWorld()->addSound($sourcePos, new \pocketmine\world\sound\BowShootSound());
    }

    private function moveTowardsTarget(Living $npc, $target, array $data, $world): void {
        $speed = ($data["speed"] ?? 1) * 0.15;
        
        $dirX = $target->getPosition()->x - $npc->getPosition()->x;
        $dirZ = $target->getPosition()->z - $npc->getPosition()->z;
        $length = sqrt($dirX * $dirX + $dirZ * $dirZ);
        
        if($length <= 0) return;
        
        $dirX /= $length;
        $dirZ /= $length;
        
        $yaw = atan2($dirZ, $dirX) * 180 / M_PI - 90;
        
        $newX = $npc->getPosition()->x + ($dirX * $speed);
        $newY = $npc->getPosition()->y;
        $newZ = $npc->getPosition()->z + ($dirZ * $speed);

        $blockInFront = $world->getBlockAt((int)$newX, (int)$newY, (int)$newZ);
        $blockAbove = $world->getBlockAt((int)$newX, (int)($newY + 1), (int)$newZ);
        $blockBelow = $world->getBlockAt((int)$newX, (int)($newY - 1), (int)$newZ);
        
        $canMove = true;
        $shouldJump = false;
        
        if($blockInFront->isSolid()) {
            if(!$blockAbove->isSolid()) {
                $shouldJump = true;
            } else {
                $canMove = false;

                $sideX = $npc->getPosition()->x + ($dirZ * $speed);
                $sideZ = $npc->getPosition()->z - ($dirX * $speed);
                $blockSide = $world->getBlockAt((int)$sideX, (int)$newY, (int)$sideZ);
                if(!$blockSide->isSolid()) {
                    $newX = $sideX;
                    $newZ = $sideZ;
                    $canMove = true;
                }
            }
        }
        
        if($shouldJump) {
            $npc->setMotion(new Vector3($dirX * $speed, 0.42, $dirZ * $speed));
        } elseif($canMove) {
            if(!$blockBelow->isSolid()) {
                $newY -= 0.5;
            }
            $npc->teleport(new Location($newX, $newY, $newZ, $world, $yaw, 0));
        }
    }

    private function applyEffectToPlayer($player, string $effectId): void {
        if(!($player instanceof \pocketmine\player\Player)) return;
        
        $parts = explode(":", $effectId);
        $effectName = $parts[0];
        $durationSeconds = isset($parts[1]) ? (int)$parts[1] : 5;
        $amplifier = isset($parts[2]) ? (int)$parts[2] : 0;
        
        $durationTicks = $durationSeconds * 20;

        $effect = \pocketmine\entity\effect\StringToEffectParser::getInstance()->parse($effectName);
        if($effect !== null) {
            $player->getEffects()->add(new \pocketmine\entity\effect\EffectInstance($effect, $durationTicks, $amplifier));
        }
    }
}