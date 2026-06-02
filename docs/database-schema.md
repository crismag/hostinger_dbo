# Database Schema

The [installer](installation.md) loads these schema files automatically (skipping any tables that already exist), choosing the driver-appropriate variant: MySQL uses `schema/*.sql`; SQLite uses `schema/sqlite/*.sql` (same tables/columns, SQLite DDL, `schema_json` stored as TEXT). To load them manually, import `security_tables.sql` first, then `example_objects.sql` from the matching directory.

## Security and registry tables

| Table | Purpose |
| --- | --- |
| `api_clients` | Active machine clients, optional IP allowlists, and secret metadata (`secret_hash` stores plaintext only when DB fallback is enabled) |
| `api_client_permissions` | Per-client entity actions, field restrictions, filter restrictions, and select limits |
| `api_nonces` | Accepted HMAC nonces with expiry timestamps for replay rejection |
| `api_rate_limits` | Per-minute request-count buckets |
| `api_audit_logs` | Request outcome metadata and request-body hashes |
| `api_entities` | Enabled entity-to-table mapping plus identifier allowlists |

## Example objects

`schema/example_objects.sql` creates `tenants`, `projects`, and `users`. It seeds registry rows for the `projects` and `users` entities. The gateway never infers tables from URL values and does not offer table introspection.

The `projects` registry includes this policy shape (the `is_demo` column flags rows that are safe to expose through the optional public demo accessor):

```json
{
  "fields": ["id", "tenant_id", "name", "status", "description", "is_demo", "created_at", "updated_at"],
  "insertable": ["tenant_id", "name", "status", "description", "is_demo"],
  "updatable": ["name", "status", "description"],
  "filterable": ["id", "tenant_id", "status", "is_demo"],
  "orderable": ["id", "created_at", "updated_at"],
  "searchable": ["name", "description"],
  "groupable": ["status", "tenant_id", "is_demo"],
  "aggregatable": ["id"]
}
```

The last three keys are optional and govern the query controls (see the [API Reference](api-reference.md)):

- **`searchable`** — fields permitted in `like` filters.
- **`groupable`** — fields permitted in `group_by`.
- **`aggregatable`** — fields permitted as `sum`/`avg`/`min`/`max`/`count` targets.

Register your own objects by inserting an `api_entities` row with `entity_name`, `table_name`, `primary_key_name`, and a `schema_json` policy of the same shape. Only the identifiers listed there are reachable; everything else is rejected. Omitting `searchable`/`groupable`/`aggregatable` simply disables those controls for the entity.
