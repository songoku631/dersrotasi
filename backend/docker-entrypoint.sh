#!/bin/sh
set -e

PORT="${PORT:-8080}"

case "$PORT" in
  ''|*[!0-9]*)
    echo "PORT pozitif bir tam sayı olmalıdır." >&2
    exit 1
    ;;
esac

if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
  echo "PORT 1 ile 65535 arasında olmalıdır." >&2
  exit 1
fi

sed -ri "s/^Listen[[:space:]]+[0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:80>#<VirtualHost *:${PORT}>#g" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
