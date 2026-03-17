#!/usr/bin/env bash

set -euo pipefail

REPO="1biot/fiquela-cli"
INSTALL_DIR="${HOME}/.fiquela-cli"
BIN_PATH="/usr/local/bin/fiquela-cli"
VERSION="${1:-latest}"

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Error: %s is not installed.\n' "$1" >&2
        exit 1
    fi
}

require_cmd curl
require_cmd tar
require_cmd php

if [ "$VERSION" = "latest" ]; then
    API_URL="https://api.github.com/repos/${REPO}/releases/latest"
else
    API_URL="https://api.github.com/repos/${REPO}/releases/tags/${VERSION}"
fi

echo "Fetching release metadata (${VERSION})..."
RELEASE_JSON="$(curl -fsSL "$API_URL")"

ASSET_URL="$(printf '%s' "$RELEASE_JSON" | grep -Eo 'https://[^\"]+fiquela-cli-[^\"]+-dist\.tar\.gz' | head -n 1)"

if [ -z "$ASSET_URL" ]; then
    echo "Error: Release artifact fiquela-cli-<tag>-dist.tar.gz not found." >&2
    echo "Create a GitHub release with built assets first." >&2
    exit 1
fi

TMP_DIR="$(mktemp -d)"
ARCHIVE_PATH="${TMP_DIR}/fiquela-cli-dist.tar.gz"
EXTRACT_DIR="${TMP_DIR}/extract"

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

echo "Downloading artifact..."
curl -fL "$ASSET_URL" -o "$ARCHIVE_PATH"

mkdir -p "$EXTRACT_DIR"
tar -xzf "$ARCHIVE_PATH" -C "$EXTRACT_DIR"

EXTRACTED_ROOT="$(find "$EXTRACT_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [ -z "$EXTRACTED_ROOT" ]; then
    echo "Error: Invalid archive format." >&2
    exit 1
fi

echo "Installing to ${INSTALL_DIR}..."
rm -rf "$INSTALL_DIR"
mkdir -p "$(dirname "$INSTALL_DIR")"
mv "$EXTRACTED_ROOT" "$INSTALL_DIR"

TARGET_BIN="${INSTALL_DIR}/bin/fiquela-cli"
if [ ! -f "$TARGET_BIN" ]; then
    echo "Error: Installed package does not contain bin/fiquela-cli." >&2
    exit 1
fi
chmod +x "$TARGET_BIN"

if [ -w "$(dirname "$BIN_PATH")" ]; then
    ln -sf "$TARGET_BIN" "$BIN_PATH"
    echo "Linked binary to ${BIN_PATH}"
elif command -v sudo >/dev/null 2>&1; then
    sudo ln -sf "$TARGET_BIN" "$BIN_PATH"
    echo "Linked binary to ${BIN_PATH} (via sudo)"
else
    USER_BIN_DIR="${HOME}/.local/bin"
    mkdir -p "$USER_BIN_DIR"
    ln -sf "$TARGET_BIN" "${USER_BIN_DIR}/fiquela-cli"
    echo "Linked binary to ${USER_BIN_DIR}/fiquela-cli"
    echo "Add ${USER_BIN_DIR} to your PATH if needed."
fi

echo "FiQueLa CLI was installed successfully."
echo "Run: fiquela-cli --help"
