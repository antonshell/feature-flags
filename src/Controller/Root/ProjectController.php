<?php

declare(strict_types=1);

namespace App\Controller\Root;

use App\Controller\AbstractApiController;
use App\Repository\ProjectRepository;
use App\Service\ProjectService;
use App\Service\Root\Request\ProjectRequest;
use App\Service\Root\Serializer\ProjectSerializer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProjectController extends AbstractApiController implements RootTokenAuthenticatedController
{
    public function __construct(
        private ProjectService $projectService,
        private ProjectRepository $projectRepository,
        private ProjectSerializer $projectSerializer,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/manage/projects', name: 'getAllProjects')]
    public function getAll(Request $request): JsonResponse
    {
        $projects = $this->projectRepository->findAll();
        $data = $this->projectSerializer->serializeArray($projects);

        return $this->createApiResponse($data);
    }

    #[Route('/manage/project/{id}', name: 'getProject', methods: ['GET'])]
    public function getById(int $id): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->respondNotFound();
        }

        $data = $this->projectSerializer->serializeItem($project);

        return $this->createApiResponse($data);
    }

    #[Route('/manage/project', name: 'createProject', methods: ['POST'])]
    #[ParamConverter('projectRequest', class: ProjectRequest::class, converter: 'fos_rest.request_body')]
    public function create(ProjectRequest $projectRequest): JsonResponse
    {
        $validationErrors = $this->validator->validate($projectRequest);
        if ($validationErrors->count() > 0) {
            $errors = $this->getErrorMessages($validationErrors);

            return $this->respondValidationError($errors);
        }

        try {
            $projectCredentials = $this->projectService->createCredentials();
            $project = $this->projectService->createProject($projectRequest, $projectCredentials);
            $data = $this->projectSerializer->serializeItem($project, $projectCredentials);

            return $this->createApiResponse($data);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->respondDuplicateError();
        }
    }

    #[Route('/manage/project/{id}', name: 'updateProject', methods: ['POST'])]
    #[ParamConverter('projectRequest', class: ProjectRequest::class, converter: 'fos_rest.request_body')]
    public function update(int $id, ProjectRequest $projectRequest): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->respondNotFound();
        }

        $validationErrors = $this->validator->validate($projectRequest);
        if ($validationErrors->count() > 0) {
            $errors = $this->getErrorMessages($validationErrors);

            return $this->respondValidationError($errors);
        }

        try {
            $project = $this->projectService->updateProject($project, $projectRequest);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->respondDuplicateError();
        }

        $project = $this->projectService->updateProject($project, $projectRequest);
        $data = $this->projectSerializer->serializeItem($project);

        return $this->createApiResponse($data);
    }

    #[Route('/manage/project/{id}', name: 'deleteProject', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->respondNotFound();
        }

        $this->projectService->delete($project);

        return new JsonResponse('', Response::HTTP_NO_CONTENT);
    }
}
