<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ObjectService;
use App\Validation\EntitySchema;

/** Final HTTP adapter for already authenticated and validated object actions. */
final class ObjectController
{
    public function __construct(private readonly ObjectService $objects)
    {
    }

    public function handle(Request $request): Response
    {
        /** @var EntitySchema $schema */
        $schema = $request->attribute('schema');
        /** @var array<string, mixed> $validated */
        $validated = $request->attribute('validated');
        $action = (string) $request->attribute('action');
        $status = $action === 'insert' ? 201 : 200;

        return Response::success($this->objects->execute($schema, $action, $validated), (string) $request->attribute('request_id'), $status);
    }
}
