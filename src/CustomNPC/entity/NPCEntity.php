<?php

namespace CustomNPC\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\player\Player;

class NPCEntity extends Human {

    private string $npcUuid = "";
    private float $npcHeight = 1.8;
    private float $npcWidth = 0.6;
    private float $npcEyeHeight = 1.62;
    private float $headYaw = 0.0;
    private bool $npcImmobile = false;
    private bool $npcPushable = false;

    public function __construct(Location $location, Skin $skin, ?CompoundTag $nbt = null, float $height = 1.8, float $eyeHeight = 1.62, float $width = 0.6) {
        $this->npcHeight = $height;
        $this->npcEyeHeight = $eyeHeight;
        $this->npcWidth = $width;
        $this->headYaw = $location->yaw;
        parent::__construct($location, $skin, $nbt);
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo($this->npcHeight, $this->npcWidth, $this->npcEyeHeight);
    }

    public function getNpcUuid(): string {
        return $this->npcUuid;
    }

    public function setNpcUuid(string $uuid): void {
        $this->npcUuid = $uuid;
    }

    public function setNpcImmobile(bool $immobile): void {
        $this->npcImmobile = $immobile;
        $this->setHasGravity(!$immobile);
        $this->setNoClientPredictions($immobile);
    }

    public function isNpcImmobile(): bool {
        return $this->npcImmobile;
    }

    public function canBeCollidedWith(): bool {
        return $this->npcPushable;
    }

    public function canCollideWith(Entity $entity): bool {
        return false;
    }

    public function getHeadYaw(): float {
        return $this->headYaw;
    }

    public function setRotation(float $yaw, float $pitch): void {
        $this->headYaw = $yaw;
        parent::setRotation($yaw, $pitch);
    }

    public function setHeadYaw(float $headYaw): void {
        $this->headYaw = $headYaw;
        $this->broadcastMovement();
    }

    public function lookAt(Vector3 $target): void {
        $dx = $target->x - $this->location->x;
        $dy = $target->y - ($this->location->y + $this->getEyeHeight());
        $dz = $target->z - $this->location->z;

        $horizontal = sqrt($dx * $dx + $dz * $dz);
        if($horizontal < 0.0001 && abs($dy) < 0.0001) return;

        $yaw = (atan2($dz, $dx) * 180 / M_PI) - 90;
        $pitch = -atan2($dy, $horizontal) * 180 / M_PI;

        $this->location->pitch = max(-90.0, min(90.0, $pitch));
        $this->headYaw = $yaw;
        $this->broadcastMovement();
    }

    public function moveTo(Vector3 $position, ?float $yaw = null, ?float $pitch = null): void {
        $this->location->x = $position->x;
        $this->location->y = $position->y;
        $this->location->z = $position->z;

        if($yaw !== null) {
            $this->location->yaw = $yaw;
            $this->headYaw = $yaw;
        }
        if($pitch !== null) {
            $this->location->pitch = $pitch;
        }

        $this->recalculateBoundingBox();
        $this->broadcastMovement();
    }

    public function applyNametagMode(string $mode): void {
        switch($mode) {
            case "hidden":
                $this->setNameTagVisible(false);
                $this->setNameTagAlwaysVisible(false);
                break;
            case "hover":
                $this->setNameTagVisible(true);
                $this->setNameTagAlwaysVisible(false);
                break;
            default:
                $this->setNameTagVisible(true);
                $this->setNameTagAlwaysVisible(true);
                break;
        }
    }

    protected function broadcastMovement(bool $teleport = false): void {
        NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [MoveActorAbsolutePacket::create(
            $this->id,
            $this->getOffsetPosition($this->location),
            $this->location->pitch,
            $this->location->yaw,
            $this->headYaw,
            ($this->onGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)
        )]);
    }

    public function spawnTo(Player $player): void {
        parent::spawnTo($player);

        $player->getNetworkSession()->sendDataPacket(MoveActorAbsolutePacket::create(
            $this->id,
            $this->getOffsetPosition($this->location),
            $this->location->pitch,
            $this->location->yaw,
            $this->headYaw,
            ($this->onGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)
        ));
    }

    public function knockBack(float $x, float $z, float $force = 0.4, ?float $verticalLimit = null): void {
        if($this->npcImmobile) return;
        parent::knockBack($x, $z, $force, $verticalLimit);
    }

    public function canSaveWithChunk(): bool {
        return false;
    }
}
