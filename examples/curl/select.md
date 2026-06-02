# Signed project select

```bash
body='{"where":{"tenant_id":"tenant_001","status":"active"},"fields":["id","name","status","created_at"],"limit":50,"offset":0,"order_by":"created_at","order_dir":"desc"}'
path='/api/v1/projects/select'
timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
nonce=$(php -r 'echo bin2hex(random_bytes(16));')
body_hash=$(printf %s "$body" | sha256sum | cut -d' ' -f1)
canonical=$(printf 'POST\n%s\n%s\n%s\n%s' "$path" "$timestamp" "$nonce" "$body_hash")
signature=$(printf %s "$canonical" | openssl dgst -sha256 -hmac "$API_CLIENT_SECRET" -hex | awk '{print $NF}')
curl -X POST "https://example.com$path" -H 'Content-Type: application/json' -H "X-Client-Id: $API_CLIENT_ID" -H "X-Timestamp: $timestamp" -H "X-Nonce: $nonce" -H "X-Signature: $signature" -d "$body"
```
