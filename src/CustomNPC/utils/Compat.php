<?php

namespace CustomNPC\utils;

use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\Server;

class Compat {

    private static ?CommandSender $console = null;

    public static function console(): CommandSender {
        if(self::$console !== null) return self::$console;

        $server = Server::getInstance();

        if(method_exists($server, "getConsoleSender")) {
            $sender = $server->getConsoleSender();
            if($sender instanceof CommandSender) {
                return self::$console = $sender;
            }
        }

        return self::$console = new ConsoleCommandSender($server, $server->getLanguage());
    }

    public static function dispatch(CommandSender $sender, string $command): bool {
        $command = ltrim(trim($command), "/");
        if($command === "") return false;

        try {
            return Server::getInstance()->dispatchCommand($sender, $command);
        } catch(\Throwable $e) {
            return false;
        }
    }

    public static function dispatchConsole(string $command): bool {
        return self::dispatch(self::console(), $command);
    }

    public static function playSound(Player $player, string $sound, Vector3 $position, float $volume = 1.0, float $pitch = 1.0): void {
        $sound = trim($sound);
        if($sound === "") return;
        if(!$player->isConnected()) return;
        if(!class_exists(PlaySoundPacket::class)) return;

        try {
            $method = new \ReflectionMethod(PlaySoundPacket::class, "create");
            $parameters = $method->getParameters();

            $args = [$sound, $position->x, $position->y, $position->z, $volume, $pitch];

            for($i = 6; $i < count($parameters); $i++) {
                $args[] = self::defaultFor($parameters[$i]);
            }

            $packet = $method->invokeArgs(null, array_slice($args, 0, max(6, count($parameters))));
            $player->getNetworkSession()->sendDataPacket($packet);
        } catch(\Throwable $e) {
        }
    }

    private static function defaultFor(\ReflectionParameter $parameter) {
        if($parameter->isDefaultValueAvailable()) {
            try {
                return $parameter->getDefaultValue();
            } catch(\Throwable $e) {
            }
        }

        $type = $parameter->getType();

        if($type instanceof \ReflectionNamedType) {
            if($type->allowsNull()) return null;

            return match($type->getName()) {
                "int" => 0,
                "float" => 0.0,
                "bool" => false,
                "string" => "",
                "array" => [],
                default => null
            };
        }

        return null;
    }
}
