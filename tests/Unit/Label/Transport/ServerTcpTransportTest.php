<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label\Transport;

use PHPUnit\Framework\TestCase;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TransportException;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\ServerTcpTransport;

final class ServerTcpTransportTest extends TestCase
{
    public function testItSendsTheExactBytesToThePrinter(): void
    {
        $printer = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($printer, (string) $errorMessage);
        [$host, $port] = explode(':', (string) stream_socket_get_name($printer, false));

        $zpl = "^XA\n^FDÀ€ caf\u{e9}^FS\n^PQ3,0,1,N\n^XZ\n" . str_repeat('^FDx^FS', 20000);
        (new ServerTcpTransport(2.0))->send($host, (int) $port, $zpl);

        $client = stream_socket_accept($printer, 2.0);
        self::assertNotFalse($client);
        $received = stream_get_contents($client);
        fclose($client);
        fclose($printer);

        self::assertSame($zpl, $received);
    }

    public function testItFailsWhenThePrinterIsUnreachable(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', (string) stream_socket_get_name($socket, false));
        fclose($socket);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Cannot connect to printer 127.0.0.1:' . $port);

        (new ServerTcpTransport(1.0))->send('127.0.0.1', (int) $port, '^XA^XZ');
    }
}
