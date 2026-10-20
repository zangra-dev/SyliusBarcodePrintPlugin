<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label\Transport;

use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TransportException;

final class ServerTcpTransport implements LabelTransportInterface
{
    public function __construct(private readonly float $timeout = 5.0)
    {
    }

    public function send(string $host, int $port, string $zpl): void
    {
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, $this->timeout);
        if (false === $socket) {
            throw new TransportException(sprintf('Cannot connect to printer %s:%d (%s).', $host, $port, $errorMessage ?: 'error ' . $errorCode));
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeout));

            $length = \strlen($zpl);
            $written = 0;
            while ($written < $length) {
                $bytes = @fwrite($socket, substr($zpl, $written));
                if (false === $bytes || 0 === $bytes) {
                    throw new TransportException(sprintf('Failed to write the label to printer %s:%d.', $host, $port));
                }
                $written += $bytes;
            }
            fflush($socket);
        } finally {
            fclose($socket);
        }
    }
}
