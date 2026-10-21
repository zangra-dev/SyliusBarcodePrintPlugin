<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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

    public function testOmittedVariablesUseTheTemplateDefaults(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => 1, 'variables' => ['code' => 'abc.001', 'ean13' => '5415249002848']]);
        self::assertResponseStatusCodeSame(200);
        $zpl = $this->json()['zpl'];
        self::assertStringContainsString('^FDabc.001^FS', $zpl);
        self::assertStringContainsString('^FDMade in Italy^FS', $zpl);
        self::assertStringContainsString('^FDwww.zangra.com^FS', $zpl);

        $this->post(['template' => 'cable', 'printer' => 'tsc', 'quantity' => 1, 'variables' => ['code' => 'abc.001', 'ean13' => '5415249002848', 'madeIn' => 'Made in Belgium']]);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('^FDMade in Belgium^FS', $this->json()['zpl']);
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

        self::assertStringContainsString('data-controller="zangra-barcode-print"', $html);
        self::assertStringContainsString('data-action="zangra-barcode-print#open"', $html);

        $config = $this->buttonConfig($html);
        self::assertSame('/admin/barcode-print/render', $config['renderUrl']);
        self::assertSame(['cable', 'second'], array_column($config['templates'], 'code'));
        self::assertSame(['tsc'], array_column($config['printers'], 'code'));
        self::assertSame('abc.001', $config['defaults']['code']);
        self::assertSame(['ce', 'weee'], $config['icons']);
        self::assertCount(6, $config['templates'][0]['variables']);

        $filtered = $twig->createTemplate("{{ component('zangra_barcode_print:print_button', {templateCode: 'second'}) }}")->render();
        $config = $this->buttonConfig($filtered);
        self::assertSame(['second'], array_column($config['templates'], 'code'));
    }

    public function testPrintButtonComponentRendersTheItemRows(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $html = $twig->createTemplate("{{ component('zangra_barcode_print:print_button', {items: [{code: 'abc.001', ean: '5415249002848', quantity: 4, variables: {madeIn: 'Belgium', code: 'x', n: 1}}, {code: 'abc.002', ean: '4006381333931', quantity: 0}]}) }}")->render();

        $config = $this->buttonConfig($html);
        self::assertSame(['abc.001', 'abc.002'], array_column($config['items'], 'code'));
        self::assertSame(['5415249002848', '4006381333931'], array_column($config['items'], 'ean'));
        self::assertSame([4, 1], array_column($config['items'], 'quantity'));
        self::assertSame([['madeIn' => 'Belgium'], []], array_column($config['items'], 'variables'));

        $plain = $twig->createTemplate("{{ component('zangra_barcode_print:print_button') }}")->render();
        self::assertSame([], $this->buttonConfig($plain)['items']);
    }

    public function testPrintButtonComponentListsThePrintableItemsOfAnOrder(): void
    {
        $this->createPrinter('tsc', PrinterTransport::LocalAgent);

        $variant = new class() extends ProductVariant {
            public ?string $ean = '5415249002848';
        };
        $variant->setCode('abc.001');
        $plain = new ProductVariant();
        $plain->setCode('abc.002');

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $template = $twig->createTemplate("{{ component('zangra_barcode_print:print_button', {order: order}) }}");

        $html = $template->render(['order' => $this->order([[$variant, 3], [$plain, 1]])]);
        $config = $this->buttonConfig($html);
        self::assertSame([['code' => 'abc.001', 'ean' => '5415249002848', 'quantity' => 3, 'variables' => []]], $config['items']);

        // The batch dialog only offers printable items, a printer and a template: no label variable fields.
        $crawler = new Crawler($html);
        self::assertCount(0, $crawler->filter('template'));
        self::assertCount(0, $crawler->filter('[data-field]:not([data-field="printer"]):not([data-field="template"])'));

        // Without a printable item the button is not rendered.
        $html = $template->render(['order' => $this->order([[$plain, 1]])]);
        self::assertStringNotContainsString('data-controller="zangra-barcode-print"', $html);
    }

    /** @param list<array{ProductVariant, int}> $lines */
    private function order(array $lines): OrderInterface
    {
        $items = [];
        foreach ($lines as [$variant, $quantity]) {
            $item = $this->createMock(OrderItemInterface::class);
            $item->method('getVariant')->willReturn($variant);
            $item->method('getQuantity')->willReturn($quantity);
            $items[] = $item;
        }
        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn(new ArrayCollection($items));

        return $order;
    }

    /** @return array<string, mixed> */
    private function buttonConfig(string $html): array
    {
        $crawler = new Crawler($html);
        $root = $crawler->filter('[data-controller="zangra-barcode-print"]');
        self::assertCount(1, $root);

        $value = static fn (string $name): mixed => json_decode((string) $root->attr('data-zangra-barcode-print-' . $name . '-value'), true, 512, \JSON_THROW_ON_ERROR);

        return [
            'renderUrl' => $root->attr('data-zangra-barcode-print-render-url-value'),
            'batch' => 'true' === $root->attr('data-zangra-barcode-print-batch-value'),
            'templates' => $value('templates'),
            'icons' => $value('icons'),
            'defaults' => $value('defaults'),
            'messages' => $value('messages'),
            'printers' => $crawler->filter('select[name="printer"] option')->each(static fn (Crawler $option): array => [
                'code' => $option->attr('value'),
                'default' => null !== $option->attr('selected'),
            ]),
            'items' => $crawler->filter('tr[data-code]')->each(static fn (Crawler $row): array => [
                'code' => $row->attr('data-code'),
                'ean' => $row->attr('data-ean'),
                'quantity' => (int) $row->filter('[data-role="quantity"]')->attr('value'),
                'variables' => json_decode((string) $row->attr('data-variables'), true, 512, \JSON_THROW_ON_ERROR),
            ]),
        ];
    }

    public function testOnlyOnePrinterAndOneTemplateCanBeTheDefault(): void
    {
        $first = $this->createPrinter('first', PrinterTransport::LocalAgent);
        $second = $this->createPrinter('second', PrinterTransport::LocalAgent);
        $first->setDefault(true);
        $this->em->flush();
        $second->setDefault(true);
        $this->em->flush();
        $this->em->clear();

        $printers = $this->em->getRepository(LabelPrinter::class)->findBy([], ['code' => 'ASC']);
        self::assertSame([false, true], array_map(static fn (LabelPrinter $printer): bool => $printer->isDefault(), $printers));

        $other = $this->createTemplate('other', 'Other label');
        $other->setDefault(true);
        $this->em->flush();
        $this->createTemplate('third', 'Third label')->setDefault(true);
        $this->em->flush();
        $this->em->clear();

        $defaults = array_map(
            static fn (LabelTemplate $template): string => (string) $template->getCode(),
            $this->em->getRepository(LabelTemplate::class)->findBy(['isDefault' => true]),
        );
        self::assertSame(['third'], $defaults);
    }

    public function testPrintButtonComponentExposesTheDefaultFlag(): void
    {
        $this->createPrinter('plain', PrinterTransport::LocalAgent);
        $this->createPrinter('main', PrinterTransport::LocalAgent)->setDefault(true);
        $this->em->flush();

        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        $html = $twig->createTemplate("{{ component('zangra_barcode_print:print_button') }}")->render();

        $config = $this->buttonConfig($html);
        $defaults = array_column($config['printers'], 'default', 'code');
        self::assertSame(['main' => true, 'plain' => false], $defaults);
        self::assertSame([false], array_column($config['templates'], 'default'));
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
