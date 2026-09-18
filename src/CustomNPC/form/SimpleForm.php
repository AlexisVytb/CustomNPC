<?php

namespace CustomNPC\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

class SimpleForm implements Form {

    public const IMAGE_TYPE_PATH = 0;
    public const IMAGE_TYPE_URL = 1;

    private array $data = [];
    private array $labelMap = [];
    private $callable;

    public function __construct(?callable $callable = null) {
        $this->callable = $callable;
        $this->data["type"] = "form";
        $this->data["title"] = "";
        $this->data["content"] = "";
        $this->data["buttons"] = [];
    }

    public function getCallable(): ?callable {
        return $this->callable;
    }

    public function setCallable(?callable $callable): void {
        $this->callable = $callable;
    }

    public function setTitle(string $title): void {
        $this->data["title"] = $title;
    }

    public function getTitle(): string {
        return (string)$this->data["title"];
    }

    public function setContent(string $content): void {
        $this->data["content"] = $content;
    }

    public function getContent(): string {
        return (string)$this->data["content"];
    }

    public function addButton(string $text, int $imageType = -1, string $imagePath = "", ?string $label = null): void {
        $button = ["text" => $text];

        if($imageType !== -1) {
            $button["image"] = [
                "type" => $imageType === self::IMAGE_TYPE_PATH ? "path" : "url",
                "data" => $imagePath
            ];
        }

        $this->data["buttons"][] = $button;
        $this->labelMap[] = $label ?? count($this->labelMap);
    }

    public function handleResponse(Player $player, $data): void {
        if($data === null) {
            $this->processResponse($player, null);
            return;
        }

        if(is_bool($data)) {
            $this->processResponse($player, null);
            return;
        }

        if(is_numeric($data)) {
            $index = (int)$data;

            if(!isset($this->labelMap[$index])) {
                throw new FormValidationException("Bouton inconnu : " . $index);
            }

            $this->processResponse($player, $this->labelMap[$index]);
            return;
        }

        throw new FormValidationException("Reponse de formulaire invalide");
    }

    private function processResponse(Player $player, $value): void {
        if($this->callable !== null) {
            ($this->callable)($player, $value);
        }
    }

    public function jsonSerialize(): mixed {
        return $this->data;
    }
}
