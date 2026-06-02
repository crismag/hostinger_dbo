<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * A named service operation — developer-authored business/reporting logic that
 * the generic gateway intentionally does not support (joins, multi-table
 * reports, transactions). The query *shape* is committed code; only the
 * parameters come from the authenticated caller, and they are validated against
 * inputSpec() before execute() runs.
 */
interface ServiceOperation
{
    /**
     * @param array<string, mixed> $input caller parameters, already validated against inputSpec()
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function execute(array $input, ServiceContext $context): array;

    /**
     * Declares accepted input parameters. Shape:
     *   ['limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 500]]
     * Supported types: string, int, bool. Unknown keys in the request are rejected.
     *
     * @return array<string, array{type:string,required?:bool,min?:int,max?:int}>
     */
    public function inputSpec(): array;
}
