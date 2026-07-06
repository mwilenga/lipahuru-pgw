#!/usr/bin/env bash
set -euo pipefail

LIPAHURU_BASE="${LIPAHURU_BASE:-https://lipahuru-api.gotiketi.co.tz}"
CLIENT_ID="${CLIENT_ID:-cli_utdpprhengpiir1zlktz6noz}"
CLIENT_SECRET="${CLIENT_SECRET:-cs_qbpzXqpZHmDzUHDzjitEMr423iB4jk1nqea3yZel0gOA1dTG}"

new_uuid() {
  if command -v uuidgen >/dev/null 2>&1; then
    uuidgen | tr '[:upper:]' '[:lower:]'
  else
    python3 -c "import uuid; print(uuid.uuid4())"
  fi
}

sign_request() {
  local METHOD="$1"
  local REQUEST_PATH="$2"
  local BODY="$3"

  X_SIGNATURE=$(METHOD="$METHOD" REQUEST_PATH="$REQUEST_PATH" BODY="$BODY" CLIENT_SECRET="$CLIENT_SECRET" python3 <<'PY'
import base64, hashlib, hmac, os

method = os.environ["METHOD"]
path = os.environ["REQUEST_PATH"]
body = os.environ["BODY"]
secret = os.environ["CLIENT_SECRET"]

content_sha256 = base64.b64encode(hashlib.sha256(body.encode()).digest()).decode()
canonical = f"{method}\n{path}\n{content_sha256}"
signature = base64.b64encode(hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).digest()).decode()
print(signature)
PY
)
}

parse_json() {
  local KEY="$1"
  python3 -c "
import sys, json
data = json.load(sys.stdin)
for k in '${KEY}'.split('.'):
    data = data[k]
print(data)
"
}

fail_if_not_json() {
  local LABEL="$1"
  local RESP="$2"

  if ! echo "$RESP" | python3 -c "import sys,json; json.load(sys.stdin)" >/dev/null 2>&1; then
    echo "ERROR: $LABEL did not return JSON."
    echo ""
    echo "--- Raw response (first 800 chars) ---"
    echo "$RESP" | head -c 800
    echo ""
    echo ""
    echo "If you see an HTML error page, fix the API server first:"
    echo "  php artisan passport:keys --force"
    echo "  php artisan db:seed --force"
    echo "  php artisan config:cache"
    exit 1
  fi
}

echo "==> 1) OAuth token"
TOKEN_HTTP=$(curl -sS -w "\n%{http_code}" -X POST "$LIPAHURU_BASE/oauth/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials&client_id=${CLIENT_ID}&client_secret=${CLIENT_SECRET}")

TOKEN_BODY=$(echo "$TOKEN_HTTP" | sed '$d')
TOKEN_CODE=$(echo "$TOKEN_HTTP" | tail -n 1)

echo "HTTP $TOKEN_CODE"
fail_if_not_json "OAuth token" "$TOKEN_BODY"
echo "$TOKEN_BODY" | python3 -m json.tool
ACCESS_TOKEN=$(echo "$TOKEN_BODY" | parse_json access_token)

REQUEST_ID=$(new_uuid)
IDEMPOTENCY_KEY=$(new_uuid)

BODY=$(cat <<EOF
{"requestId":"${REQUEST_ID}","providerCode":"VODACOM","amount":100.00,"currency":"TZS","msisdn":"255768102956","reference":"INV-CURL-$(date +%s)","callbackUrl":"https://lipahuru-api.gotiketi.co.tz/internal/webhooks/godigital","narration":"curl test"}
EOF
)

echo "==> 2) C2B collection push"
sign_request "POST" "/api/v1/payments/collections/push" "$BODY"

CREATE_HTTP=$(curl -sS -w "\n%{http_code}" -X POST "$LIPAHURU_BASE/api/v1/payments/collections/push" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "X-Signature: ${X_SIGNATURE}" \
  -H "X-Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary "$BODY")

CREATE_BODY=$(echo "$CREATE_HTTP" | sed '$d')
CREATE_CODE=$(echo "$CREATE_HTTP" | tail -n 1)

echo "HTTP $CREATE_CODE"
if echo "$CREATE_BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); exit(0 if d.get('status')=='SUCCESS' else 1)" 2>/dev/null; then
  echo "$CREATE_BODY" | python3 -m json.tool
  TXN_ID=$(echo "$CREATE_BODY" | parse_json "data.transactionId")
else
  echo "$CREATE_BODY" | python3 -m json.tool
  exit 1
fi

echo "==> 3) Query ${TXN_ID}"
sign_request "GET" "/api/v1/payments/${TXN_ID}" ""

QUERY_HTTP=$(curl -sS -w "\n%{http_code}" -X GET "$LIPAHURU_BASE/api/v1/payments/${TXN_ID}" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" \
  -H "X-Signature: ${X_SIGNATURE}" \
  -H "Accept: application/json")

QUERY_BODY=$(echo "$QUERY_HTTP" | sed '$d')
QUERY_CODE=$(echo "$QUERY_HTTP" | tail -n 1)

echo "HTTP $QUERY_CODE"
fail_if_not_json "Transaction query" "$QUERY_BODY"
echo "$QUERY_BODY" | python3 -m json.tool
