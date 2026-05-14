#!/usr/bin/env bash

set -euo pipefail

REPO="1biot/fiquela-cli"
BIN_PATH="/usr/local/bin/fiquela-cli"
VERSION="${1:-latest}"
CDN_BASE="https://cdn.fiquela.io/fiquela-cli"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Error: %s is not installed.\n' "$1" >&2
        exit 1
    fi
}

require_cmd curl
require_cmd php

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    printf 'Error: PHP >= 8.2 is required (found %s).\n' "$PHP_VERSION" >&2
    exit 1
fi

if [ "$VERSION" = "latest" ]; then
    API_URL="https://api.github.com/repos/${REPO}/releases/latest"
    echo "Fetching latest release tag from GitHub..."
    RELEASE_JSON="$(curl -fsSL "$API_URL")"
    TAG="$(printf '%s' "$RELEASE_JSON" | grep -Eo '"tag_name"[[:space:]]*:[[:space:]]*"[^"]+"' | head -n 1 | sed -E 's/.*"tag_name"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')"
    if [ -z "$TAG" ]; then
        echo "Error: Could not determine latest release tag from GitHub API." >&2
        exit 1
    fi
else
    TAG="$VERSION"
fi

ASSET_URL="${CDN_BASE}/${TAG}/fiquela-cli.phar"
echo "Resolved download URL: ${ASSET_URL}"

TMP_FILE="$(mktemp).phar"

cleanup() {
    rm -f "$TMP_FILE"
}
trap cleanup EXIT

echo "Downloading fiquela-cli.phar..."
curl -fL "$ASSET_URL" -o "$TMP_FILE"

if [ ! -s "$TMP_FILE" ]; then
    echo "Error: Downloaded file is empty." >&2
    exit 1
fi

# Verify the PHAR is valid PHP
if ! php -r "new Phar('$TMP_FILE');" 2>/dev/null; then
    echo "Error: Downloaded file is not a valid PHAR." >&2
    exit 1
fi

# Remove old symlink or file to avoid write issues
if [ -L "$BIN_PATH" ] || [ -f "$BIN_PATH" ]; then
    if [ -w "$(dirname "$BIN_PATH")" ]; then
        rm -f "$BIN_PATH"
    elif command -v sudo >/dev/null 2>&1; then
        sudo rm -f "$BIN_PATH"
    fi
fi

if [ -w "$(dirname "$BIN_PATH")" ]; then
    mv "$TMP_FILE" "$BIN_PATH"
    chmod +x "$BIN_PATH"
    echo "Installed to ${BIN_PATH}"
elif command -v sudo >/dev/null 2>&1; then
    sudo mv "$TMP_FILE" "$BIN_PATH"
    sudo chmod +x "$BIN_PATH"
    echo "Installed to ${BIN_PATH} (via sudo)"
else
    USER_BIN_DIR="${HOME}/.local/bin"
    mkdir -p "$USER_BIN_DIR"
    mv "$TMP_FILE" "${USER_BIN_DIR}/fiquela-cli"
    chmod +x "${USER_BIN_DIR}/fiquela-cli"
    echo "Installed to ${USER_BIN_DIR}/fiquela-cli"
    echo "Add ${USER_BIN_DIR} to your PATH if needed."
fi

echo "FiQueLa CLI was installed successfully."
echo "Run: fiquela-cli --help"
echo "Update: fiquela-cli self-update"
