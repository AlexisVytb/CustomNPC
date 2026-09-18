<?php

namespace CustomNPC\task;

use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\world\World;
use CustomNPC\manager\NPCManager;
use CustomNPC\utils\Constants;

class AnimationTask extends Task {

    private NPCManager $npcManager;
    private array $states = [];

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function onRun(): void {
        $active = [];

        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $animation = (string)($data["animation"] ?? Constants::ANIM_NONE);
            if($animation === Constants::ANIM_NONE) continue;
            if($data["stored"] ?? false) continue;

            $entity = $this->npcManager->getEntity($uuid);
            if($entity === null) continue;
            if(empty($entity->getViewers())) continue;

            $active[$uuid] = true;
            $this->tick($uuid, $entity, $data, $animation);
        }

        foreach(array_keys($this->states) as $uuid) {
            if(!isset($active[$uuid])) {
                unset($this->states[$uuid]);
            }
        }
    }

    private function tick(string $uuid, $entity, array $data, string $animation): void {
        $base = new Vector3(
            (float)$data["position"]["x"],
            (float)$data["position"]["y"],
            (float)$data["position"]["z"]
        );

        if(!isset($this->states[$uuid])) {
            $this->states[$uuid] = [
                "phase" => "forward",
                "progress" => 0.0,
                "wait" => 0,
                "tick" => 0
            ];
        }

        $state = &$this->states[$uuid];
        $state["tick"]++;

        $height = max(0.5, (float)($data["animationHeight"] ?? 10.0));
        $speed = max(0.02, min(1.0, (float)($data["animationSpeed"] ?? 0.15)));
        $pause = max(0, (int)($data["animationPause"] ?? 40));

        switch($animation) {
            case "waypoints":
                $waypoints = $this->npcManager->getWaypoints($uuid);
                if(count($waypoints) < 2) break;

                if(!isset($state["index"])) {
                    $state["index"] = 0;
                }

                if($state["wait"] > 0) {
                    $state["wait"]--;
                    break;
                }

                $target = $waypoints[$state["index"]] ?? $waypoints[0];
                $destination = new Vector3((float)$target["x"], (float)$target["y"], (float)$target["z"]);
                $current = $entity->getPosition()->asVector3();

                $deltaX = $destination->x - $current->x;
                $deltaZ = $destination->z - $current->z;
                $deltaY = $destination->y - $current->y;
                $horizontal = sqrt($deltaX * $deltaX + $deltaZ * $deltaZ);

                $reach = max(0.06, $speed);

                if($horizontal <= $reach && abs($deltaY) <= max(0.25, $speed)) {
                    $next = $waypoints[($state["index"] + 1) % count($waypoints)];
                    $facing = atan2((float)$next["z"] - $destination->z, (float)$next["x"] - $destination->x) * 180 / M_PI - 90;

                    $entity->moveTo($destination, $facing);

                    $wait = (int)($target["wait"] ?? 0);
                    $state["wait"] = $wait > 0 ? $wait : $pause;
                    $state["index"] = ($state["index"] + 1) % count($waypoints);
                    break;
                }

                if($horizontal < 0.0001) {
                    $step = max(-$speed, min($speed, $deltaY));
                    $entity->moveTo(new Vector3($current->x, $current->y + $step, $current->z));
                    break;
                }

                $ratio = min(1.0, $speed / $horizontal);

                $newX = $current->x + $deltaX * $ratio;
                $newZ = $current->z + $deltaZ * $ratio;
                $newY = $current->y + $deltaY * $ratio;

                $ground = $this->groundHeight($entity->getWorld(), $newX, $newZ, $newY);

                if($ground !== null && abs($ground - $newY) <= 1.5) {
                    $newY = $ground;
                }

                $yaw = atan2($deltaZ, $deltaX) * 180 / M_PI - 90;
                $entity->moveTo(new Vector3($newX, $newY, $newZ), $yaw);
                break;

            case "climb":
                if($state["wait"] > 0) {
                    $state["wait"]--;
                    break;
                }

                if($state["phase"] === "forward") {
                    $state["progress"] += $speed;
                    if($state["progress"] >= $height) {
                        $state["progress"] = $height;
                        $state["phase"] = "backward";
                        $state["wait"] = $pause;
                    }
                } else {
                    $state["progress"] -= $speed;
                    if($state["progress"] <= 0.0) {
                        $state["progress"] = 0.0;
                        $state["phase"] = "forward";
                        $state["wait"] = $pause;
                    }
                }

                $entity->moveTo($base->add(0, $state["progress"], 0));
                break;

            case "float":
                $offset = (sin($state["tick"] * $speed) + 1) * 0.5 * min($height, 2.0);
                $entity->moveTo($base->add(0, $offset, 0));
                break;

            case "jump":
                if($state["wait"] > 0) {
                    $state["wait"]--;
                    $entity->moveTo($base);
                    break;
                }

                $state["progress"] += $speed * 2;
                $offset = sin($state["progress"]) * 0.8;

                if($offset < 0) {
                    $state["progress"] = 0.0;
                    $offset = 0.0;
                    $state["wait"] = $pause;
                }

                $entity->moveTo($base->add(0, $offset, 0));
                break;

            case "spin":
                $yaw = fmod((float)($data["yaw"] ?? 0.0) + $state["tick"] * ($speed * 40), 360.0);
                $entity->moveTo($base, $yaw, (float)($data["pitch"] ?? 0.0));
                break;

            case "patrol":
                if($state["wait"] > 0) {
                    $state["wait"]--;
                    break;
                }

                $yaw = (float)($data["yaw"] ?? 0.0);
                $radians = ($yaw + 90) * M_PI / 180;
                $dirX = cos($radians);
                $dirZ = sin($radians);

                if($state["phase"] === "forward") {
                    $state["progress"] += $speed;
                    if($state["progress"] >= $height) {
                        $state["progress"] = $height;
                        $state["phase"] = "backward";
                        $state["wait"] = $pause;
                    }
                } else {
                    $state["progress"] -= $speed;
                    if($state["progress"] <= 0.0) {
                        $state["progress"] = 0.0;
                        $state["phase"] = "forward";
                        $state["wait"] = $pause;
                    }
                }

                $facing = $state["phase"] === "forward" ? $yaw : fmod($yaw + 180, 360);
                $entity->moveTo($base->add($dirX * $state["progress"], 0, $dirZ * $state["progress"]), $facing);
                break;
        }

        unset($state);
    }

    private function groundHeight(World $world, float $x, float $z, float $y): ?float {
        $blockX = (int)floor($x);
        $blockZ = (int)floor($z);

        if(!$world->isChunkLoaded($blockX >> 4, $blockZ >> 4)) return null;

        $start = (int)floor($y) + 1;
        $end = (int)floor($y) - 2;

        for($blockY = $start; $blockY >= $end; $blockY--) {
            if($blockY < $world->getMinY() || $blockY > $world->getMaxY()) continue;

            $block = $world->getBlockAt($blockX, $blockY, $blockZ);

            $top = null;
            foreach($block->getCollisionBoxes() as $box) {
                if($top === null || $box->maxY > $top) {
                    $top = $box->maxY;
                }
            }

            if($top !== null) {
                return (float)$top;
            }
        }

        return null;
    }

    public function reset(string $uuid): void {
        unset($this->states[$uuid]);
    }
}
