# NFS Volume Test Application for Tanzu Application Service

A PHP application to test NFSv3 volume services in Tanzu Application Service (TAS) foundations.

## Features

- Test NFS volume mounting and access
- Write and read files from shared NFS storage
- Multi-instance testing to verify volume sharing
- REST API endpoints for volume operations

## Prerequisites

- Cloud Foundry CLI installed
- Access to a Tanzu Application Service environment with NFS volume services enabled
- An NFS server with an exported share
- LDAP server configured for NFS user authentication (if using LDAP mode)

## Deployment Steps

### 1. Create an org and space for testing
```bash
# Create the org and space
cf create-org nfs-org
cf create-space nfs-space -o nfs-org

# Target the org and space
cf target -o nfs-org -s nfs-space
```

### 2. Create the NFS volume service instance
```bash
# Set your NFS server details
NFS_SERVER="192.168.50.224"
EXPORT_PATH="/srv/nfs/tas-volumes"
NFS_USER_PASSWORD="<password>"

# Create the service instance - password in the clear for this simple example,
# make sure to abstract in formal environments
cat > /tmp/nfs-config.json <<EOF
{
  "share": "${NFS_SERVER}${EXPORT_PATH}",
  "mount": "/var/vcap/data/nfs-test",
  "username": "dev1",
  "password": "${NFS_USER_PASSWORD}"
}
EOF
cf create-service nfs Existing nfs-volume -c /tmp/nfs-config.json 
```

Replace the `NFS_SERVER` and `EXPORT_PATH` variables with your actual NFS server IP/hostname and export path.

### 3. Wait for service to be ready
```bash
cf services
```

### 4. Push the application
```bash
cf push
```

The app will automatically bind to the service due to the manifest configuration.

### 5. Check the app status
```bash
cf app nfs-volume-test
```

## Testing the Application

### Get the application URL
```bash
APP_URL=$(cf app nfs-volume-test | grep routes | awk '{print $2}')
```

### Available Endpoints

- `GET /` - Check application and volume status
- `POST /write` - Write data to the volume
- `GET /read` - Read data from the volume
- `GET /files` - List all files in the volume

### Test Commands
```bash
# Check status
curl https://$APP_URL/

# Write data
curl -X POST https://$APP_URL/write \
  -H "Content-Type: application/json" \
  -d '{"message": "Test from first request"}'

# Read data
curl https://$APP_URL/read

# List files
curl https://$APP_URL/files

# Check the mounted fs in the container
cf ssh nfs-volume-test -c "ls -ltra /var/vcap/data/nfs-test"
```

### Multi-Instance Testing
```bash
# Scale up to test multi-instance sharing
cf scale nfs-volume-test -i 3

# Get the app GUID
APP_GUID=$(cf app nfs-volume-test --guid)

# Write from different instances
for i in {0..2}; do
  echo "--- Writing to instance $i ---"
  curl -X POST https://$APP_URL/write \
    -H "Content-Type: application/json" \
    -H "X-CF-APP-INSTANCE: $APP_GUID:$i" \
    -d "{\"message\": \"Test from instance $i\"}"
  echo ""
done

# Read from any instance - should see all writes
curl https://$APP_URL/read
```

## Troubleshooting

This section provides systematic steps to diagnose NFS volume service issues, particularly when LDAP authentication is configured.

### Step 0: Set Up Environment Variables

First, set up the deployment and LDAP configuration variables for use in subsequent commands:

```bash
# Get the TAS deployment name
DEPLOYMENT=$(bosh deployments --column=name | awk '/^cf-/ {print $1; exit}')

# Extract LDAP configuration from the deployment manifest
eval $(bosh -d $DEPLOYMENT manifest --json | jq -r '.Blocks[0]' | python3 -c 'import sys, yaml, json; print(json.dumps(yaml.safe_load(sys.stdin)))' | jq -r '.instance_groups[].jobs[] | select(.name == "nfsv3driver") | .properties.nfsv3driver | "LDAP_HOST=\(.ldap_host)\nLDAP_PORT=\(.ldap_port)\nLDAP_SVC_USER=\(.ldap_svc_user)\nLDAP_USER_FQDN=\(.ldap_user_fqdn)"')

# Extract the LDAP CA certificate
LDAP_CA_CERT=$(bosh -d $DEPLOYMENT manifest --json | jq -r '.Blocks[0]' | python3 -c 'import sys, yaml, json; print(json.dumps(yaml.safe_load(sys.stdin)))' | jq -r '.instance_groups[].jobs[] | select(.name == "nfsv3driver") | .properties.nfsv3driver.ldap_ca_cert')

# Verify the variables are set
echo "Deployment: $DEPLOYMENT"
echo "LDAP Host: $LDAP_HOST"
echo "LDAP Port: $LDAP_PORT"
echo "Bind DN: $LDAP_SVC_USER"
echo "User Base: $LDAP_USER_FQDN"
echo -e "LDAP Cert: \n$LDAP_CA_CERT"
```

Get the LDAP service account password from CredHub or Ops Manager:

```bash
LDAP_SVC_PASSWORD=$(credhub get -n /opsmgr/$DEPLOYMENT/nfs_volume_driver/enable/ldap_service_account_password --output-json | jq -r '.value.value')
```

### Step 1: Check Application Logs for Error Messages

```bash
cf logs nfs-volume-test --recent | grep -i -E "(error|fail|denied|mount|ldap)"
```

Common error messages and their meaning:

| Error Message | Likely Cause |
|---------------|--------------|
| `User does not exist` | LDAP lookup failing - user missing `objectClass: User` or not found |
| `LDAP server could not be reached` | Network/firewall blocking Diego cells from LDAP server |
| `Authentication failed` | Wrong bind credentials or user password |
| `Permission denied` (on write) | NFS export permissions or UID/GID mapping issue |
| `mount.nfs: access denied` | NFS server not exporting to Diego cell IPs |

### Step 2: Check nfsv3driver Logs on Diego Cell

For more detailed error information:

```bash
bosh -d $DEPLOYMENT ssh diego_cell/0 -c "sudo cat /var/vcap/sys/log/nfsv3driver/nfsv3driver.stdout.log | tail -100"
```

Look for entries with `"level":"error"` that show the specific failure reason.

### Step 3: Test Network Connectivity to LDAP Server

Verify Diego cells can reach the LDAP server:

```bash
bosh -d $DEPLOYMENT ssh diego_cell/0 -c "nc -zv $LDAP_HOST $LDAP_PORT -w 5"
```

**Expected output:**
```
Connection to ldap.example.com (x.x.x.x) 636 port [tcp/ldaps] succeeded!
```

**If connection fails:**
- Check firewall rules between Diego cell subnet and LDAP server
- Verify LDAP server is listening on the configured port
- Confirm LDAP hostname resolves correctly from Diego cells

### Step 4: Test TLS/SSL Handshake

Verify the TLS certificate is being presented:

```bash
bosh -d $DEPLOYMENT ssh diego_cell/0 -c "echo | openssl s_client -connect $LDAP_HOST:$LDAP_PORT -showcerts 2>/dev/null | head -20"
```

**Expected output should show:**
- `CONNECTED`
- `Certificate chain`
- Certificate subject/issuer information

### Step 5: Validate CA Certificate Trust

Save the CA cert and test validation (run from Ops Manager or a jumpbox with LDAP connectivity):

```bash
# Save the CA cert
echo "$LDAP_CA_CERT" > /tmp/ldap-ca.pem

# Test TLS with CA validation
openssl s_client -connect $LDAP_HOST:$LDAP_PORT -CAfile /tmp/ldap-ca.pem </dev/null 2>&1 | grep -E "verify|error"
```

**Expected output:**
```
verify return:1
verify return:1
```

If you see `verify error`, the CA certificate in Ops Manager doesn't match the LDAP server's certificate chain.

### Step 6: Test LDAP Bind and Search

Test that the service account can bind and search for users (run from Ops Manager or jumpbox):

```bash
LDAPTLS_CACERT=/tmp/ldap-ca.pem ldapsearch -x -H ldaps://$LDAP_HOST:$LDAP_PORT \
  -D "$LDAP_SVC_USER" -w "$LDAP_SVC_PASSWORD" \
  -b "$LDAP_USER_FQDN" "(objectClass=User)" uid uidNumber gidNumber
```

**Expected output:**
```
# dev1, people, example.org
dn: uid=dev1,ou=people,dc=example,dc=org
uid: dev1
uidNumber: 20003
gidNumber: 10001

# search result
result: 0 Success
```

**If no results returned:**
- Users may be missing `objectClass: User` (see "LDAP Schema Requirements" below)
- Check `LDAP_USER_FQDN` points to correct OU

**If bind fails:**
- Verify `LDAP_SVC_USER` DN is correct
- Verify `LDAP_SVC_PASSWORD` is correct
- Check service account exists in LDAP

### Step 7: Test Specific User Lookup

Test the exact LDAP filter the nfsv3driver uses:

```bash
# Replace 'dev1' with the username from your service binding
LDAPTLS_CACERT=/tmp/ldap-ca.pem ldapsearch -x -H ldaps://$LDAP_HOST:$LDAP_PORT \
  -D "$LDAP_SVC_USER" -w "$LDAP_SVC_PASSWORD" \
  -b "$LDAP_USER_FQDN" "(&(objectClass=User)(cn=dev1))" dn uidNumber gidNumber
```

This must return the user with `uidNumber` and `gidNumber` for NFS mounting to work.


### Step 8: Verify NFS Server Connectivity

Ensure the NFS server is accessible from Diego cells:

```bash
# Set your NFS server IP from the service configuration
NFS_SERVER="192.168.50.224"

# Test NFS port connectivity from Diego cell
bosh -d $DEPLOYMENT ssh diego_cell/0 -c "nc -zv $NFS_SERVER 2049 -w 5"
```

Use `rpcinfo` to verify NFS services:
```bash
rpcinfo -p $NFS_SERVER | grep nfs
```

### Step 9: Check Volume Mount Path in Application

If LDAP is working but the app can't find the mount, verify the mount path:

Update your app's manifest to diagnose, add this to the manifest.yml:

```yaml
  health-check-type: process
  command: |
    echo "=== NFS Volume Diagnostic ==="
    echo "--- All mounts ---"
    mount
    echo "--- /var/vcap/data contents ---"
    ls -la /var/vcap/data/
    echo "--- VCAP_SERVICES ---"
    echo $VCAP_SERVICES
    sleep infinity
```

Then look at logs for the output of the command included in the manifest:

```bash
cf logs nfs-volume-test --recent
````

### Step 10: Check NFS Mounts on Diego Cells

To verify that the NFS volume is properly mounted on the Diego cells where your app is running:

```bash
# Set your NFS server IP
NFS_SERVER="192.168.50.224"

# Get unique Diego cell IDs where the app is running
CELL_IDS=$(cf logs <app-name> --recent | grep "creating container for instance" | grep -oP 'Cell \K[a-f0-9-]+' | uniq)

# Check NFS mounts on each Diego cell where the app is deployed
for CELL_ID in $CELL_IDS; do
  DIEGO_INSTANCE="diego_cell/$CELL_ID"
  echo "Checking $DIEGO_INSTANCE:"
  bosh -d $DEPLOYMENT ssh $DIEGO_INSTANCE -c "mount | grep \"$NFS_SERVER\"" 2>/dev/null | grep "stdout" | awk -F'|' '{print $2}'
done
```

This shows mount points from your NFS server across the Diego cells where app instances are running, confirming the volume service is working correctly.

## LDAP Schema Requirements

The TAS nfsv3driver uses a hardcoded LDAP filter: `(&(objectClass=User)(cn=<username>))`

This `User` objectClass is an Active Directory convention. For OpenLDAP compatibility, you must:

1. **Add a custom schema** defining `User` as an AUXILIARY objectClass
2. **Add `objectClass: User`** to all users that need NFS access
3. **Ensure users have `uidNumber` and `gidNumber`** attributes

Example OpenLDAP schema (`nfs-user.schema`):
```
objectclass ( 1.3.6.1.4.1.99999.1.1
    NAME 'User'
    DESC 'TAS NFS Volume Services User compatibility class'
    SUP top
    AUXILIARY )
```

Example user entry:
```ldif
dn: uid=dev1,ou=people,dc=example,dc=org
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: User
uid: dev1
cn: dev1
uidNumber: 20003
gidNumber: 10001
homeDirectory: /home/dev1
...
```

## Troubleshooting Quick Reference

| Symptom | Check | Solution |
|---------|-------|----------|
| "LDAP server could not be reached" | Step 3: Network connectivity | Open firewall from Diego cells to LDAP |
| "User does not exist" | Step 7: User lookup | Add `objectClass: User` to LDAP user |
| "Authentication failed" | Step 6: LDAP bind | Verify bind DN and password in Ops Manager |
| TLS/certificate errors | Step 5: CA validation | Update CA cert in Ops Manager NFS config |
| Mount succeeds but write fails | Step 8: NFS exports | Check NFS export permissions and UID mapping |
| App can't find mount path | Step 9: Mount path | Use `/var/vcap/data/<service-name>` from VCAP_SERVICES |
| Verify mounts are working | Step 10: Diego cell mounts | Check NFS mounts visible on Diego cells |

## Additional Resources

- [TAS NFS Volume Services Documentation](https://docs.vmware.com/en/VMware-Tanzu-Application-Service/index.html)
- [Cloud Foundry Volume Services](https://docs.cloudfoundry.org/devguide/services/using-vol-services.html)