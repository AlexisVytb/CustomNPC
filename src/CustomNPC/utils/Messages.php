<?php

namespace CustomNPC\utils;

use pocketmine\utils\Config;
use CustomNPC\Main;

class Messages {

    private static ?Config $config = null;
    private static array $fallback = [];

    public const DEFAULTS = [
        "prefix" => "§8[§6NPC§8] §r",
        "general" => [
            "no-permission" => "§cPermission manquante : §e{permission}",
            "player-only" => "Cette commande doit etre executee en jeu.",
            "npc-not-found" => "§cNPC introuvable.",
            "npc-not-matching" => "§cAucun NPC ne correspond a §e{id}",
            "no-selection" => "§cAucun NPC selectionne. Vise un NPC ou utilise §e/npc select§c.",
            "none-nearby" => "§cAucun NPC a proximite.",
            "world-not-loaded" => "§cMonde non charge.",
            "inventory-full" => "§cInventaire plein, libere une case puis refais la commande.",
            "limit-reached" => "§cLimite de NPCs atteinte dans ce monde ({max}).",
            "invalid-item" => "§cItem invalide ignore : §7{item}"
        ],
        "npc" => [
            "created" => "§aNPC cree et selectionne. §7UUID: §e{uuid}",
            "deleted" => "§aNPC supprime : §e{title}",
            "selected" => "§aNPC selectionne : §e{title}",
            "moved" => "§aNPC deplace.",
            "moved-here" => "§aNPC deplace sur ta position.",
            "teleported" => "§aTeleporte au NPC.",
            "respawned" => "§aNPC respawne.",
            "stored" => "§aNPC range dans un item.",
            "placed" => "§aNPC place.",
            "duplicated" => "§aNPC duplique et selectionne. §7UUID: §e{uuid}",
            "refreshed" => "§a{count} NPCs rafraichis.",
            "id-set" => "§aIdentifiant defini : §e{id}",
            "id-taken" => "§cCet identifiant est deja utilise.",
            "id-invalid" => "§cIdentifiant invalide (lettres, chiffres, tirets uniquement)."
        ],
        "admin" => [
            "enabled" => "§aMode admin NPC active.",
            "disabled" => "§cMode admin NPC desactive.",
            "wand-hint" => "§7Clic gauche : editer §8| §7Clic droit : creer",
            "wand-received" => "§aBaguette NPC recue.",
            "wand-already" => "§7Tu as deja la baguette NPC."
        ],
        "shop" => [
            "closed" => "§cCette boutique est fermee.",
            "empty" => "§cCette boutique n'a rien a proposer.",
            "out-of-stock" => "§cRupture de stock.",
            "cannot-afford" => "§cTu n'as pas de quoi payer.",
            "inventory-full" => "§cLibere de la place dans ton inventaire.",
            "purchased" => "§aEchange effectue : §e{item}",
            "permission-required" => "§cCet echange requiert : §e{permission}",
            "admin-closed" => "§cLa boutique de ce NPC est fermee. Ouvre-la dans §e/npc shop §7> §eParametres§c.",
            "admin-empty" => "§cAucun echange configure. Ajoute-en dans §e/npc shop§c."
        ],
        "dialogue" => [
            "format" => "§f<§e{npc}§f> §r{line}",
            "node-missing" => "§cDialogue interrompu."
        ],
        "waypoint" => [
            "added" => "§aPoint §e#{index}§a ajoute.",
            "removed" => "§aPoint §e#{index}§a supprime.",
            "cleared" => "§aTous les points ont ete supprimes.",
            "empty" => "§cAucun point enregistre.",
            "need-two" => "§cIl faut au moins deux points pour une patrouille."
        ],
        "command" => [
            "cooldown" => "§cEn cooldown : §e{seconds}s",
            "one-time-used" => "§cCommande deja utilisee.",
            "permission" => "§cPermission requise : §e{permission}"
        ],
        "skin" => [
            "applied" => "§aSkin applique : §e{skin}",
            "not-found" => "§cSkin introuvable : §e{skin}§c. Voir §e/npc skinlist",
            "invalid" => "§cFichier PNG illisible ou dimensions invalides : §e{skin}",
            "reset" => "§aSkin reinitialise.",
            "model-set" => "§aModele de bras : §e{model}"
        ]
    ];

    public static function init(Main $plugin): void {
        $plugin->saveResource("messages.yml");
        self::$config = new Config($plugin->getDataFolder() . "messages.yml", Config::YAML);
        self::$fallback = self::DEFAULTS;

        $resource = $plugin->getResource("messages.yml");
        if($resource !== null) {
            $raw = stream_get_contents($resource);
            fclose($resource);

            $parsed = function_exists('yaml_parse') ? @yaml_parse((string)$raw) : null;
            if(is_array($parsed)) {
                self::$fallback = array_replace_recursive(self::DEFAULTS, $parsed);
            }
        }
    }

    public static function reload(Main $plugin): void {
        self::init($plugin);
    }

    public static function get(string $key, array $replacements = [], bool $prefix = false): string {
        $value = self::lookup($key) ?? "";

        foreach($replacements as $search => $replace) {
            $value = str_replace("{" . $search . "}", (string)$replace, $value);
        }

        return ($prefix ? (self::lookup("prefix") ?? "") : "") . $value;
    }

    private static function lookup(string $key): ?string {
        if(self::$config !== null) {
            $value = self::$config->getNested($key);
            if(is_string($value) && $value !== "") return $value;
        }

        $value = self::dig(self::$fallback, $key);
        if(is_string($value) && $value !== "") return $value;

        $value = self::dig(self::DEFAULTS, $key);
        return is_string($value) ? $value : null;
    }

    private static function dig(array $source, string $key) {
        $node = $source;

        foreach(explode(".", $key) as $part) {
            if(!is_array($node) || !array_key_exists($part, $node)) return null;
            $node = $node[$part];
        }

        return $node;
    }
}
