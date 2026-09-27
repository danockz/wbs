#!/usr/bin/env bash
# Ensure a usable PHP 8.4 CLI exists. ~/.local is EXCLUDED from workspace
# snapshots, so the static php binary is wiped between turns — call this at the
# start of a session, then `export PATH="$HOME/.local/bin:$PATH"`.
set -euo pipefail
if [ -x "$HOME/.local/bin/php" ]; then
  echo "php: $($HOME/.local/bin/php -r 'echo PHP_VERSION;')"
  exit 0
fi
mkdir -p "$HOME/.local/bin"
tmp="$(mktemp -d)"
curl -sSL "https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz" -o "$tmp/php.tgz"
tar xzf "$tmp/php.tgz" -C "$tmp"
mv -f "$tmp/php" "$HOME/.local/bin/php"
chmod +x "$HOME/.local/bin/php"
rm -rf "$tmp"
echo "php: $($HOME/.local/bin/php -r 'echo PHP_VERSION;') (fetched)"
