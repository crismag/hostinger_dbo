# Signed project update

Use the signing steps in [select.md](select.md) with path `/api/v1/projects/update` and this JSON body:

```json
{"where":{"id":123,"tenant_id":"tenant_001"},"data":{"status":"archived"}}
```
