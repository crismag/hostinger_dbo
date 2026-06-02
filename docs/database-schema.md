# Database Schema

The [installer](installation.md) loads these schema files automatically (skipping any tables that already exist). To load them manually, import `schema/security_tables.sql` first, then `schema/example_objects.sql`.

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
  "orderable": ["id", "created_at", "updated_at"]
}
```

Register your own objects by inserting an `api_entities` row with `entity_name`, `table_name`, `primary_key_name`, and a `schema_json` policy of the same shape. Only the identifiers listed there are reachable; everything else is rejected.
