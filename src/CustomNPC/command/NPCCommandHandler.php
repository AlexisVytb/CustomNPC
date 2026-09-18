<?php

namespace CustomNPC\command;

use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\Position;
use CustomNPC\gui\AnimationGUI;
use CustomNPC\gui\DialogueGUI;
use CustomNPC\gui\FakePlayerGUI;
use CustomNPC\gui\MainGUI;
use CustomNPC\gui\DialogueTreeGUI;
use CustomNPC\gui\NametagGUI;
use CustomNPC\gui\ShopEditGUI;
use CustomNPC\gui\VisibilityGUI;
use CustomNPC\gui\WaypointGUI;
use CustomNPC\gui\NPCListGUI;
use CustomNPC\inventory\ChestEditor;
use CustomNPC\Main;
use CustomNPC\manager\NPCManager;
use CustomNPC\manager\SkinManager;
use CustomNPC\utils\Constants;
use CustomNPC\utils\Messages;

class NPCCommandHandler {

    private const PERMISSIONS = [
        "admin" => "customnpc.admin",
        "wand" => "customnpc.wand",
        "select" => "customnpc.edit",
        "create" => "customnpc.create",
        "spawn" => "customnpc.create",
        "delete" => "customnpc.delete",
        "id" => "customnpc.edit",
        "edit" => "customnpc.edit",
        "list" => "customnpc.list",
        "gui" => "customnpc.list",
        "tp" => "customnpc.tp",
        "here" => "customnpc.move",
        "move" => "customnpc.move",
        "rotate" => "customnpc.move",
        "armor" => "customnpc.armor",
        "drops" => "customnpc.drops",
        "skin" => "customnpc.skin",
        "listskins" => "customnpc.skin",
        "skinlist" => "customnpc.skin",
        "skins" => "customnpc.skin",
        "skinmodel" => "customnpc.skin",
        "model" => "customnpc.skin",
        "race" => "customnpc.race",
        "listraces" => "customnpc.race",
        "pose" => "customnpc.pose",
        "listposes" => "customnpc.pose",
        "nametag" => "customnpc.edit",
        "anim" => "customnpc.anim",
        "dialogue" => "customnpc.dialogue",
        "tree" => "customnpc.dialogue",
        "shop" => "customnpc.shop",
        "waypoint" => "customnpc.waypoint",
        "visibility" => "customnpc.visibility",
        "logs" => "customnpc.logs",
        "info" => "customnpc.list",
        "copy" => "customnpc.edit",
        "paste" => "customnpc.create",
        "refresh" => "customnpc.edit",
        "reload" => "customnpc.reload",
        "export" => "customnpc.export",
        "import" => "customnpc.export",
        "uuid" => "customnpc.uuid",
        "fakeplayer" => "customnpc.fakeplayer",
        "debug" => "customnpc.debug"
    ];

    private NPCManager $npcManager;

    public function __construct(NPCManager $npcManager) {
        $this->npcManager = $npcManager;
    }

    public function handleCommand(Player $player, string $commandName, array $args): bool {
        if($commandName === "npcadmin") {
            return $this->admin($player);
        }

        if(empty($args)) {
            $this->help($player);
            return true;
        }

        $sub = strtolower($args[0]);
        $rest = array_slice($args, 1);

        $permission = self::PERMISSIONS[$sub] ?? "customnpc.use";
        if(!$player->hasPermission($permission) && !$this->npcManager->isAdmin($player->getName())) {
            $player->sendMessage(Messages::get("general.no-permission", ["permission" => $permission]));
            return true;
        }

        return match($sub) {
            "help" => $this->help($player),
            "admin" => $this->admin($player),
            "wand" => $this->wand($player),
            "select" => $this->select($player, $rest),
            "create" => $this->create($player),
            "spawn" => $this->spawn($player, $rest),
            "delete", "remove" => $this->delete($player, $rest),
            "id" => $this->setId($player, $rest),
            "edit" => $this->edit($player, $rest),
            "list" => $this->list($player),
            "gui" => $this->gui($player),
            "tp" => $this->teleport($player, $rest),
            "here" => $this->here($player, $rest),
            "move" => $this->move($player, $rest),
            "rotate" => $this->rotate($player, $rest),
            "armor" => $this->armor($player, $rest),
            "drops" => $this->drops($player, $rest),
            "skin" => $this->skin($player, $rest),
            "listskins", "skinlist", "skins" => $this->listSkins($player),
            "skinmodel", "model" => $this->skinModel($player, $rest),
            "race" => $this->race($player, $rest),
            "listraces" => $this->listRaces($player),
            "pose" => $this->pose($player, $rest),
            "listposes" => $this->listPoses($player),
            "nametag" => $this->nametag($player, $rest),
            "anim" => $this->animation($player, $rest),
            "dialogue" => $this->dialogue($player, $rest),
            "tree" => $this->dialogueTree($player, $rest),
            "shop" => $this->shop($player, $rest),
            "waypoint", "wp" => $this->waypoint($player, $rest),
            "visibility" => $this->visibility($player, $rest),
            "logs" => $this->logs($player, $rest),
            "info" => $this->info($player, $rest),
            "copy" => $this->copy($player, $rest),
            "paste" => $this->paste($player),
            "refresh" => $this->refresh($player),
            "reload" => $this->reload($player),
            "export" => $this->export($player),
            "import" => $this->import($player, $rest),
            "uuid" => $this->uuid($player),
            "fakeplayer" => $this->fakePlayer($player, $rest),
            "debug" => $this->debug($player),
            default => $this->help($player)
        };
    }

    private function resolveTarget(Player $player, array $args, int $index = 0): ?string {
        if(isset($args[$index]) && $args[$index] !== "") {
            $uuid = $this->npcManager->resolve($args[$index]);

            if($uuid === null) {
                $player->sendMessage(Messages::get("general.npc-not-matching", ["id" => $args[$index]]));
                return null;
            }

            return $uuid;
        }

        $uuid = $this->npcManager->getSelection($player);
        if($uuid !== null) return $uuid;

        $radius = (float)Main::getInstance()->getConfig()->getNested("settings.selection-radius", 8.0);
        $uuid = $this->npcManager->findLookedAt($player, $radius);

        if($uuid === null) {
            $player->sendMessage(Messages::get("general.no-selection"));
            return null;
        }

        $this->npcManager->select($player, $uuid);
        return $uuid;
    }

    private function help(Player $player): bool {
        $player->sendMessage("§e===== CustomNPC =====");
        $player->sendMessage("§7L'UUID est optionnel partout : le NPC selectionne ou vise est utilise.");
        $player->sendMessage("§b/npc select §7- selectionner le NPC vise");
        $player->sendMessage("§b/npc create §7- creer un NPC ici");
        $player->sendMessage("§b/npc edit §7- ouvrir le menu d'edition");
        $player->sendMessage("§b/npc gui §7- liste interactive des NPCs");
        $player->sendMessage("§b/npc id <id> §7- donner un identifiant court");
        $player->sendMessage("§b/npc armor §7| §b/npc drops §7- coffres d'equipement et de butin");
        $player->sendMessage("§b/npc nametag <always|hover|hidden> §7- visibilite du nom");
        $player->sendMessage("§b/npc anim <type> [distance] [vitesse] [pause] §7- animations");
        $player->sendMessage("§b/npc dialogue §7- lignes simples");
        $player->sendMessage("§b/npc tree §7- arbre de dialogue a choix");
        $player->sendMessage("§b/npc shop §7- boutique et echanges");
        $player->sendMessage("§b/npc waypoint <add|list|remove|clear|start|stop> §7- patrouille");
        $player->sendMessage("§b/npc visibility §7- qui voit le NPC");
        $player->sendMessage("§b/npc logs [nombre] §7- historique des modifications");
        $player->sendMessage("§b/npc skin <fichier.png|me|player:pseudo|reset> §7- skin");
        $player->sendMessage("§b/npc skinlist §7- lister les PNG du dossier skins");
        $player->sendMessage("§b/npc skinmodel <steve|alex> §7- largeur des bras");
        $player->sendMessage("§b/npc race|pose §7- apparence");
        $player->sendMessage("§b/npc tp|here|move|rotate §7- positionnement");
        $player->sendMessage("§b/npc copy §7| §b/npc paste §7- copier les reglages");
        $player->sendMessage("§b/npc list|info|refresh|reload|export|import|debug|uuid|wand|admin");
        return true;
    }

    private function admin(Player $player): bool {
        $enabled = !$this->npcManager->isAdmin($player->getName());
        $this->npcManager->setAdmin($player->getName(), $enabled);

        if($enabled) {
            $this->giveWand($player);
            $player->sendMessage("§aMode admin NPC active.");
            $player->sendMessage("§7Clic gauche : editer §8| §7Clic droit : creer");
        } else {
            $removed = 0;
            foreach($player->getInventory()->getContents() as $slot => $item) {
                if($item->getNamedTag()->getTag(Constants::WAND_TAG) !== null) {
                    $player->getInventory()->clear($slot);
                    $removed++;
                }
            }
            $player->sendMessage("§cMode admin NPC desactive." . ($removed > 0 ? " §7Baguette retiree." : ""));
        }

        $this->npcManager->refreshNPCsForPlayer($player);
        return true;
    }

    private function giveWand(Player $player): void {
        foreach($player->getInventory()->getContents() as $item) {
            if($item->getNamedTag()->getTag(Constants::WAND_TAG) !== null) {
                $player->sendMessage("§7Tu as deja la baguette NPC.");
                return;
            }
        }

        $wand = VanillaItems::WOODEN_HOE()->setCustomName(Constants::NPC_WAND_NAME);
        $wand->setLore(["§7Clic gauche : editer le NPC vise", "§7Clic droit : creer un NPC"]);

        $tag = $wand->getNamedTag();
        $tag->setByte(Constants::WAND_TAG, 1);
        $wand->setNamedTag($tag);

        if($player->getInventory()->canAddItem($wand)) {
            $player->getInventory()->addItem($wand);
            $player->sendMessage("§aBaguette NPC recue.");
        } else {
            $player->sendMessage("§cInventaire plein, libere une case puis refais la commande.");
        }
    }

    private function wand(Player $player): bool {
        $this->giveWand($player);
        return true;
    }

    private function select(Player $player, array $args): bool {
        if(isset($args[0])) {
            $uuid = $this->npcManager->resolve($args[0]);
            if($uuid === null) {
                $player->sendMessage("§cAucun NPC ne correspond a §e" . $args[0]);
                return true;
            }

            $this->npcManager->select($player, $uuid);
            $player->sendMessage("§aNPC selectionne : §e" . ($this->npcManager->getNPCData($uuid)["title"] ?? "NPC"));
            return true;
        }

        $radius = (float)Main::getInstance()->getConfig()->getNested("settings.selection-radius", 8.0);
        $uuid = $this->npcManager->findLookedAt($player, $radius);

        if($uuid === null) {
            $player->sendMessage("§cAucun NPC a proximite.");
            return true;
        }

        $this->npcManager->select($player, $uuid);
        $player->sendMessage("§aNPC selectionne : §e" . ($this->npcManager->getNPCData($uuid)["title"] ?? "NPC"));
        return true;
    }

    private function create(Player $player): bool {
        $worldName = $player->getWorld()->getFolderName();
        $max = (int)Main::getInstance()->getConfig()->getNested("settings.max-npcs-per-world", 0);

        if($max > 0 && $this->npcManager->countInWorld($worldName) >= $max) {
            $player->sendMessage("§cLimite de NPCs atteinte dans ce monde (" . $max . ").");
            return true;
        }

        $position = $player->getPosition();
        $location = $player->getLocation();

        $data = $this->npcManager->getDefaultNPCDataWithRotation(
            $position->x, $position->y, $position->z, $worldName, $location->yaw, $location->pitch
        );
        $data["creator"] = $player->getName();

        $uuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        $this->npcManager->select($player, $uuid);

        $this->log($player, "create", $uuid, "commande");
        $player->sendMessage(Messages::get("npc.created", ["uuid" => $uuid]));
        return true;
    }

    private function spawn(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $this->npcManager->updateNPCData($uuid, ["stored" => false]);
        $this->npcManager->respawnNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aNPC respawne.");
        return true;
    }

    private function delete(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $title = $this->npcManager->getNPCData($uuid)["title"] ?? "NPC";
        $this->log($player, "delete", $uuid, $title);
        $this->npcManager->deleteNPC($uuid);

        $player->sendMessage(Messages::get("npc.deleted", ["title" => $title]));
        return true;
    }

    private function setId(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc id <identifiant> [uuid]");
            return true;
        }

        $customId = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $args[0]));
        if($customId === "") {
            $player->sendMessage("§cIdentifiant invalide (lettres, chiffres, tirets uniquement).");
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        if($this->npcManager->customIdExists($customId, $uuid)) {
            $player->sendMessage("§cCet identifiant est deja utilise.");
            return true;
        }

        $this->npcManager->updateNPCData($uuid, ["customId" => $customId]);
        $this->npcManager->updateNameTag($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aIdentifiant defini : §e" . $customId);
        return true;
    }

    private function edit(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new MainGUI($this->npcManager))->open($player, $uuid);
        return true;
    }

    private function gui(Player $player): bool {
        (new NPCListGUI($this->npcManager))->open($player, 0, false);
        return true;
    }

    private function list(Player $player): bool {
        $all = $this->npcManager->getAllNPCData();

        $player->sendMessage("§e===== NPCs (" . count($all) . ") =====");

        $shown = 0;
        foreach($all as $uuid => $data) {
            if($shown >= 20) {
                $player->sendMessage("§7... utilise §b/npc gui§7 pour la liste complete.");
                break;
            }

            $identifier = (string)($data["customId"] ?? "");
            $status = $this->npcManager->getEntity($uuid) !== null ? "§aactif" : "§cabsent";

            $player->sendMessage("§7- §f" . ($data["title"] ?? "NPC") . " §8[" . ($identifier !== "" ? $identifier : $uuid) . "] §7" . ($data["position"]["world"] ?? "?") . " " . $status);
            $shown++;
        }

        return true;
    }

    private function teleport(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $data = $this->npcManager->getNPCData($uuid);
        $world = $player->getServer()->getWorldManager()->getWorldByName((string)$data["position"]["world"]);

        if($world === null) {
            $player->sendMessage("§cMonde non charge.");
            return true;
        }

        $player->teleport(new Position((float)$data["position"]["x"], (float)$data["position"]["y"], (float)$data["position"]["z"], $world));
        $player->sendMessage("§aTeleporte au NPC.");
        return true;
    }

    private function here(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $position = $player->getPosition();
        $location = $player->getLocation();

        $this->npcManager->updateNPCData($uuid, [
            "position" => [
                "x" => $position->x,
                "y" => $position->y,
                "z" => $position->z,
                "world" => $player->getWorld()->getFolderName()
            ],
            "yaw" => $location->yaw,
            "pitch" => $location->pitch,
            "headYaw" => $location->yaw,
            "stored" => false
        ]);

        $this->npcManager->updateNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aNPC deplace sur ta position.");
        return true;
    }

    private function move(Player $player, array $args): bool {
        if(count($args) < 3) {
            $player->sendMessage("§cUsage: /npc move <x> <y> <z> [uuid]");
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, 3);
        if($uuid === null) return true;

        $data = $this->npcManager->getNPCData($uuid);

        $this->npcManager->updateNPCData($uuid, [
            "position" => [
                "x" => (float)$args[0],
                "y" => (float)$args[1],
                "z" => (float)$args[2],
                "world" => (string)$data["position"]["world"]
            ],
            "stored" => false
        ]);

        $this->npcManager->updateNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aNPC deplace.");
        return true;
    }

    private function rotate(Player $player, array $args): bool {
        $mode = strtolower($args[0] ?? "");

        if($mode !== "body" && $mode !== "head") {
            $player->sendMessage("§cUsage: /npc rotate <body|head> [uuid]");
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        $yaw = $player->getLocation()->yaw;
        $pitch = $player->getLocation()->pitch;

        $opposite = fmod($yaw + 180, 360);

        if($mode === "body") {
            $this->npcManager->updateNPCData($uuid, ["yaw" => $opposite, "pitch" => $pitch, "headYaw" => $opposite]);
            $this->npcManager->updateNPC($uuid);
            $player->sendMessage("§aLe NPC te fait face.");
        } else {
            $this->npcManager->updateNPCData($uuid, ["headYaw" => $opposite]);

            $entity = $this->npcManager->getEntity($uuid);
            if($entity !== null) {
                $entity->setHeadYaw($opposite);
            }

            $player->sendMessage("§aTete du NPC tournee vers toi.");
        }

        $this->npcManager->saveNPC($uuid);
        return true;
    }

    private function armor(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $editor = new ChestEditor($this->npcManager);
        if(!$editor->openArmor($player, $uuid, null)) {
            (new \CustomNPC\gui\ArmorGUI($this->npcManager))->open($player, $uuid);
        }

        return true;
    }

    private function drops(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $editor = new ChestEditor($this->npcManager);
        if(!$editor->openDrops($player, $uuid, null)) {
            (new \CustomNPC\gui\DropsGUI($this->npcManager))->open($player, $uuid);
        }

        return true;
    }

    private function skin(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc skin <me|player:pseudo|fichier.png|reset> [uuid]");
            $player->sendMessage("§7Liste des fichiers : §e/npc skinlist");
            return true;
        }

        $value = $args[0];
        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        if(strtolower($value) === "reset") {
            $this->npcManager->resetSkin($uuid);
            $player->sendMessage(Messages::get("skin.reset"));
            return true;
        }

        if(strtolower($value) === "me") {
            $this->npcManager->changeSkinFromPlayer($uuid, $player);
            $player->sendMessage(Messages::get("skin.applied", ["skin" => $player->getName()]));
            return true;
        }

        $result = $this->npcManager->changeSkin($uuid, $value);

        if($result === SkinManager::RESULT_OK) {
            $player->sendMessage(Messages::get("skin.applied", ["skin" => $value]));
        } elseif($result === SkinManager::RESULT_INVALID) {
            $player->sendMessage(Messages::get("skin.invalid", ["skin" => $value]));
        } else {
            $player->sendMessage(Messages::get("skin.not-found", ["skin" => $value]));
        }

        return true;
    }

    private function skinModel(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc skinmodel <steve|alex> [uuid]");
            return true;
        }

        $model = $this->npcManager->getModelManager()->normalizeModel($args[0]);

        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        $this->npcManager->changeSkinModel($uuid, $model);
        $player->sendMessage(Messages::get("skin.model-set", [
            "model" => $this->npcManager->getModelManager()->getModelLabel($model)
        ]));

        return true;
    }

    private function listSkins(Player $player): bool {
        $skinManager = $this->npcManager->getSkinManager();
        $skins = $skinManager->listAvailableSkins();

        $player->sendMessage("§e===== Skins disponibles =====");
        $player->sendMessage("§7Dossier : §8" . $skinManager->skinsFolder());
        $player->sendMessage("§7- §eme §7(ton skin)");
        $player->sendMessage("§7- §eplayer:<pseudo> §7(joueur connecte)");
        $player->sendMessage("§7- §ereset §7(skin par defaut)");

        foreach($skins as $skin) {
            $player->sendMessage("§7- §b" . $skin);
        }

        if(empty($skins)) {
            $player->sendMessage("§cAucun PNG trouve. Depose tes fichiers 64x64 dans ce dossier.");
        } else {
            $player->sendMessage("§8Formats acceptes : 64x32, 64x64, 128x64, 128x128");
        }

        return true;
    }

    private function race(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc race <race> [uuid]");
            return true;
        }

        $raceId = strtolower($args[0]);
        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        if(!$this->npcManager->changeRace($uuid, $raceId)) {
            $player->sendMessage("§cRace inconnue. Voir §e/npc listraces");
            return true;
        }

        $player->sendMessage("§aRace appliquee : §e" . $raceId);
        return true;
    }

    private function listRaces(Player $player): bool {
        $player->sendMessage("§e===== Races =====");

        foreach($this->npcManager->getRaceManager()->listRaces() as $id => $label) {
            $player->sendMessage("§7- §b" . $id . " §7(" . $label . ")");
        }

        return true;
    }

    private function pose(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc pose <pose> [uuid]");
            return true;
        }

        $poseId = strtolower($args[0]);
        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        if(!$this->npcManager->changePose($uuid, $poseId)) {
            $player->sendMessage("§cPose inconnue. Voir §e/npc listposes");
            return true;
        }

        $player->sendMessage("§aPose appliquee : §e" . $poseId);
        return true;
    }

    private function listPoses(Player $player): bool {
        $player->sendMessage("§e===== Poses =====");

        foreach($this->npcManager->getModelManager()->listPoses() as $id => $label) {
            $player->sendMessage("§7- §b" . $id . " §7(" . $label . ")");
        }

        return true;
    }

    private function nametag(Player $player, array $args): bool {
        if(empty($args)) {
            $uuid = $this->resolveTarget($player, $args);
            if($uuid === null) return true;

            (new NametagGUI($this->npcManager))->open($player, $uuid);
            return true;
        }

        $mode = strtolower($args[0]);
        if(!isset(Constants::NAMETAG_MODES[$mode])) {
            $player->sendMessage("§cModes disponibles : §ealways§7, §ehover§7, §ehidden");
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, 1);
        if($uuid === null) return true;

        $this->npcManager->updateNPCData($uuid, ["nametagMode" => $mode]);

        $entity = $this->npcManager->getEntity($uuid);
        if($entity !== null) {
            $entity->applyNametagMode($mode);
        }

        $this->npcManager->updateNameTag($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aNametag : §e" . Constants::NAMETAG_MODES[$mode]);
        return true;
    }

    private function animation(Player $player, array $args): bool {
        if(empty($args)) {
            $uuid = $this->resolveTarget($player, $args);
            if($uuid === null) return true;

            (new AnimationGUI($this->npcManager))->open($player, $uuid);
            return true;
        }

        $animation = strtolower($args[0]);
        if(!isset(Constants::ANIMATIONS[$animation])) {
            $player->sendMessage("§cAnimations : §e" . implode("§7, §e", array_keys(Constants::ANIMATIONS)));
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, 4);
        if($uuid === null) return true;

        $updates = ["animation" => $animation];

        if(isset($args[1])) $updates["animationHeight"] = max(0.5, min(64.0, (float)$args[1]));
        if(isset($args[2])) $updates["animationSpeed"] = max(0.02, min(1.0, (float)$args[2]));
        if(isset($args[3])) $updates["animationPause"] = max(0, min(600, (int)$args[3]));

        if($animation !== Constants::ANIM_NONE) {
            $updates["immobile"] = true;
        }

        $this->npcManager->updateNPCData($uuid, $updates);
        $this->npcManager->updateNPC($uuid);
        $this->npcManager->saveNPC($uuid);

        $player->sendMessage("§aAnimation : §e" . Constants::ANIMATIONS[$animation]);
        return true;
    }

    private function dialogue(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new DialogueGUI($this->npcManager))->open($player, $uuid);
        return true;
    }

    private function dialogueTree(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new DialogueTreeGUI($this->npcManager, Main::getInstance()->getDialogueRunner(), Main::getInstance()->getConditionManager()))->open($player, $uuid);
        return true;
    }

    private function shop(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new ShopEditGUI($this->npcManager, Main::getInstance()->getShopManager()))->open($player, $uuid);
        return true;
    }

    private function visibility(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new VisibilityGUI($this->npcManager))->open($player, $uuid);
        return true;
    }

    private function waypoint(Player $player, array $args): bool {
        $action = strtolower($args[0] ?? "");

        if($action === "") {
            $uuid = $this->resolveTarget($player, []);
            if($uuid === null) return true;

            (new WaypointGUI($this->npcManager))->open($player, $uuid);
            return true;
        }

        $uuid = $this->resolveTarget($player, $args, $action === "remove" ? 2 : 1);
        if($uuid === null) return true;

        $waypoints = $this->npcManager->getWaypoints($uuid);

        switch($action) {
            case "add":
                $position = $player->getPosition();
                $waypoints[] = [
                    "x" => round($position->x, 2),
                    "y" => round($position->y, 2),
                    "z" => round($position->z, 2),
                    "wait" => (int)($this->npcManager->getNPCData($uuid)["animationPause"] ?? 40)
                ];

                $this->npcManager->setWaypoints($uuid, $waypoints);
                $this->log($player, "waypoint_add", $uuid, "point " . count($waypoints));

                $player->sendMessage(Messages::get("waypoint.added", ["index" => count($waypoints)]));
                break;

            case "list":
                if(empty($waypoints)) {
                    $player->sendMessage(Messages::get("waypoint.empty"));
                    break;
                }

                $player->sendMessage("§e===== Points (" . count($waypoints) . ") =====");
                foreach($waypoints as $index => $point) {
                    $player->sendMessage("§7#" . ($index + 1) . " §f" . (int)$point["x"] . ", " . (int)$point["y"] . ", " . (int)$point["z"] . " §8| attente " . (int)$point["wait"]);
                }
                break;

            case "remove":
                $index = (int)($args[1] ?? 0) - 1;

                if(!isset($waypoints[$index])) {
                    $player->sendMessage("§cPoint introuvable.");
                    break;
                }

                unset($waypoints[$index]);
                $this->npcManager->setWaypoints($uuid, array_values($waypoints));
                $this->log($player, "waypoint_remove", $uuid, "point " . ($index + 1));

                $player->sendMessage(Messages::get("waypoint.removed", ["index" => $index + 1]));
                break;

            case "clear":
                $this->npcManager->setWaypoints($uuid, []);
                $this->npcManager->updateNPCData($uuid, ["animation" => Constants::ANIM_NONE]);
                $this->npcManager->saveNPC($uuid);
                $this->log($player, "waypoint_clear", $uuid, "");

                $player->sendMessage(Messages::get("waypoint.cleared"));
                break;

            case "start":
                if(count($waypoints) < 2) {
                    $player->sendMessage(Messages::get("waypoint.need-two"));
                    break;
                }

                $this->npcManager->updateNPCData($uuid, ["animation" => Constants::ANIM_WAYPOINTS, "immobile" => true]);
                $this->npcManager->updateNPC($uuid);
                $this->npcManager->saveNPC($uuid);
                $this->log($player, "waypoint_start", $uuid, "");

                $player->sendMessage("§aPatrouille demarree.");
                break;

            case "stop":
                $this->npcManager->updateNPCData($uuid, ["animation" => Constants::ANIM_NONE]);
                $this->npcManager->updateNPC($uuid);
                $this->npcManager->saveNPC($uuid);
                $this->log($player, "waypoint_stop", $uuid, "");

                $player->sendMessage("§cPatrouille arretee.");
                break;

            default:
                $player->sendMessage("§cUsage: /npc waypoint <add|list|remove <n>|clear|start|stop>");
                break;
        }

        return true;
    }

    private function logs(Player $player, array $args): bool {
        $logManager = Main::getInstance()->getLogManager();

        if(!$logManager->isEnabled()) {
            $player->sendMessage("§cL'historique est desactive dans la configuration.");
            return true;
        }

        if(strtolower($args[0] ?? "") === "clear") {
            $logManager->clear(null);
            $player->sendMessage("§aHistorique efface.");
            return true;
        }

        $limit = isset($args[0]) && is_numeric($args[0]) ? (int)$args[0] : 15;
        $uuid = $this->npcManager->getSelection($player);
        $entries = $logManager->getLogs($uuid, $limit);

        if(empty($entries)) {
            $player->sendMessage("§7Aucune entree d'historique.");
            return true;
        }

        $player->sendMessage("§e===== Historique" . ($uuid !== null ? " du NPC selectionne" : "") . " =====");

        foreach($entries as $entry) {
            $when = date("d/m H:i", (int)$entry["time"]);
            $details = (string)$entry["details"];

            $player->sendMessage("§8[" . $when . "] §f" . $entry["actor"] . " §7" . $entry["action"] . ($details !== "" ? " §8(" . $details . ")" : ""));
        }

        return true;
    }

    private function log(Player $player, string $action, string $uuid, string $details): void {
        Main::getInstance()->getLogManager()->log($player->getName(), $action, $uuid, $details);
    }

    private function info(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $data = $this->npcManager->getNPCData($uuid);

        $player->sendMessage("§e===== " . ($data["title"] ?? "NPC") . " =====");
        $player->sendMessage("§7UUID: §f" . $uuid);
        $player->sendMessage("§7ID court: §f" . (($data["customId"] ?? "") !== "" ? $data["customId"] : "aucun"));
        $player->sendMessage("§7Monde: §f" . ($data["position"]["world"] ?? "?") . " §7(" . (int)$data["position"]["x"] . ", " . (int)$data["position"]["y"] . ", " . (int)$data["position"]["z"] . ")");
        $player->sendMessage("§7Vie: §f" . (int)($data["health"] ?? 0) . "/" . (int)($data["maxHealth"] ?? 0));
        $player->sendMessage("§7Race/pose: §f" . ($data["race"] ?? "?") . " / " . ($data["pose"] ?? "?"));
        $player->sendMessage("§7Nametag: §f" . (Constants::NAMETAG_MODES[$data["nametagMode"] ?? ""] ?? "?"));
        $player->sendMessage("§7Animation: §f" . (Constants::ANIMATIONS[$data["animation"] ?? ""] ?? "?"));
        $player->sendMessage("§7Commandes: §f" . count($data["commands"] ?? []) . " §8| §7Dialogues: §f" . count($data["dialogue"] ?? []) . " §8| §7Drops: §f" . count($data["drops"] ?? []));
        $player->sendMessage("§7Statut: " . ($this->npcManager->getEntity($uuid) !== null ? "§aactif" : (($data["stored"] ?? false) ? "§6range en item" : "§cabsent")));
        $player->sendMessage("§7Cree par: §f" . (($data["creator"] ?? "") !== "" ? $data["creator"] : "inconnu"));

        return true;
    }

    private function copy(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        $data = $this->npcManager->getNPCData($uuid);
        unset($data["position"], $data["runtimeId"], $data["customId"], $data["usedOnce"]);

        $this->npcManager->setClipboard($player->getName(), $data);
        $player->sendMessage("§aReglages copies. Utilise §e/npc paste§a pour creer une copie ici.");
        return true;
    }

    private function paste(Player $player): bool {
        $clipboard = $this->npcManager->getClipboard($player->getName());

        if($clipboard === null) {
            $player->sendMessage("§cPresse-papier vide. Utilise §e/npc copy§c d'abord.");
            return true;
        }

        $position = $player->getPosition();
        $location = $player->getLocation();

        $data = $clipboard;
        $data["position"] = [
            "x" => $position->x,
            "y" => $position->y,
            "z" => $position->z,
            "world" => $player->getWorld()->getFolderName()
        ];
        $data["yaw"] = $location->yaw;
        $data["pitch"] = $location->pitch;
        $data["headYaw"] = $location->yaw;
        $data["customId"] = "";
        $data["runtimeId"] = 0;
        $data["stored"] = false;
        $data["usedOnce"] = [];
        $data["creator"] = $player->getName();

        $uuid = $this->npcManager->createNPC($data);
        $this->npcManager->spawnNPC($player->getWorld(), $uuid);
        $this->npcManager->select($player, $uuid);

        $player->sendMessage("§aNPC colle et selectionne.");
        return true;
    }

    private function refresh(Player $player): bool {
        $count = 0;
        $worldName = $player->getWorld()->getFolderName();

        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            if(($data["position"]["world"] ?? "") !== $worldName) continue;
            if($data["stored"] ?? false) continue;

            $this->npcManager->updateNPC($uuid);
            $count++;
        }

        $player->sendMessage("§a" . $count . " NPCs rafraichis.");
        return true;
    }

    private function reload(Player $player): bool {
        Main::getInstance()->reloadConfig();
        Messages::reload(Main::getInstance());
        $player->sendMessage("§aConfiguration et messages recharges.");
        return true;
    }

    private function export(Player $player): bool {
        $file = Main::getInstance()->getDataFolder() . "export_" . date("Ymd_His") . ".json";
        $json = json_encode($this->npcManager->getAllNPCData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if($json === false || file_put_contents($file, $json) === false) {
            $player->sendMessage("§cEchec de l'export.");
            return true;
        }

        $player->sendMessage("§aExport ecrit dans §e" . basename($file));
        return true;
    }

    private function import(Player $player, array $args): bool {
        if(empty($args)) {
            $player->sendMessage("§cUsage: /npc import <fichier.json>");
            return true;
        }

        $file = Main::getInstance()->getDataFolder() . basename($args[0]);

        if(!file_exists($file)) {
            $player->sendMessage("§cFichier introuvable : §e" . basename($file));
            return true;
        }

        $decoded = json_decode((string)file_get_contents($file), true);

        if(!is_array($decoded)) {
            $player->sendMessage("§cFichier JSON invalide.");
            return true;
        }

        $imported = 0;
        foreach($decoded as $data) {
            if(!is_array($data)) continue;

            $data["runtimeId"] = 0;
            $data["customId"] = "";

            $uuid = $this->npcManager->createNPC($data);

            $world = $player->getServer()->getWorldManager()->getWorldByName((string)($data["position"]["world"] ?? ""));
            if($world !== null) {
                $this->npcManager->spawnNPC($world, $uuid);
            }

            $imported++;
        }

        $player->sendMessage("§a" . $imported . " NPCs importes.");
        return true;
    }

    private function uuid(Player $player): bool {
        $waiting = $this->npcManager->isWaitingForUuid($player->getName());
        $this->npcManager->setWaitingForUuid($player->getName(), !$waiting);

        $player->sendMessage($waiting ? "§cDetection d'UUID desactivee." : "§aDetection activee : touche un NPC.");
        return true;
    }

    private function fakePlayer(Player $player, array $args): bool {
        $uuid = $this->resolveTarget($player, $args);
        if($uuid === null) return true;

        (new FakePlayerGUI($this->npcManager))->open($player, $uuid);
        return true;
    }

    private function debug(Player $player): bool {
        $logger = Main::getInstance()->getLogger();
        $logger->info("===== DEBUG CustomNPC =====");

        foreach($this->npcManager->getAllNPCData() as $uuid => $data) {
            $entity = $this->npcManager->getEntity($uuid);

            $logger->info(sprintf(
                "%s (%s) monde=%s actif=%s vie=%s/%s anim=%s nametag=%s cmds=%d",
                $uuid,
                $data["title"] ?? "NPC",
                $data["position"]["world"] ?? "?",
                $entity !== null ? "oui" : "non",
                (int)($data["health"] ?? 0),
                (int)($data["maxHealth"] ?? 0),
                $data["animation"] ?? "none",
                $data["nametagMode"] ?? "always",
                count($data["commands"] ?? [])
            ));
        }

        $logger->info("InvMenu disponible : " . (ChestEditor::isAvailable() ? "oui" : "non"));
        $player->sendMessage("§aInformations ecrites dans la console.");
        return true;
    }
}
