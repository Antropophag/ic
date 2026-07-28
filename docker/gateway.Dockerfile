FROM node:22.23.0-alpine3.23 AS frontend
WORKDIR /build
COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.29.4-alpine3.23
COPY --from=frontend /build/dist /srv/frontend
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080
