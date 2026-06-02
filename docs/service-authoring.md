# Authoring Service Operations

Service operations are the supported home for logic the generic gateway intentionally does **not** expose — joins, multi-table reports, aggregated dashboards, and multi-write transactions. This guide covers how to write one safely.

See also: [API Reference → Service operations](api-reference.md#service-operations).

## When to use a service operation

Use one when the work needs more than a single-entity object operation:

- a **JOIN** or any multi-table query,
- aggregation with **`HAVING`**, window functions, or expressions,
- a **transaction** spanning several writes (e.g. create order + items + total),
- a **report** combining several tables,
- business logic the caller should not be able to compose itself.

## When *not* to use one

Prefer the generic gateway when the operation is single-entity and already supported:

- plain CRUD (`select`/`insert`/`update`/`delete`),
- equality filters + `LIKE` search,
- sorting and pagination,
- `GROUP BY` with `count/sum/avg/min/max` on **one** entity.

If a registered entity + the query controls already do it, do not write a service operation.

## The safety model (why this is allowed to be complex)

The gateway forbids **caller-supplied** SQL because callers are untrusted. A service operation is different: the SQL *shape* is **committed, reviewed code**; only the *parameters* come from the authenticated caller, and they are validated first. So a handler may run arbitrary JOINs/transactions — the structure is trusted, the values are bound.

Two rules make this hold, and they are non-negotiable:

1. **Handlers resolve only through the allowlist.** A handler must be registered in `App\Services\Operations\OperationRegistry::MAP` (a compile-time array). Class names are never read from config or the database. This is the code-execution counterpart of the gateway's SQL-identifier allowlisting.
2. **No caller input reaches SQL unbound.** Every value goes through a bound parameter; every dynamic identifier comes from trusted code, never from the request.

## Writing a handler

A handler implements `App\Services\Operations\ServiceOperation`:

```php
namespace App\Services\Operations\Reports;

use App\Services\Operations\ServiceContext;
use App\Services\Operations\ServiceOperation;
use PDO;

final class TicketSummary implements ServiceOperation
{
    public function inputSpec(): array
    {
        // Accepted caller parameters. Unknown keys and out-of-range values are
        // rejected with SERVICE_INPUT_INVALID before execute() runs.
        return ['limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 500]];
    }

    public function execute(array $input, ServiceContext $context): array
    {
        $limit = (int) ($input['limit'] ?? 50);

        // Honour the client's enforced scope (tenant_id, …). Empty for unscoped clients.
        $params = [];
        $scope = $context->bindScopedWhere([], $params, 't');   // -> "t.`tenant_id` = :scope_0" or ""
        $where = $scope !== '' ? ' WHERE ' . $scope : '';

        $sql = 'SELECT t.`status`, COUNT(*) AS n'
             . ' FROM `tickets` t' . $where
             . ' GROUP BY t.`status` HAVING COUNT(*) > 0'
             . ' ORDER BY n DESC LIMIT :limit';

        $stmt = $context->pdo()->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
```

Then:

1. Add it to the **allowlist** in `OperationRegistry`: `'reports.ticket_summary' => TicketSummary::class`.
2. Map the URL to the key in `config/services.php`:
   ```php
   'reports' => ['ticket_summary' => ['handler' => 'reports.ticket_summary']],
   ```
3. Grant clients the operation key under `config/security.php` → `clients[clientId]['services']`.

The route is then `POST /api/v1/services/reports/ticket_summary`.

## `inputSpec()` validation

Declare every accepted parameter. The framework, before calling `execute()`:

- **rejects unknown keys** in the request body (`SERVICE_INPUT_INVALID`),
- enforces `required`,
- coerces and checks `type` (`int`, `bool`, `string`); `int` honours `min`/`max`,
- rejects non-scalar values.

Anything not in the spec never reaches your handler.

## Tenant scope is your responsibility — use the helper

The framework does **not** automatically scope a handler's queries. If your handler reads tenant-owned data, apply the client's enforced scope with `ServiceContext`:

| Helper | Use |
| --- | --- |
| `bindScopedWhere($base, $params, $alias)` | Returns a bound SQL `WHERE` fragment (no `WHERE` keyword) for the scoped filters; `''` if unscoped. Best for handlers writing SQL. |
| `scopedWhere($base)` | Returns the merged `field => value` map; throws `TENANT_SCOPE_VIOLATION` on conflict. |
| `enforceScopeOrFail($callerWhere)` | Same as `scopedWhere`, named for validating a caller-supplied `where`. |

Conflicts (a base/caller value that differs from the enforced value) raise `TENANT_SCOPE_VIOLATION` — a handler cannot be tricked into widening scope. For an unscoped client these return empty, yielding a full cross-tenant report. **Always** route tenant-owned reads through one of these.

## Transactions

A handler may wrap multiple writes in a transaction. Always pair begin/commit with a rollback on failure:

```php
$pdo = $context->pdo();
$pdo->beginTransaction();
try {
    // ... several INSERT/UPDATE with bound params ...
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
return ['ok' => true];
```

Never leave a transaction open across the return.

## Do / Don't

**Do**
- Register the class in `OperationRegistry`.
- Declare all input in `inputSpec()`.
- Bind every value; write identifiers as literal code.
- Apply tenant scope via `ServiceContext`.
- Keep one operation focused; return structured arrays.

**Don't**
- Build SQL from caller input (entity names, columns, operators, fragments).
- Read the handler class name from config or the database.
- Skip `inputSpec()` and read `$input` keys directly.
- Forget scope on tenant-owned data.
- Expose generic JOIN/raw-SQL capability to callers — that is exactly what this layer exists to avoid.
