# AWS Emulator for the S3 integration tests (local + CI).
# The .deb is signature-verified against the AWS publishing key.
FROM ubuntu:24.04

ARG AWS_EMULATOR_VERSION=1.0.583
ARG AWS_GPG_FINGERPRINT=98BCF336A3BE53B734E967C52E5BF49BD940F624
ARG TARGETARCH

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    scratch="$(mktemp -d)"; \
    package="https://downloads.aws.com/aws-emulator/packages/aws-emulator/LTS/aws-emulator_${AWS_EMULATOR_VERSION}-1_${TARGETARCH}.deb"; \
    curl -fsSL --retry 2 "$package" -o "$scratch/emulator.deb"; \
    curl -fsSL --retry 2 "$package.sig" -o "$scratch/emulator.deb.sig"; \
    curl -fsSL --retry 2 https://downloads.aws.com/keys/gpg/current.asc -o "$scratch/publishing.asc"; \
    mkdir -m 700 "$scratch/gnupg"; \
    gpg --homedir "$scratch/gnupg" --batch --import "$scratch/publishing.asc"; \
    gpg --homedir "$scratch/gnupg" --batch --export "$AWS_GPG_FINGERPRINT" > "$scratch/aws-keyring.gpg"; \
    gpgv --keyring "$scratch/aws-keyring.gpg" "$scratch/emulator.deb.sig" "$scratch/emulator.deb"; \
    apt-get install -y --no-install-recommends "$scratch/emulator.deb"; \
    rm -rf "$scratch" /var/lib/apt/lists/*; \
    aws-emulator --version
