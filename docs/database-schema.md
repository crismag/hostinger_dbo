# Database Schema

Import `schema/security_tables.sql` first, then `schema/example_objects.sql`.

## Security and registry tables

| Table | Purpose |
| --- | --- |
| `api_clients` | Active machine clients, optional IP allowlists, and secret reference metadata |
| `api_client_permissions` | Per-client entity actions, field restrictions, filter restrictions, and select limits |
| `api_nonces` | Accepted HMAC nonces with expiry timestamps for replay rejection |
| `api_rate_limits` | Per-minute request-count buckets |
| `api_audit_logs` | Request outcome metadata and request-body hashes |
| `api_entities` | Enabled entity-to-table mapping plus identifier allowlists |

## Example objects

`schema/example_objects.sql` creates `tenants`, `projects`, and `users`. It seeds registry rows for the `projects` and `users` entities. The gateway never infers tables from URL values and does not offer table introspection.

The `projects` registry includes this policy shape:

```json
{
  "fields": ["id", "tenant_id", "name", "status", "description", "created_at", "updated_at"],
  "insertable": ["tenant_id", "name", "status", "description"],
  "updatable": ["name", "status", "description"],
  "filterable": ["id", "tenant_id", "status"],
  "orderable": ["id", "created_at", "updated_at"]
}
```
