<?php

declare(strict_types=1);

namespace App\Controller\Manage;

use App\Controller\AbstractApiController;
use App\Repository\FeatureValueRepository;
use App\Service\AuthService;
use App\Service\EnvironmentService;
use App\Service\FeatureService;
use App\Service\FeatureValueService;
use App\Service\Manage\Request\FeatureValueRequest;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FeatureValueController extends AbstractApiController implements ManageTokenAuthenticatedController
{
    public function __construct(
        private AuthService $authService,
        private FeatureService $featureService,
        private EnvironmentService $environmentService,
        private FeatureValueRepository $featureValueRepository,
        private FeatureValueService $featureValueService,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/feature/{name}/value', name: 'setFeatureValue', methods: ['POST'])]
    #[ParamConverter('featureValueRequest', class: FeatureValueRequest::class, converter: 'fos_rest.request_body')]
    public function setFeatureValue(string $name, FeatureValueRequest $featureValueRequest): JsonResponse
    {
        $project = $this->authService->getProjectByManageKeyAndReference();
        $feature = $this->featureService->getFeature($project, $name);
        if (!$feature) {
            return $this->respondNotFound();
        }

        $environment = $this->environmentService->getEnvironment($project, $featureValueRequest->getEnvironment());
        if (!$environment) {
            return $this->respondNotFound();
        }

        $validationErrors = $this->validator->validate($featureValueRequest);
        if ($validationErrors->count() > 0) {
            $errors = $this->getErrorMessages($validationErrors);

            return $this->respondValidationError($errors);
        }

        $featureValue = $this->featureValueRepository->findOneByFeatureAndEnvironment($feature, $environment);
        if (!$featureValue) {
            return $this->respondJsonError(
                Response::HTTP_NOT_FOUND,
                sprintf(self::ERROR_VALUE_NOT_SET_FOR_ENV, $feature->getId(), $environment->getName())
            );
        }

        $featureValue = $this->featureValueService->updateFeatureValue($featureValue, $featureValueRequest);

        return $this->createApiResponse([
            'status' => 'ok',
            'feature' => $feature->getName(),
            'environment' => $environment->getName(),
            'enabled' => $featureValue->isEnabled(),
        ]);
    }
}
