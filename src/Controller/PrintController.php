<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Controller;

use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\InvalidLabelValuesException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\LabelException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TransportException;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelRenderer;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\LabelTransportInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\PrinterTransport;

final class PrintController
{
    public const ADMIN_ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    /**
     * @param ObjectRepository<LabelTemplate> $templateRepository
     * @param ObjectRepository<LabelPrinter>  $printerRepository
     */
    public function __construct(
        private readonly ObjectRepository $templateRepository,
        private readonly ObjectRepository $printerRepository,
        private readonly LabelRenderer $renderer,
        private readonly LabelTransportInterface $transport,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->authorizationChecker->isGranted(self::ADMIN_ROLE)) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // A JSON body can only be sent cross-site after a CORS preflight, which this endpoint never grants (CSRF protection).
        if ('json' !== $request->getContentTypeFormat()) {
            return new JsonResponse(['error' => 'A JSON body is expected.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $payload = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Malformed JSON.'], Response::HTTP_BAD_REQUEST);
        }
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'Malformed JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $templateCode = $payload['template'] ?? null;
        $printerCode = $payload['printer'] ?? null;
        $variables = $payload['variables'] ?? [];
        $quantity = $payload['quantity'] ?? 1;

        if (!\is_string($templateCode) || '' === $templateCode) {
            $errors['template'] = 'A template is required.';
        }
        if (!\is_string($printerCode) || '' === $printerCode) {
            $errors['printer'] = 'A printer is required.';
        }
        if (!\is_array($variables)) {
            $errors['variables'] = 'Variables must be an object.';
            $variables = [];
        }
        if (\is_string($quantity) && ctype_digit($quantity)) {
            $quantity = (int) $quantity;
        }
        if (!\is_int($quantity)) {
            $errors['quantity'] = 'Quantity must be a whole number.';
            $quantity = 1;
        }
        if ([] !== $errors) {
            return $this->validationError($errors);
        }

        $template = $this->templateRepository->findOneBy(['code' => $templateCode, 'enabled' => true]);
        if (!$template instanceof LabelTemplate) {
            return new JsonResponse(['error' => sprintf('Unknown template "%s".', $templateCode)], Response::HTTP_NOT_FOUND);
        }

        $printer = $this->printerRepository->findOneBy(['code' => $printerCode, 'enabled' => true]);
        if (!$printer instanceof LabelPrinter) {
            return new JsonResponse(['error' => sprintf('Unknown printer "%s".', $printerCode)], Response::HTTP_NOT_FOUND);
        }

        try {
            $zpl = $this->renderer->render($template, $variables, $quantity);
        } catch (InvalidLabelValuesException $exception) {
            return $this->validationError($exception->getErrors());
        } catch (LabelException $exception) {
            return $this->validationError(['template' => $exception->getMessage()]);
        }

        if (PrinterTransport::ServerTcp === $printer->getTransport()) {
            try {
                $this->transport->send((string) $printer->getHost(), $printer->getPort(), $zpl);
            } catch (TransportException $exception) {
                return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
            }

            return new JsonResponse(['transport' => PrinterTransport::ServerTcp->value, 'status' => 'sent']);
        }

        return new JsonResponse([
            'transport' => PrinterTransport::LocalAgent->value,
            'zpl' => $zpl,
            'agentUrl' => $printer->getAgentUrl(),
            'host' => $printer->getHost(),
            'port' => $printer->getPort(),
        ]);
    }

    /** @param array<string, string> $errors */
    private function validationError(array $errors): JsonResponse
    {
        return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
