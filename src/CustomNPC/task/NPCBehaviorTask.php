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

class NPCBehaviorTask extends Task {

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $npcData = $this->npcManager->getAllNPCData();

        foreach($npcData as $uuid => $data) {
            // Ne pas faire bouger les NPCs immobiles
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
        
        // Vérifier si on a déjà une cible
        $targetName = $this->npcManager->getTarget($uuid);
        if($targetName !== null) {
            $target = \CustomNPC\Main::getInstance()->getServer()->getPlayerExact($targetName);
            if($target === null || $target->getWorld() !== $world || $npc->getPosition()->distance($target->getPosition()) > Constants::NPC_DEAGGRO_DISTANCE) {
                $this->npcManager->setTarget($uuid, null);
                $target = null;
            }
        }

        // Chercher une nouvelle cible
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
        
        // Déplacement
        if($distance > Constants::NPC_FOLLOW_DISTANCE) {
            $this->moveTowardsTarget($npc, $target, $data, $world);
        }

        // Attaque
        if($distance < Constants::NPC_ATTACK_RANGE && $this->npcManager->canAttack($uuid)) {
            $event = new EntityDamageByEntityEvent($npc, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $data["attackDamage"] ?? 1);
            $target->attack($event);
        }
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
        
        // Vérification des collisions
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
                // Essayer de contourner
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
}