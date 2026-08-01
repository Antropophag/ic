FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install --yes --no-install-recommends \
        krb5-user ldap-utils samba samba-ad-provision smbclient \
    && rm -rf /var/lib/apt/lists/*
COPY docker/test-ad/entrypoint.sh /usr/local/bin/test-ad-entrypoint
RUN chmod 0755 /usr/local/bin/test-ad-entrypoint
EXPOSE 53 88 135 137 138 139 389 445 464 636 3268 3269
ENTRYPOINT ["test-ad-entrypoint"]
