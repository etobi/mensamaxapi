#!/bin/sh
# Runs "bin/mensamax publish" once or in an endless loop every PUBLISH_INTERVAL seconds.
set -u

if [ "${1:-loop}" != "loop" ]; then
    exec /app/bin/mensamax "$@"
fi

interval="${PUBLISH_INTERVAL:-3600}"
echo "mensamax-api: publishing every ${interval}s"
while true; do
    /app/bin/mensamax publish || echo "mensamax-api: publish failed with exit code $?"
    sleep "$interval"
done
