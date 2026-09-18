<?php

namespace CustomNPC\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

class CustomForm implements Form {

    private array $data = [];
    private array $labelMap = [];
    private $callable;

    public function __construct(?callable $callable = null) {
        $this->callable = $callable;
        $this->data["type"] = "custom_form";
        $this->data["title"] = "";
        $this->data["content"] = [];
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

    public function addLabel(string $text, ?string $label = null): void {
        $this->addContent(["type" => "label", "text" => $text], $label);
    }

    public function addToggle(string $text, bool $default = false, ?string $label = null): void {
        $this->addContent(["type" => "toggle", "text" => $text, "default" => $default], $label);
    }

    public function addSlider(string $text, float $min, float $max, float $step = -1.0, float $default = -1.0, ?string $label = null): void {
        $content = ["type" => "slider", "text" => $text, "min" => $min, "max" => $max];

        if($step >= 1.0) {
            $content["step"] = $step;
        }
        if($default !== -1.0) {
            $content["default"] = $default;
        }

        $this->addContent($content, $label);
    }

    public function addStepSlider(string $text, array $steps, int $defaultIndex = -1, ?string $label = null): void {
        $content = ["type" => "step_slider", "text" => $text, "steps" => array_values($steps)];

        if($defaultIndex !== -1) {
            $content["default"] = $defaultIndex;
        }

        $this->addContent($content, $label);
    }

    public function addDropdown(string $text, array $options, int $default = 0, ?string $label = null): void {
        $this->addContent([
            "type" => "dropdown",
            "text" => $text,
            "options" => array_values($options),
            "default" => $default
        ], $label);
    }

    public function addInput(string $text, string $placeholder = "", string $default = "", ?string $label = null): void {
        $this->addContent([
            "type" => "input",
            "text" => $text,
            "placeholder" => $placeholder,
            "default" => $default
        ], $label);
    }

    private function addContent(array $content, ?string $label): void {
        $this->data["content"][] = $content;
        $this->labelMap[] = $label ?? count($this->labelMap);
    }

    public function handleResponse(Player $player, $data): void {
        if($data === null) {
            $this->processResponse($player, null);
            return;
        }

        if(!is_array($data)) {
            throw new FormValidationException("Reponse de formulaire invalide");
        }

        $result = [];
        $index = 0;

        foreach($this->labelMap as $key) {
            $element = $this->data["content"][$index] ?? null;
            $value = $data[$index] ?? null;

            if(is_array($element) && ($element["type"] ?? "") === "label") {
                $result[$key] = null;
            } else {
                $result[$key] = $value;
            }

            $index++;
        }

        $this->processResponse($player, $result);
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
