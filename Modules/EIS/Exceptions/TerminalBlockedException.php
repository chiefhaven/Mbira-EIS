<?php

namespace Modules\EIS\Exceptions;

class TerminalBlockedException extends EisSaleException
{
    public function __construct(
        public readonly int $businessId,
        public readonly int $terminalId,
        public readonly string $blockingReason
    ) {
        parent::__construct('EIS terminal blocked: '.$blockingReason);
    }
}
