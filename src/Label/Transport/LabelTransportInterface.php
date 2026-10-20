<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label\Transport;

use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TransportException;

interface LabelTransportInterface
{
    /** @throws TransportException when the job cannot be delivered */
    public function send(string $host, int $port, string $zpl): void;
}
