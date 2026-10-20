<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplateVariable;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\PrinterTransport;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

final class PrintControllerTest extends WebTestCase
{
    private const VALID_VARIABLES = [
        'code' => 'textilecable.031.001',
        'ean13' => '5415249002848',
        'techInfo' => '2 core wires (0.75mm2) IP20',
        'madeIn' => 'Made in Italy',
        'icons' => ['ce', 'weee'],
        'footer' => 'www.zangra.com',
    ];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;
        $em->createQuery('DELETE FROM ' . LabelTemplate::class)->execute();
        $em->createQuery('DELETE FROM ' . LabelPrinter::class)->execute();

        $user = $container->get('sylius.repository.admin_user')->findOneBy(['username' => 'barcode-admin']);
        if (!$user instanceof AdminUserInterface) {
            /** @var AdminUserInterface $user */
            $user = $container->get('sylius.factory.admin_user')->createNew();
            $user->setUsername('barcode-admin');
            $user->setEmail('barcode-admin@example.com');
            $user->setPlainPassword('secret');
            $user->setEnabled(true);
            $user->setLocaleCode('en_US');
            $em->persist($user);
            $em->flush();
        }
        $this->client->loginUser($user, 'admin');

        $this->createTemplate('cable', 'Cable label');
    }

    public function testLocalAgentPrinterReturnsTheZplForTheAgent(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => 2, 'variables' => self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->json();
        self::assertSame('local_agent', $data['transport']);
        self::assertSame('http://127.0.0.1:9101', $data['agentUrl']);
        self::assertSame('172.16.0.22', $data['host']);
        self::assertSame(9100, $data['port']);
        self::assertStringStartsWith('^XA', $data['zpl']);
        self::assertStringContainsString('^FDtextilecable.031.001^FS', $data['zpl']);
        self::assertStringContainsString('^PQ2,0,1,N', $data['zpl']);
        self::assertStringNotContainsString('{{', $data['zpl']);
    }

    public function testServerTcpPrinterSendsTheJobItself(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $this->createPrinter('direct', PrinterTransport::ServerTcp, '127.0.0.1', $port);

        $this->post(['template' => 'cable', 'printer' => 'direct', 'quantity' => 3, 'variables' => self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['transport' => 'server_tcp', 'status' => 'sent'], $this->json());

        $connection = stream_socket_accept($server, 2);
        self::assertNotFalse($connection);
        $received = (string) stream_get_contents($connection);
        self::assertStringStartsWith('^XA', $received);
        self::assertStringContainsString('^PQ3,0,1,N', $received);
        self::assertStringEndsWith("^XZ\n", $received);
    }

    public function testUnreachableServerTcpPrinterReturnsBadGateway(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        $this->createPrinter('down', PrinterTransport::ServerTcp, '127.0.0.1', $port);

        $this->post(['template' => 'cable', 'printer' => 'down', 'variables' => self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(502);
        self::assertArrayHasKey('error', $this->json());
    }

    public function testInvalidEanReturnsFieldErrors(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => 1, 'variables' => ['ean13' => '5415249002840'] + self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('ean13', $this->json()['errors']);
    }

    /** @dataProvider invalidQuantities */
    public function testInvalidQuantityIsRejected(mixed $quantity): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => $quantity, 'variables' => self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('quantity', $this->json()['errors']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'too many' => [1000];
        yield 'text' => ['many'];
    }

    public function testMissingRequiredValueIsReported(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => 1, 'variables' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['code', 'ean13'], array_keys($this->json()['errors']));
    }

    public function testUnknownTemplateOrPrinterReturnsNotFound(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'nope', 'printer' => 'tsc', 'variables' => self::VALID_VARIABLES]);
        self::assertResponseStatusCodeSame(404);

        $this->post(['template' => 'cable', 'printer' => 'nope', 'variables' => self::VALID_VARIABLES]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledPrinterIsNotUsable(): void
    {
        $printer = $this->createPrinter('off', PrinterTransport::LocalAgent);
        $printer->setEnabled(false);
        $this->em->flush();

        $this->post(['template' => 'cable', 'printer' => 'off', 'variables' => self::VALID_VARIABLES]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBodyMustBeJson(): void
    {
        $this->client->request('POST', '/admin/barcode-print/render', ['template' => 'cable']);

        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/admin/barcode-print/render', [], [], ['CONTENT_TYPE' => 'application/json'], '{broken');
        self::assertResponseStatusCodeSame(400);
    }

    public function testRouteOnlyAcceptsPost(): void
    {
        $this->client->request('GET', '/admin/barcode-print/render');

        self::assertResponseStatusCodeSame(405);
    }

    public function testAnonymousUsersCannotPrint(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);
        $this->client->getCookieJar()->clear();

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'variables' => self::VALID_VARIABLES]);

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testPrintButtonComponentRendersTheTemplatesPrintersAndDefaults(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);
        $this->createTemplate('second', 'Second label');

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $html = $twig->createTemplate("{{ component('zangra_barcode_print:print_button', {defaults: {code: 'abc.001', ean13: '5415249002848'}}) }}")->render();

        self::assertStringContainsString('data-zangra-barcode-print=', $html);
        self::assertStringContainsString('data-zangra-barcode-print-open', $html);

        preg_match('/data-zangra-barcode-print="([^"]+)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1], \ENT_QUOTES), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/admin/barcode-print/render', $config['renderUrl']);
        self::assertSame(['cable', 'second'], array_column($config['templates'], 'code'));
        self::assertSame(['tsc'], array_column($config['printers'], 'code'));
        self::assertSame('abc.001', $config['defaults']['code']);
        self::assertSame(['ce', 'weee'], $config['icons']);
        self::assertCount(6, $config['templates'][0]['variables']);

        $filtered = $twig->createTemplate("{{ component('zangra_barcode_print:print_button', {templateCode: 'second'}) }}")->render();
        preg_match('/data-zangra-barcode-print="([^"]+)"/', $filtered, $matches);
        $config = json_decode(html_entity_decode($matches[1], \ENT_QUOTES), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['second'], array_column($config['templates'], 'code'));
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $this->client->request('POST', '/admin/barcode-print/render', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function createPrinter(string $code, PrinterTransport $transport, string $host = '172.16.0.22', int $port = 9100): LabelPrinter
    {
        $printer = new LabelPrinter();
        $printer->setCode($code);
        $printer->setName(ucfirst($code));
        $printer->setHost($host);
        $printer->setPort($port);
        $printer->setTransport($transport);
        $this->em->persist($printer);
        $this->em->flush();

        return $printer;
    }

    private function createTemplate(string $code, string $name): LabelTemplate
    {
        $template = new LabelTemplate();
        $template->setCode($code);
        $template->setName($name);
        $template->setPath('cable-label.zpl');

        $variables = [
            ['code', VariableType::Text, null, true],
            ['ean13', VariableType::Ean13, null, true],
            ['techInfo', VariableType::Text, '2 core wires (0.75mm2) IP20', false],
            ['madeIn', VariableType::Text, 'Made in Italy', false],
            ['icons', VariableType::Icons, 'ce,weee', false],
            ['footer', VariableType::Text, 'www.zangra.com', false],
        ];
        foreach ($variables as [$key, $type, $default, $required]) {
            $variable = new LabelTemplateVariable();
            $variable->setKey($key);
            $variable->setLabel(ucfirst($key));
            $variable->setType($type);
            $variable->setDefaultValue($default);
            $variable->setRequired($required);
            $template->addVariable($variable);
        }

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }
}
