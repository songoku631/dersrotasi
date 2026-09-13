#!/bin/bash
set -euo pipefail

PROJECT_ID="project-45a43d3b-b230-4b9d-b3f"
SECRET_NAME="dersrotasi-turn-shared-secret"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq coturn curl ca-certificates jq

TOKEN="$(curl -fsS -H 'Metadata-Flavor: Google' 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token' | jq -r .access_token)"
SECRET="$(curl -fsS -H "Authorization: Bearer ${TOKEN}" "https://secretmanager.googleapis.com/v1/projects/${PROJECT_ID}/secrets/${SECRET_NAME}/versions/latest:access" | jq -r .payload.data | base64 -d)"
EXTERNAL_IP="$(curl -fsS -H 'Metadata-Flavor: Google' 'http://metadata.google.internal/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip')"

install -m 600 /dev/null /etc/turnserver.conf
cat > /etc/turnserver.conf <<EOF
listening-port=3478
fingerprint
use-auth-secret
static-auth-secret=${SECRET}
realm=turn.dersrotasi.com
server-name=turn.dersrotasi.com
external-ip=${EXTERNAL_IP}
min-port=49152
max-port=49251
no-tls
no-dtls
no-cli
no-multicast-peers
stale-nonce=600
total-quota=200
user-quota=12
simple-log
EOF
unset SECRET TOKEN

sed -i 's/^TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
systemctl enable coturn
systemctl restart coturn
