<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label\Transport;

enum PrinterTransport: string
{
    case LocalAgent = 'local_agent';
    case ServerTcp = 'server_tcp';
}
