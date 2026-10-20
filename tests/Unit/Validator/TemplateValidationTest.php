<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplateVariable;
use Zangra\SyliusBarcodePrintPlugin\Label\IconRegistry;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelRenderer;
use Zangra\SyliusBarcodePrintPlugin\Label\TemplateFileLocator;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;
use Zangra\SyliusBarcodePrintPlugin\Validator\AllTokensDefinedValidator;
use Zangra\SyliusBarcodePrintPlugin\Validator\TemplateFileExistsValidator;

final class TemplateValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $locator = new TemplateFileLocator(\dirname(__DIR__, 3) . '/resources/labels');
        $renderer = new LabelRenderer($locator, new IconRegistry());

        $validators = [
            TemplateFileExistsValidator::class => new TemplateFileExistsValidator($locator),
            AllTokensDefinedValidator::class => new AllTokensDefinedValidator($locator, $renderer),
        ];

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new class($validators) implements ConstraintValidatorFactoryInterface {
                private ConstraintValidatorFactory $fallback;

                /** @param array<class-string, object> $validators */
                public function __construct(private readonly array $validators)
                {
                    $this->fallback = new ConstraintValidatorFactory();
                }

                public function getInstance(Constraint $constraint): ConstraintValidatorInterface
                {
                    if ($constraint instanceof UniqueEntity) {
                        return new class() extends ConstraintValidator {
                            public function validate(mixed $value, Constraint $constraint): void
                            {
                                // uniqueness needs a database; covered by the functional tests
                            }
                        };
                    }

                    return $this->validators[$constraint->validatedBy()] ?? $this->fallback->getInstance($constraint);
                }
            })
            ->getValidator()
        ;
    }

    public function testAFullyDefinedTemplateIsValid(): void
    {
        self::assertCount(0, $this->validator->validate($this->template('cable-label.zpl', ['code', 'ean13', 'techInfo', 'madeIn', 'icons', 'footer'])));
    }

    public function testAMissingFileIsRejected(): void
    {
        $violations = $this->validator->validate($this->template('does-not-exist.zpl', []));

        self::assertSame(['path'], $this->paths($violations));
    }

    public function testATraversalPathIsRejected(): void
    {
        $violations = $this->validator->validate($this->template('../composer.json', []));

        self::assertSame(['path'], $this->paths($violations));
    }

    public function testATokenWithoutVariableIsRejected(): void
    {
        $violations = $this->validator->validate($this->template('cable-label.zpl', ['code', 'ean13', 'techInfo', 'madeIn', 'icons']));

        self::assertSame(['variables'], $this->paths($violations));
        self::assertStringContainsString('footer', $violations[0]->getParameters()['{{ tokens }}']);
    }

    public function testVariableKeysAreValidated(): void
    {
        $variable = new LabelTemplateVariable();
        $variable->setKey('quantity');
        $variable->setLabel('Quantity');

        self::assertSame(['key'], $this->paths($this->validator->validate($variable)));

        $variable->setKey('1bad key');
        self::assertSame(['key'], $this->paths($this->validator->validate($variable)));
    }

    public function testAnInvalidEan13DefaultIsRejected(): void
    {
        $variable = new LabelTemplateVariable();
        $variable->setKey('ean13');
        $variable->setLabel('EAN');
        $variable->setType(VariableType::Ean13);
        $variable->setDefaultValue('5415249002840');

        self::assertSame(['defaultValue'], $this->paths($this->validator->validate($variable)));

        $variable->setDefaultValue('5415249002848');
        self::assertCount(0, $this->validator->validate($variable));
    }

    public function testPrinterDefaults(): void
    {
        $printer = new LabelPrinter();
        $printer->setCode('tsc-atelier');
        $printer->setName('TSC atelier');
        $printer->setHost('172.16.0.22');

        self::assertCount(0, $this->validator->validate($printer));

        $printer->setPort(70000);
        self::assertSame(['port'], $this->paths($this->validator->validate($printer)));
    }

    /** @param list<string> $keys */
    private function template(string $path, array $keys): LabelTemplate
    {
        $template = new LabelTemplate();
        $template->setCode('cable');
        $template->setName('Cable');
        $template->setPath($path);
        foreach ($keys as $key) {
            $variable = new LabelTemplateVariable();
            $variable->setKey($key);
            $variable->setLabel($key);
            $template->addVariable($variable);
        }

        return $template;
    }

    /** @return list<string> */
    private function paths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
