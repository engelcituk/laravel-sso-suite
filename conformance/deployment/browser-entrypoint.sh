#!/bin/bash
set -eu
export NODE_EXTRA_CA_CERTS=/tls/ca.pem
cp /tls/ca.pem /usr/local/share/ca-certificates/sso-laboratory.crt
update-ca-certificates >/dev/null 2>&1
mkdir -p /root/.pki/nssdb
certutil -N -d sql:/root/.pki/nssdb --empty-password
certutil -A -d sql:/root/.pki/nssdb -n sso-laboratory -t 'C,,' -i /tls/ca.pem
printf '%s\n' '{"policies":{"Certificates":{"Install":["/tls/ca.pem"]}}}' > /tmp/sso-firefox-policies.json
export PLAYWRIGHT_FIREFOX_POLICIES_JSON=/tmp/sso-firefox-policies.json
if [ "${1:-}" = "--modes" ]; then
    shift
    exec node /app/tools/deployment-mode-browser.cjs "$@"
fi
exec node /app/tools/deployment-browser.cjs "$@"
