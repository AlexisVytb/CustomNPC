<?php

declare(strict_types=1);

namespace CustomNPC\libs\muqsit\invmenu\session\network\handler;

use Closure;
use CustomNPC\libs\muqsit\invmenu\session\network\NetworkStackLatencyEntry;

interface PlayerNetworkHandler{

	public function createNetworkStackLatencyEntry(Closure $then) : NetworkStackLatencyEntry;
}