<?php

namespace CustomNPC\utils;

class Constants {

    public const WAND_TAG = "customnpc_wand";
    public const ITEM_TAG = "customnpc_npc";

    public const NPC_WAND_NAME = "§r§6NPC Wand";
    public const NPC_ITEM_PREFIX = "§r§2NPC Item: ";

    public const MIN_HEALTH = 1.0;
    public const MAX_HEALTH = 200000.0;

    public const MIN_SPEED = 1;
    public const MAX_SPEED = 10;

    public const MIN_ATTACK_SPEED = 1;
    public const MAX_ATTACK_SPEED = 3;

    public const MIN_ATTACK_DAMAGE = 1;
    public const MAX_ATTACK_DAMAGE = 999;

    public const MIN_SIZE = 0.1;
    public const MAX_SIZE = 10.0;

    public const MIN_REGEN = 1;
    public const MAX_REGEN = 100;

    public const DEFAULT_RACE = "humain";

    public const RACES = [
        "humain" => "Humain",
        "zombie" => "Zombie",
        "squelette" => "Squelette",
        "husk" => "Husk",
        "piglin" => "Piglin",
        "enderman" => "Enderman"
    ];

    public const DEFAULT_POSE = "none";

    public const POSES = [
        "none" => "Aucune",
        "zombie" => "Bras tendus",
        "assis" => "Assis",
        "couche" => "Couche",
        "bras_croises" => "Bras croises",
        "salut" => "Salut"
    ];

    public const NAMETAG_ALWAYS = "always";
    public const NAMETAG_HOVER = "hover";
    public const NAMETAG_HIDDEN = "hidden";

    public const NAMETAG_MODES = [
        self::NAMETAG_ALWAYS => "Toujours visible",
        self::NAMETAG_HOVER => "Visible au survol",
        self::NAMETAG_HIDDEN => "Cache"
    ];

    public const ANIM_NONE = "none";
    public const ANIM_WAYPOINTS = "waypoints";

    public const ANIMATIONS = [
        "none" => "Aucune",
        "waypoints" => "Patrouille (points)",
        "climb" => "Monte et descend",
        "jump" => "Saute sur place",
        "spin" => "Tourne sur lui-meme",
        "float" => "Flotte doucement",
        "patrol" => "Va-et-vient"
    ];

    public const ARMOR_SLOTS = ["helmet", "chestplate", "leggings", "boots", "hand", "offhand"];
}
