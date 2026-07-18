curl -X POST https://lipahuru-api.gotiketi.co.tz/internal/webhooks/godigital \
  -H "Content-Type: application/json" \
  -d '{
    "eventType": "PAYMENT_FINALIZED",
    "data": {
      "transactionId": "INV-CURL-1783321093",
      "transactionStatus": "SUCCESS",
      "providerTransactionId": "GD26070606570673204",
      "providerReceiptNo": "DG62J1OUJP"
    }
  }'