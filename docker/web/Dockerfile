FROM node:22-alpine AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ .
ARG VITE_SUPPORT_URL=
ENV VITE_SUPPORT_URL=$VITE_SUPPORT_URL
RUN node --check public/vendor/telegram-web-app.js \
    && node --check public/vendor/telegram-widget.js \
    && npm run build \
    && cmp public/vendor/telegram-web-app.js dist/vendor/telegram-web-app.js \
    && cmp public/vendor/telegram-widget.js dist/vendor/telegram-widget.js

FROM nginx:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
