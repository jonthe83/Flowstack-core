#!/bin/sh
# Skapa en testdokument-länk (ersätt nyckel om du bytt från change-me).
# curl -sS -X POST 'https://flowstack.example.test/fullmakt/api/create.php' \
#   -H 'Content-Type: application/json' \
#   -H 'X-Fullmakt-Key: change-me' \
#   -d '{
#     "namn": "Anna Andersson",
#     "personnummer": "19800101-1234",
#     "telefon": "070-123 45 67",
#     "epost": "kontakt@jarfalla.example.test",
#     "adress": "Testvägen 1",
#     "postnummer": "176 76",
#     "ort": "Järfälla",
#     "typ": "privatperson"
#   }'
echo "Se kommentarerna i denna fil för curl-exemplet."
