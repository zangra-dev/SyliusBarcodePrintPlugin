<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;

final class AdminCrudTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $em->createQuery('DELETE FROM ' . LabelTemplate::class)->execute();
        $em->createQuery('DELETE FROM ' . LabelPrinter::class)->execute();

        $repository = $container->get('sylius.repository.admin_user');
        $user = $repository->findOneBy(['username' => 'barcode-admin']);
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
    }

    public function testIndexPagesRender(): void
    {
        foreach (['/admin/label-templates/', '/admin/label-printers/', '/admin/label-templates/new', '/admin/label-printers/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url . ' => ' . $this->client->getResponse()->getStatusCode());
        }
    }

    public function testCreatePrinter(): void
    {
        $crawler = $this->client->request('GET', '/admin/label-printers/new');
        $form = $crawler->filter('form[method=post]')->last()->form();
        $form->setValues([
            'zangra_barcode_print_label_printer[code]' => 'tsc',
            'zangra_barcode_print_label_printer[name]' => 'TSC atelier',
            'zangra_barcode_print_label_printer[host]' => '172.16.0.22',
            'zangra_barcode_print_label_printer[port]' => '9100',
            'zangra_barcode_print_label_printer[transport]' => 'local_agent',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertNotNull($em->getRepository(LabelPrinter::class)->findOneBy(['code' => 'tsc']));
    }

    public function testCreateTemplateWithVariables(): void
    {
        $crawler = $this->client->request('GET', '/admin/label-templates/new');
        $token = $crawler->filter('input[name="zangra_barcode_print_label_template[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/label-templates/new', [
            'zangra_barcode_print_label_template' => [
                'code' => 'cable',
                'name' => 'Cable label',
                'path' => 'cable-label.zpl',
                'enabled' => '1',
                'variables' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => '1'],
                    ['key' => 'ean13', 'label' => 'EAN13', 'type' => 'ean13', 'required' => '1'],
                    ['key' => 'techInfo', 'label' => 'Tech', 'type' => 'text'],
                    ['key' => 'madeIn', 'label' => 'Made in', 'type' => 'text'],
                    ['key' => 'icons', 'label' => 'Icons', 'type' => 'icons'],
                    ['key' => 'footer', 'label' => 'Footer', 'type' => 'text'],
                ],
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $template = $em->getRepository(LabelTemplate::class)->findOneBy(['code' => 'cable']);
        self::assertNotNull($template, implode(" | ", $this->client->getCrawler()->filter(".invalid-feedback, .alert-danger, .form-error-message")->each(static fn ($n) => trim($n->text()))));
        self::assertCount(6, $template->getVariables());
    }

    public function testTemplateWithUndefinedTokensIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/admin/label-templates/new');
        $token = $crawler->filter('input[name="zangra_barcode_print_label_template[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/label-templates/new', [
            'zangra_barcode_print_label_template' => [
                'code' => 'broken',
                'name' => 'Broken',
                'path' => 'cable-label.zpl',
                'enabled' => '1',
                'variables' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text'],
                ],
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertNull($em->getRepository(LabelTemplate::class)->findOneBy(['code' => 'broken']));
    }

    public function testTemplateWithMissingFileIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/admin/label-templates/new');
        $token = $crawler->filter('input[name="zangra_barcode_print_label_template[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/label-templates/new', [
            'zangra_barcode_print_label_template' => [
                'code' => 'nofile',
                'name' => 'No file',
                'path' => 'does-not-exist.zpl',
                'enabled' => '1',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertNull($em->getRepository(LabelTemplate::class)->findOneBy(['code' => 'nofile']));
    }
}
