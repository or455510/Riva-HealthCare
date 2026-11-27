# RIVA-113 (directive §18): production image for the Angular frontend.
# This app is built with Angular SSR (@angular/ssr + express — see
# package.json's "serve:ssr:project-riva" script), so the runtime image
# runs the compiled Node SSR server, not a static file server. If the
# frontend is later reconfigured for a purely static build (no SSR), this
# Dockerfile's runtime stage would change to nginx serving dist/browser
# instead — but that's not what's actually in package.json today, and this
# Dockerfile matches the real, existing build output rather than a
# hypothetical one.

FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

FROM node:22-alpine AS runtime

LABEL org.opencontainers.image.title="riva-frontend"

WORKDIR /app

ENV NODE_ENV=production

# Only the build output + package manifests are needed at runtime — no
# source, no devDependencies, no build tooling in the final image.
COPY --from=build /app/dist ./dist
COPY --from=build /app/package.json /app/package-lock.json ./

# express (the SSR server's only real runtime dependency beyond what's
# already bundled into dist/project-riva/server) needs to be present —
# `npm ci --omit=dev` keeps this a lean production install rather than
# reusing the full build-stage node_modules.
RUN npm ci --omit=dev

RUN addgroup -g 1000 riva && adduser -G riva -u 1000 -D riva
USER riva

EXPOSE 4000
ENV PORT=4000

CMD ["node", "dist/project-riva/server/server.mjs"]
