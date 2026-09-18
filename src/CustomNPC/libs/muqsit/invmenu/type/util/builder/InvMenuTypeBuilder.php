<?php

declare(strict_types=1);

namespace CustomNPC\libs\muqsit\invmenu\type\util\builder;

use CustomNPC\libs\muqsit\invmenu\type\InvMenuType;

interface InvMenuTypeBuilder{

	public function build() : InvMenuType;
}