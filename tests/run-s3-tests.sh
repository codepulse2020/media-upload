#!/usr/bin/env bash
# Runs the test suite with the S3 integration tests enabled, against AWS
# Emulator in Docker (docker/aws-emulator/Dockerfile). Same script in CI.
#
#   bash tests/run-s3-tests.sh                 # whole suite
#   bash tests/run-s3-tests.sh --filter=S3     # extra args go to Pest
set -euo pipefail

cd "$(dirname "$0")/.."

image="${AWS_EMULATOR_IMAGE:-media-upload-aws-emulator}"
container="media-upload-aws-emulator-$$"
state="$(mktemp -d)"

cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    rm -rf "$state"
}
trap cleanup EXIT

if [[ -z "${AWS_EMULATOR_SKIP_BUILD:-}" ]]; then
    docker build --quiet --tag "$image" docker/aws-emulator >/dev/null
fi

# The emulator's TLS certificate is issued for 127.0.0.1, so publish on loopback.
docker run --detach --name "$container" --publish 127.0.0.1::9297 "$image" \
    aws-emulator --bind 0.0.0.0 --port 9297 --auth on --telemetry off --idle-timeout 600 >/dev/null

ready=''
for ((attempt = 0; attempt < 100; attempt++)); do
    if docker logs "$container" 2>&1 | grep -q '"emulator server information"'; then
        ready=1
        break
    fi
    if [[ "$(docker inspect -f '{{.State.Running}}' "$container")" != 'true' ]]; then
        break
    fi
    sleep 0.2
done

if [[ -z "$ready" ]]; then
    docker logs "$container" >&2
    exit 1
fi

docker cp "$container:/root/.aws/emulator/9297/ca.pem" "$state/ca.pem" >/dev/null
docker cp "$container:/root/.aws/emulator/9297/emulator_credentials" "$state/emulator_credentials" >/dev/null

endpoint="https://$(docker port "$container" 9297/tcp | head -n1)"
curl --fail --silent --show-error --noproxy '*' --max-time 5 --cacert "$state/ca.pem" "$endpoint/__admin/health" >/dev/null

export MEDIA_UPLOAD_S3_ENDPOINT="$endpoint"
export MEDIA_UPLOAD_S3_CA="$state/ca.pem"
export MEDIA_UPLOAD_S3_CREDENTIALS="$state/emulator_credentials"

vendor/bin/pest "$@"
