FROM docker.io/library/node:22.23.0-alpine3.23 AS frontend-source
WORKDIR /build
COPY frontend/package*.json frontend/.npmrc ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./

FROM frontend-source AS frontend-production-build
RUN npm run build

FROM frontend-source AS frontend-development-build
RUN npm run build -- --mode development

FROM docker.io/library/nginx:1.29.4-alpine3.23 AS frontend-runtime
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080

FROM frontend-runtime AS production
COPY --from=frontend-production-build /build/dist /srv/frontend
COPY --from=frontend-source /build/node_modules/swagger-ui-dist/swagger-ui.css /srv/frontend/api/docs/swagger-ui.css
COPY --from=frontend-source /build/node_modules/swagger-ui-dist/swagger-ui-bundle.js /srv/frontend/api/docs/swagger-ui-bundle.js
COPY openapi/swagger-ui/index.html /srv/frontend/api/docs/index.html
COPY openapi/openapi.yaml /srv/frontend/api/openapi.yaml
RUN grep -R -E -q 'X-Dev-User-ID|/api/v1/dev/users' /srv/frontend; status=$?; \
    if [ "$status" -eq 0 ]; then \
        echo 'Development identity code found in production frontend' >&2; \
        exit 1; \
    fi; \
    if [ "$status" -ne 1 ]; then \
        echo "Failed to inspect production frontend (grep status $status)" >&2; \
        exit "$status"; \
    fi

FROM frontend-runtime AS development
COPY --from=frontend-development-build /build/dist /srv/frontend
COPY --from=frontend-source /build/node_modules/swagger-ui-dist/swagger-ui.css /srv/frontend/api/docs/swagger-ui.css
COPY --from=frontend-source /build/node_modules/swagger-ui-dist/swagger-ui-bundle.js /srv/frontend/api/docs/swagger-ui-bundle.js
COPY openapi/swagger-ui/index.html /srv/frontend/api/docs/index.html
COPY openapi/openapi.yaml /srv/frontend/api/openapi.yaml
RUN grep -R -q 'X-Dev-User-ID' /srv/frontend/assets \
    && grep -R -q '/api/v1/dev/users' /srv/frontend/assets
