#syntax=docker/dockerfile:1

# Base images are built daily in https://github.com/FetchDock/fetchdock-base-images

# Base stage for dev and build
FROM ghcr.io/fetchdock/fetchdock-base-images/node-pwa:lts AS base
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

WORKDIR /srv/app


# Development image
FROM base AS dev
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

EXPOSE 3000
ENV PORT=3000
ENV HOSTNAME=localhost

CMD ["sh", "-c", "pnpm install; pnpm dev"]


FROM base AS builder
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

COPY --link pnpm-lock.yaml ./
COPY --link . .

# Install devDependencies too: they're needed to build (e.g. @types/node, an optional peer of sharp >= 0.35
# that next depends on; without it, prerendering fails with "NextRouter was not mounted", see #66).
# They don't end up in the prod image, which only copies the standalone output.
RUN pnpm install --frozen-lockfile && \
	pnpm run build


# Production image, copy all the files and run next
FROM ghcr.io/fetchdock/fetchdock-base-images/node-pwa-runtime:lts AS prod
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

WORKDIR /srv/app

COPY --from=builder --link /srv/app/public ./public

# Set the correct permission for prerender cache
RUN mkdir .next && \
	chown nextjs:nodejs .next

# Automatically leverage output traces to reduce image size
# https://nextjs.org/docs/advanced-features/output-file-tracing
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/standalone ./
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/static ./.next/static

# nextjs:nodejs, created in node-pwa-runtime. Numeric so it also resolves without the image's /etc/passwd (hadolint DL3066).
USER 1001:1001
