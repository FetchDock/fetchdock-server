#syntax=docker/dockerfile:1

# Versions
FROM node:lts AS node_upstream
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}
ENV PATH=${PATH}:/root/.local/share/pnpm/bin


# Base stage for dev and build
FROM node_upstream AS base
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}
ENV PATH=${PATH}:/root/.local/share/pnpm/bin

WORKDIR /srv/app

#RUN corepack enable && \
#	corepack prepare pnpm@latest --activate && \
#	mkdir -p /pnpm && \
#	pnpm config set store-dir /.pnpm-store

RUN npm install -g corepack@latest && \
    corepack enable && \
	corepack prepare --activate pnpm@latest && \
	pnpm config -g set store-dir /.pnpm-store

# Next.js collects completely anonymous telemetry data about general usage.
# Learn more here: https://nextjs.org/telemetry
# Delete the following line in case you want to enable telemetry during dev and build.
ENV NEXT_TELEMETRY_DISABLED=1


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
RUN	pnpm install --frozen-lockfile && \
	pnpm run build


# Production image, copy all the files and run next
FROM node_upstream AS prod
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

WORKDIR /srv/app

ENV NODE_ENV=production
# Delete the following line in case you want to enable telemetry during runtime.
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --gid 1001 nodejs; \
	adduser --uid 1001 --gid 1001 nextjs

COPY --from=builder --link /srv/app/public ./public

# Set the correct permission for prerender cache
RUN mkdir .next; \
	chown nextjs:nodejs .next

# Automatically leverage output traces to reduce image size
# https://nextjs.org/docs/advanced-features/output-file-tracing
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/standalone ./
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/static ./.next/static

# nextjs:nodejs, created above. Numeric so it also resolves without the image's /etc/passwd (hadolint DL3066).
USER 1001:1001

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
