# Linux cron + WP-CLI sidecar for local staging.
#
# This exists to prove the real production job-scheduling mechanism end to
# end (Linux cron invoking WP-CLI commands directly, per ARCHITECTURE.md
# §7 — WP-Cron is never used). Base image already ships WP-CLI and Alpine's
# busybox crond, so no extra cron package install is needed.
FROM wordpress:cli-php8.3

USER root

RUN mkdir -p /var/log/tube-cron

COPY ops/cron/staging.cron /etc/crontabs/root

CMD ["crond", "-f", "-l", "8"]
