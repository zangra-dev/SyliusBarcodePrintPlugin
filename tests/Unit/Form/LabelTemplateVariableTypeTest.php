<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Form;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplateVariable;
use Zangra\SyliusBarcodePrintPlugin\Form\Type\LabelTemplateVariableType;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

final class LabelTemplateVariableTypeTest extends TestCase
{
    public function testMissingTypeFallsBackToText(): void
    {
        $variable = new LabelTemplateVariable();
        $form = Forms::createFormFactory()->create(LabelTemplateVariableType::class, $variable);

        $form->submit(['key' => 'name', 'label' => 'Name']);

        self::assertTrue($form->isSynchronized());
        self::assertSame(VariableType::Text, $variable->getType());
    }
}
