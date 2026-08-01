#!/bin/sh
set -eu

realm=IC.TEST
domain=IC
admin_password='TestPassword1!'
state=/var/lib/samba/private/sam.ldb

if [ ! -f "$state" ]; then
  rm -f /etc/samba/smb.conf
  samba-tool domain provision --server-role=dc --use-rfc2307 \
    --realm="$realm" --domain="$domain" --adminpass="$admin_password" \
    --dns-backend=SAMBA_INTERNAL
  cp /etc/samba/smb.conf /var/lib/samba/test-smb.conf
  cp /var/lib/samba/private/krb5.conf /var/lib/samba/test-krb5.conf
fi

test -f /etc/samba/smb.conf || cp /var/lib/samba/test-smb.conf /etc/samba/smb.conf
test -f /etc/krb5.conf || cp /var/lib/samba/test-krb5.conf /etc/krb5.conf

samba --foreground --no-process-group &
samba_pid=$!
trap 'kill -TERM "$samba_pid"; wait "$samba_pid"' TERM INT

attempt=0
until samba-tool user list >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 30 ] || exit 1
  sleep 1
done

create_user() {
  login=$1
  name=$2
  if ! samba-tool user show "$login" >/dev/null 2>&1; then
    samba-tool user create "$login" 'TestPassword1!' \
      --given-name="$name" --surname=Test \
      --mail-address="$login@ic.test"
  fi
}

for pair in \
  initiator:Initiator ic_manager:ICManager laboratory_manager:LabManager \
  executor:Executor expert:Expert security_officer:Security \
  administrator:Administrator employee_without_roles:Employee disabled_user:Disabled
do
  create_user "${pair%%:*}" "${pair#*:}"
done

for group in ICManagers LaboratoryManagers Executors Experts SecurityOfficers Administrators; do
  samba-tool group show "$group" >/dev/null 2>&1 || samba-tool group add "$group"
done
samba-tool group addmembers ICManagers ic_manager >/dev/null 2>&1 || true
samba-tool group addmembers LaboratoryManagers laboratory_manager >/dev/null 2>&1 || true
samba-tool group addmembers Executors executor >/dev/null 2>&1 || true
samba-tool group addmembers Experts expert >/dev/null 2>&1 || true
samba-tool group addmembers SecurityOfficers security_officer >/dev/null 2>&1 || true
samba-tool group addmembers Administrators administrator >/dev/null 2>&1 || true
samba-tool user disable disabled_user >/dev/null 2>&1 || true

wait "$samba_pid"
