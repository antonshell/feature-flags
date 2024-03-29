<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractApiController
{
    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'feature flag service',
        ]);
    }
}
