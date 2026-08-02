FROM docker.io/library/node:22.23.0-alpine3.23 AS frontend-build
WORKDIR /build
COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM docker.io/library/nginx:1.29.4-alpine3.23 AS production
COPY --from=frontend-build /build/dist /srv/frontend
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080

FROM production AS development
COPY frontend/dev/dev-tools.js /srv/frontend/dev-tools.js
RUN sed -i 's#</head>#<script type="module" src="/dev-tools.js"></script></head>#' /srv/frontend/index.html
