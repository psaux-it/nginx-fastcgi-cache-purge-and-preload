#!/bin/sh
# Local CA + a certificate for api.cloudflare.com (harness only). Output: $1 (default ./certs)
D=${1:-./certs}; mkdir -p "$D" && cd "$D" || exit 1
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.pem -days 30 -subj "/CN=nppp-harness-ca" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -keyout api.key -out api.csr -subj "/CN=api.cloudflare.com" 2>/dev/null
printf 'subjectAltName=DNS:api.cloudflare.com\nbasicConstraints=CA:FALSE\n' > ext.cnf
openssl x509 -req -in api.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out api.pem -days 30 -extfile ext.cnf 2>/dev/null
echo "certs in $(pwd)"
