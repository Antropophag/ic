FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
# Test fixture image follows the pinned Debian base repository.
# hadolint ignore=DL3008
RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install --yes --no-install-recommends \
        krb5-user ldap-utils samba samba-ad-provision samba-dsdb-modules samba-vfs-modules smbclient \
    && rm -rf /var/lib/apt/lists/*
# Test fixture image follows the pinned Debian base repository.
# hadolint ignore=DL3008
RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install --yes --no-install-recommends winbind \
    && rm -rf /var/lib/apt/lists/*
COPY docker/test-ad/entrypoint.sh /usr/local/bin/test-ad-entrypoint
RUN chmod 0755 /usr/local/bin/test-ad-entrypoint
EXPOSE 53 88 135 137 138 139 389 445 464 636 3268 3269
ENTRYPOINT ["test-ad-entrypoint"]
