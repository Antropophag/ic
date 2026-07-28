FROM node:22.23.0-alpine3.23 AS frontend
WORKDIR /build
ARG VITE_DEV_USER_ID
ENV VITE_DEV_USER_ID=${VITE_DEV_USER_ID}
COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.29.4-alpine3.23
COPY --from=frontend /build/dist /srv/frontend
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080
