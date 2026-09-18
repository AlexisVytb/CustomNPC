<?php

declare(strict_types=1);

namespace CustomNPC\libs\muqsit\invmenu\type;

use CustomNPC\libs\muqsit\invmenu\InvMenu;
use CustomNPC\libs\muqsit\invmenu\type\graphic\InvMenuGraphic;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;

interface InvMenuType{

	public function createGraphic(InvMenu $menu, Player $player) : ?InvMenuGraphic;

	public function createInventory() : Inventory;
}