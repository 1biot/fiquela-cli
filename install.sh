#!/bin/bash

set -e

# Configuration
REPO_URL="https://github.com/1biot/fiquela-cli.git"
INSTALL_DIR="$HOME/.fiquela-cli"
BIN_PATH="/usr/local/bin/fiquela-cli"

# Check for required dependencies
if ! command -v git &> /dev/null; then
    echo "Error: git is not installed. Please install git and try again."
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "Error: Composer is not installed. Please install Composer and try again."
    exit 1
fi

if ! command -v php &> /dev/null; then
    echo "Error: PHP is not installed. Please install PHP (8.1+) and try again."
    exit 1
fi

# Clone the repository
if [ -d "$INSTALL_DIR" ]; then
    echo "Updating existing installation..."
    cd "$INSTALL_DIR"
    git pull origin main
else
    echo "Cloning FiQueLa CLI repository..."
    git clone "$REPO_URL" "$INSTALL_DIR"
fi

# Install dependencies
cd "$INSTALL_DIR"
composer install --no-dev --optimize-autoloader

# Make the script globally accessible
if [[ "$OSTYPE" == "darwin"* ]]; then
    echo "Detected macOS, setting up symlink in /usr/local/bin"
    sudo ln -sf "$INSTALL_DIR/bin/fiquela-cli" "$BIN_PATH"
else
    ln -sf "$INSTALL_DIR/bin/fiquela-cli" "$BIN_PATH"
fi

chmod +x "$BIN_PATH"

echo "FiQueLa CLI has been successfully installed!"
echo "You can now use it by running: fiquela-cli"
